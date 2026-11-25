#!/usr/bin/env php
<?php
/**
 * Durable fetch-state tests.
 *
 * @package WPSD_Feed
 */

$tmp = sys_get_temp_dir() . '/wpdiag-state-test-' . getmypid();
putenv( 'WPDIAG_LOG_DIR=' . $tmp . '/logs' );
putenv( 'WPDIAG_STATE_DIR=' . $tmp . '/state' );
putenv( 'WPDIAG_WORK_DIR=' . $tmp . '/work' );
putenv( 'WPDIAG_REFRESH_INTERVAL_SECONDS=21600' );
putenv( 'WPDIAG_LIVE_FORCE_MIN_INTERVAL_SECONDS=1800' );

require dirname( __DIR__ ) . '/lib/bootstrap.php';

$failures = 0;
$path     = $tmp . '/state/fetch-state.json';
$legacy   = $tmp . '/legacy-last-refresh.txt';
$state    = new Fetch_State( $path, $legacy );

/**
 * Assert helper.
 *
 * @param string $name Condition name.
 * @param bool   $ok   Result.
 * @return void
 */
function state_assert( string $name, bool $ok ): void {
	global $failures;
	if ( ! $ok ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$name}\n" );
	} else {
		echo "ok: {$name}\n";
	}
}

$now = time();
$st  = $state->read();
state_assert( 'defaults: never fetched', 0 === $st['last_attempt_at'] && 'never' === $st['last_status'] );

$st = $state->mark_started( $st, $now );
state_assert( 'started: attempt persisted', $now === $st['last_attempt_at'] && 'in_progress' === $st['last_status'] );
state_assert( 'started: six-hour guard', $now + 21600 === $st['next_allowed_at'] );

$check = $state->check( $now + 3600, false );
state_assert( 'interval: blocked after one hour', ! $check['allowed'] && 'interval' === $check['reason'] );

$check = $state->check( $now + 21601, false );
state_assert( 'interval: allowed after six hours', $check['allowed'] && 'due' === $check['reason'] );

$restarted = new Fetch_State( $path, $legacy );
$check     = $restarted->check( $now + 3600, false );
state_assert( 'restart: persisted state blocks a new process', ! $check['allowed'] && 'interval' === $check['reason'] );

$check = $restarted->check( $now + 60, true );
state_assert( 'force: hard floor blocks an immediate retry', ! $check['allowed'] && 'hard_limit' === $check['reason'] );

$check = $restarted->check( $now + 1800, true );
state_assert( 'force: allowed after hard floor', $check['allowed'] && 'forced' === $check['reason'] );

$st = $restarted->mark_failure( $st, $now + 10, 'rate_limited', 'HTTP 429', 429, 30000 );
state_assert( '429: retry-after extends the guard', $now + 30010 === $st['next_allowed_at'] );
state_assert( '429: status recorded', 'rate_limited' === $st['last_status'] && 429 === $st['last_http_status'] );
state_assert( '429: backoff recorded', $now + 30010 === $st['rate_limited_until'] );

$check = $restarted->check( $now + 1800, true );
state_assert( '429: force cannot bypass backoff', ! $check['allowed'] && 'rate_limited' === $check['reason'] );

$check = $restarted->check( $now + 30011, true );
state_assert( '429: allowed after backoff', $check['allowed'] && 'forced' === $check['reason'] );

$threw = false;
try {
	$restarted->mark_started( $st, $now + 1800 );
} catch ( RuntimeException $e ) {
	$threw = true;
}
state_assert( '429: direct start refused during backoff', $threw );

$st = $restarted->mark_success( $st, $now + 20, '2026.01.01.0001', 200 );
state_assert( 'success: success timestamp recorded', $now + 20 === $st['last_success_at'] && 'ok' === $st['last_status'] );
state_assert( 'success: pack version recorded', '2026.01.01.0001' === $st['pack_version'] );
state_assert( 'success: guard is not shortened', $now + 30010 === $st['next_allowed_at'] );
state_assert( 'success: backoff cleared', 0 === $st['rate_limited_until'] );

file_put_contents( $path, 'not-json' );
$corrupt = $restarted->read();
state_assert( 'corrupt: mtime used as conservative attempt', $corrupt['last_attempt_at'] > 0 && 'unknown' === $corrupt['last_status'] );

@unlink( $path );
file_put_contents( $legacy, (string) ( $now - 1000 ) );
$migrated = $restarted->read();
state_assert( 'legacy: timestamp migrated', ( $now - 1000 ) === $migrated['last_attempt_at'] && 'ok' === $migrated['last_status'] );
state_assert( 'legacy: state file written', is_file( $path ) );
state_assert( 'legacy: source removed', ! is_file( $legacy ) );

/**
 * Recursively remove a temp directory.
 *
 * @param string $dir Directory.
 * @return void
 */
function state_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			state_rrmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

state_rrmdir( $tmp );

if ( $failures > 0 ) {
	fwrite( STDERR, "Fetch state tests: FAIL ({$failures})\n" );
	exit( 1 );
}
echo "Fetch state tests: PASS\n";
