#!/usr/bin/env php
<?php
/**
 * Feed refresh workflow.
 *
 * Usage: php bin/refresh.php [--force] [--file=<fixture>] [--version=YYYY.MM.DD.HHMM]
 *
 * @package WPSD_Feed
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

$force   = in_array( '--force', $argv, true );
$fixture = '';
$version_override = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--file=' ) ) {
		$fixture = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--version=' ) ) {
		$version_override = substr( $arg, 10 );
	}
}

$secret = (string) Config::get( 'signing_secret_key_path' );
if ( ! is_file( $secret ) ) {
	fwrite( STDERR, "Signing key not found. Run bin/gen-keypair.php first.\n" );
	exit( 4 );
}

$work_dir = rtrim( (string) Config::get( 'work_dir' ), '/' );
$last_file = $work_dir . '/last-refresh.txt';
$interval  = (int) Config::get( 'refresh_interval_seconds', 3600 );

if ( ! $force && is_file( $last_file ) ) {
	$last = (int) file_get_contents( $last_file );
	if ( $last > 0 && ( time() - $last ) < $interval ) {
		Logger::log( 'skipped', 'Refresh interval not elapsed.' );
		exit( 3 );
	}
}

$lock = fopen( $work_dir . '/refresh.lock', 'c' );
if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
	Logger::log( 'locked', 'Another refresh is running.' );
	exit( 3 );
}

Logger::log( 'started', $fixture ? 'fixture:' . $fixture : 'live' );
$client = new Wordfence_Client();
try {
	$records = $fixture ? $client->fetch_fixture( $fixture ) : $client->fetch();
} catch ( Throwable $e ) {
	Logger::log( 'fetch_failed', $e->getMessage() );
	fwrite( STDERR, 'Fetch failed: ' . $e->getMessage() . "\n" );
	flock( $lock, LOCK_UN );
	exit( 2 );
}
$raw_count = count( $records );
Logger::log( 'fetch_ok', 'records=' . $raw_count );

$normalizer = new Feed_Normalizer();
$normalized = $normalizer->normalize( $records );
$entries    = 0;
foreach ( $normalized['components'] as $component ) {
	$entries += count( $component['advisories'] );
}
Logger::log( 'normalized', 'entries=' . $entries . ' skipped=' . (int) $normalized['skipped'] );
if ( 0 === $entries ) {
	Logger::log( 'failed', 'No valid advisories.' );
	fwrite( STDERR, "Normalization produced no valid advisories.\n" );
	flock( $lock, LOCK_UN );
	exit( 2 );
}

if ( '' !== $version_override ) {
	if ( '' === $fixture || ! preg_match( '/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $version_override ) ) {
		Logger::log( 'failed', 'Version override rejected.' );
		fwrite( STDERR, "--version is only allowed with --file and must match YYYY.MM.DD.HHMM.\n" );
		flock( $lock, LOCK_UN );
		exit( 4 );
	}
	$version = $version_override;
} else {
	$version = gmdate( 'Y.m.d.Hi' );
}

$store = new Pack_Store();
if ( $store->latest_version() === $version ) {
	Logger::log( 'skipped', 'Version already published.' );
	flock( $lock, LOCK_UN );
	exit( 3 );
}

$builder = new Pack_Builder();
try {
	$zip_path = $builder->build( $normalized, $version );
	Pack_Verifier::verify( $zip_path, $store->public_key() );
	Logger::log( 'built', 'version=' . $version . ' entries=' . $entries );
	$store->publish( $zip_path, $version );
	$pruned = $store->prune();
	Logger::log( 'published', 'version=' . $version . ' pruned=' . $pruned );
	file_put_contents( $last_file, (string) time() );
	flock( $lock, LOCK_UN );
	echo "Published pack {$version} ({$entries} advisories).\n";
	exit( 0 );
} catch ( Throwable $e ) {
	Logger::log( 'failed', $e->getMessage() );
	if ( isset( $zip_path ) && is_file( $zip_path ) ) {
		unlink( $zip_path );
	}
	fwrite( STDERR, 'Build failed: ' . $e->getMessage() . "\n" );
	flock( $lock, LOCK_UN );
	exit( 2 );
}
