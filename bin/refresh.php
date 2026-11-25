#!/usr/bin/env php
<?php
/**
 * Feed refresh workflow.
 *
 * Usage: php bin/refresh.php [--force] [--file=<fixture>] [--version=YYYY.MM.DD.HHMM] [--status] [--preflight] [--skip-guard]
 *
 * Live fetches are guarded by a durable six-hour window. The guard is written
 * before the upstream request so a crash or container restart cannot trigger
 * a duplicate request.
 *
 * --preflight checks the guard and persists the attempt without fetching.
 * --skip-guard resumes a preflight attempt (GitHub Actions commits the
 * preflight state before calling it).
 *
 * @package WPSD_Feed
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

$force            = in_array( '--force', $argv, true );
$show_status      = in_array( '--status', $argv, true );
$preflight        = in_array( '--preflight', $argv, true );
$skip_guard       = in_array( '--skip-guard', $argv, true );
$fixture          = '';
$version_override = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--file=' ) ) {
		$fixture = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--version=' ) ) {
		$version_override = substr( $arg, 10 );
	}
}

$state = new Fetch_State();

if ( $preflight && $skip_guard ) {
	fwrite( STDERR, "--preflight and --skip-guard are mutually exclusive.\n" );
	exit( 4 );
}
if ( $preflight && $show_status ) {
	fwrite( STDERR, "--preflight and --status are mutually exclusive.\n" );
	exit( 4 );
}
if ( $skip_guard && '' !== $fixture ) {
	fwrite( STDERR, "--skip-guard is only valid for live fetches.\n" );
	exit( 4 );
}

if ( $preflight ) {
	$secret = (string) Config::get( 'signing_secret_key_path' );
	if ( ! is_file( $secret ) ) {
		fwrite( STDERR, "Signing key not found. Run bin/gen-keypair.php first.\n" );
		exit( 4 );
	}
	if ( '' === (string) Config::get( 'wordfence_api_key' ) ) {
		Logger::log( 'config_error', 'No Wordfence API key configured.' );
		fwrite( STDERR, "No Wordfence API key configured.\n" );
		exit( 4 );
	}
	$now   = time();
	$check = $state->check( $now, false );
	if ( ! $check['allowed'] ) {
		$out                  = $check['state'];
		$out['now']           = gmdate( 'c', $check['now'] );
		$out['fetch_allowed'] = false;
		$out['reason']        = $check['reason'];
		Logger::log( 'skipped', 'reason=' . $check['reason'] . ' last_attempt_at=' . (string) ( $check['state']['last_attempt_iso'] ?? 'never' ) . ' next_allowed_at=' . (string) ( $check['state']['next_allowed_iso'] ?? 'none' ) );
		echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
		exit( 3 );
	}
	try {
		$started = $state->mark_started( $check['state'], $now );
	} catch ( Throwable $e ) {
		Logger::log( 'state_write_failed', $e->getMessage() );
		fwrite( STDERR, 'Could not persist preflight state: ' . $e->getMessage() . "\n" );
		exit( 4 );
	}
	$started['now']           = gmdate( 'c', $now );
	$started['fetch_allowed'] = true;
	$started['reason']        = $check['reason'];
	Logger::log( 'preflight', 'last_query_at=' . gmdate( 'c', $now ) . ' next_allowed_at=' . (string) ( $started['next_allowed_iso'] ?? 'unknown' ) );
	echo json_encode( $started, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
	exit( 0 );
}

if ( $show_status ) {
	$check = $state->check( time(), false );
	$out   = $check['state'];
	$out['now']           = gmdate( 'c', $check['now'] );
	$out['fetch_allowed'] = $check['allowed'];
	$out['reason']        = $check['reason'];
	echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
	exit( 0 );
}

$secret = (string) Config::get( 'signing_secret_key_path' );
if ( ! is_file( $secret ) ) {
	fwrite( STDERR, "Signing key not found. Run bin/gen-keypair.php first.\n" );
	exit( 4 );
}

$state_dir = rtrim( (string) Config::get( 'state_dir' ), '/' );
if ( ! is_dir( $state_dir ) && ! @mkdir( $state_dir, 0750, true ) && ! is_dir( $state_dir ) ) {
	fwrite( STDERR, "State directory is not writable: {$state_dir}\n" );
	exit( 4 );
}

$lock = fopen( $state_dir . '/refresh.lock', 'c' );
if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
	Logger::log( 'locked', 'Another refresh is running.' );
	fwrite( STDERR, "Another refresh is already running.\n" );
	exit( 3 );
}

$exit_code = 0;
try {
	$exit_code = wpsd_run_refresh( $force, $fixture, $version_override, $skip_guard, $state );
} catch ( Throwable $e ) {
	Logger::log( 'failed', $e->getMessage() );
	fwrite( STDERR, 'Refresh failed: ' . $e->getMessage() . "\n" );
	$exit_code = 2;
} finally {
	flock( $lock, LOCK_UN );
	fclose( $lock );
}

exit( $exit_code );

/**
 * Run one refresh attempt.
 *
 * @param bool       $force            Bypass the scheduled interval (live only).
 * @param string     $fixture          Fixture path, or empty for live.
 * @param string     $version_override Explicit version (fixture only).
 * @param bool       $skip_guard       Resume a preflight-persisted attempt.
 * @param Fetch_State $state           Durable state.
 * @return int Exit code.
 */
function wpsd_run_refresh( bool $force, string $fixture, string $version_override, bool $skip_guard, Fetch_State $state ): int {
	$is_fixture = '' !== $fixture;
	$now        = time();
	$interval   = max( 60, (int) Config::get( 'refresh_interval_seconds', 21600 ) );

	if ( '' !== $version_override && ( ! $is_fixture || ! preg_match( '/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $version_override ) ) ) {
		Logger::log( 'failed', 'Version override rejected.' );
		fwrite( STDERR, "--version is only allowed with --file and must match YYYY.MM.DD.HHMM.\n" );
		return 4;
	}

	$started = array();
	if ( $is_fixture ) {
		Logger::log( 'started', 'fixture:' . $fixture );
	} else {
		if ( '' === (string) Config::get( 'wordfence_api_key' ) ) {
			Logger::log( 'config_error', 'No Wordfence API key configured.' );
			fwrite( STDERR, "No Wordfence API key configured.\n" );
			return 4;
		}
		if ( $skip_guard ) {
			$started = $state->read();
			$age     = $now - (int) ( $started['last_attempt_at'] ?? 0 );
			if ( 'in_progress' !== (string) ( $started['last_status'] ?? '' ) || $age < 0 || $age > 900 ) {
				Logger::log( 'preflight_missing', 'No recent preflight state; refusing to fetch.' );
				fwrite( STDERR, "No recent preflight state; refusing to fetch. Run --preflight first.\n" );
				return 4;
			}
			Logger::log( 'fetch_started', 'last_query_at=' . (string) ( $started['last_attempt_iso'] ?? '' ) . ' interval=' . $interval . ' reason=preflight' );
		} else {
			$check = $state->check( $now, $force );
			if ( ! $check['allowed'] ) {
				$last = (string) ( $check['state']['last_attempt_iso'] ?? '' );
				$next = (string) ( $check['state']['next_allowed_iso'] ?? '' );
				Logger::log( 'skipped', 'reason=' . $check['reason'] . ' last_attempt_at=' . ( '' !== $last ? $last : 'never' ) . ' next_allowed_at=' . ( '' !== $next ? $next : 'none' ) );
				fwrite( STDERR, 'Refresh skipped (' . $check['reason'] . '); next allowed at ' . ( '' !== $next ? $next : 'unknown' ) . ".\n" );
				return 3;
			}
			try {
				$started = $state->mark_started( $check['state'], $now );
			} catch ( Throwable $e ) {
				Logger::log( 'state_write_failed', $e->getMessage() );
				fwrite( STDERR, 'Could not persist fetch state; refusing to call the API: ' . $e->getMessage() . "\n" );
				return 4;
			}
			Logger::log( 'fetch_started', 'last_query_at=' . gmdate( 'c', $now ) . ' interval=' . $interval . ' reason=' . $check['reason'] );
		}
	}

	$client        = new Wordfence_Client();
	$fetch_started = microtime( true );
	try {
		$records = $is_fixture ? $client->fetch_fixture( $fixture ) : $client->fetch();
	} catch ( Wordfence_Rate_Limit_Exception $e ) {
		if ( ! $is_fixture ) {
			wpsd_mark_failure( $state, $started, time(), 'rate_limited', $e->getMessage(), $e->http_status, $e->retry_after );
		}
		$next = wpsd_state_next_allowed( $state );
		Logger::log( 'rate_limited', 'retry_after=' . $e->retry_after . ' next_allowed_at=' . $next );
		fwrite( STDERR, 'Rate limited: ' . $e->getMessage() . ' Next attempt after ' . $next . ".\n" );
		return 2;
	} catch ( Throwable $e ) {
		if ( ! $is_fixture ) {
			$http = $e instanceof Wordfence_Client_Exception ? $e->http_status : 0;
			wpsd_mark_failure( $state, $started, time(), 'failed', $e->getMessage(), $http, 0 );
		}
		$next = $is_fixture ? '' : wpsd_state_next_allowed( $state );
		Logger::log( 'fetch_failed', $e->getMessage() . ( '' !== $next ? ' next_allowed_at=' . $next : '' ) );
		fwrite( STDERR, 'Fetch failed: ' . $e->getMessage() . "\n" );
		return 2;
	}

	$duration  = round( microtime( true ) - $fetch_started, 3 );
	$raw_count = count( $records );
	Logger::log( 'fetch_ok', 'records=' . $raw_count . ' bytes=' . $client->last_bytes . ' status=' . $client->last_status . ' duration=' . $duration );

	$normalizer = new Feed_Normalizer();
	$normalized = $normalizer->normalize( $records );
	$entries    = 0;
	foreach ( $normalized['components'] as $component ) {
		$entries += count( $component['advisories'] );
	}
	Logger::log( 'normalized', 'entries=' . $entries . ' skipped=' . (int) $normalized['skipped'] );
	if ( 0 === $entries ) {
		if ( ! $is_fixture ) {
			wpsd_mark_failure( $state, $started, time(), 'failed', 'No valid advisories.', 0, 0 );
		}
		$next = $is_fixture ? '' : ' next_allowed_at=' . wpsd_state_next_allowed( $state );
		Logger::log( 'failed', 'No valid advisories.' . $next );
		fwrite( STDERR, "Normalization produced no valid advisories.\n" );
		return 2;
	}

	$version = '' !== $version_override ? $version_override : gmdate( 'Y.m.d.Hi' );

	$store = new Pack_Store();
	if ( $store->latest_version() === $version ) {
		if ( ! $is_fixture ) {
			wpsd_mark_success( $state, $started, time(), $version, $client->last_status > 0 ? $client->last_status : 200 );
		}
		Logger::log( 'skipped', 'Version already published: ' . $version );
		return 3;
	}

	$zip_path = '';
	try {
		$builder  = new Pack_Builder();
		$zip_path = $builder->build( $normalized, $version );
		Pack_Verifier::verify( $zip_path, $store->public_key() );
		Logger::log( 'built', 'version=' . $version . ' entries=' . $entries . ' size=' . (int) @filesize( $zip_path ) );
		$store->publish( $zip_path, $version );
		if ( ! $is_fixture ) {
			wpsd_mark_success( $state, $started, time(), $version, $client->last_status > 0 ? $client->last_status : 200 );
		}
		$pruned = 0;
		try {
			$pruned = $store->prune();
		} catch ( Throwable $e ) {
			Logger::log( 'prune_failed', $e->getMessage() );
		}
		Logger::log( 'published', 'version=' . $version . ' pruned=' . $pruned );
		echo "Published pack {$version} ({$entries} advisories).\n";
		return 0;
	} catch ( Throwable $e ) {
		if ( '' !== $zip_path && is_file( $zip_path ) ) {
			@unlink( $zip_path );
		}
		if ( ! $is_fixture ) {
			wpsd_mark_failure( $state, $started, time(), 'failed', $e->getMessage(), 0, 0 );
		}
		$next = $is_fixture ? '' : ' next_allowed_at=' . wpsd_state_next_allowed( $state );
		Logger::log( 'failed', $e->getMessage() . $next );
		fwrite( STDERR, 'Build failed: ' . $e->getMessage() . "\n" );
		return 2;
	}
}

/**
 * Persist a failure without masking the original error.
 *
 * @param Fetch_State $state       State store.
 * @param array       $state_data  State snapshot.
 * @param int         $now         Current timestamp.
 * @param string      $status      Status.
 * @param string      $message     Error message.
 * @param int         $http        HTTP status.
 * @param int         $retry_after Retry-After seconds.
 * @return void
 */
function wpsd_mark_failure( Fetch_State $state, array $state_data, int $now, string $status, string $message, int $http, int $retry_after ): void {
	try {
		$state->mark_failure( $state_data, $now, $status, $message, $http, $retry_after );
	} catch ( Throwable $e ) {
		Logger::log( 'state_write_failed', $e->getMessage() );
	}
}

/**
 * Persist a success without masking the original outcome.
 *
 * @param Fetch_State $state      State store.
 * @param array       $state_data State snapshot.
 * @param int         $now        Current timestamp.
 * @param string      $version    Pack version.
 * @param int         $http       HTTP status.
 * @return void
 */
function wpsd_mark_success( Fetch_State $state, array $state_data, int $now, string $version, int $http ): void {
	try {
		$state->mark_success( $state_data, $now, $version, $http );
	} catch ( Throwable $e ) {
		Logger::log( 'state_write_failed', $e->getMessage() );
	}
}

/**
 * Current persisted next-allowed timestamp for logging.
 *
 * @param Fetch_State $state State store.
 * @return string
 */
function wpsd_state_next_allowed( Fetch_State $state ): string {
	try {
		$data = $state->read();
		return (string) ( $data['next_allowed_iso'] ?? 'unknown' );
	} catch ( Throwable $e ) {
		return 'unknown';
	}
}
