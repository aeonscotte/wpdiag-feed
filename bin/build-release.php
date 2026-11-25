#!/usr/bin/env php
<?php
/**
 * Build the static release assets from the latest published pack.
 *
 * Usage: php bin/build-release.php [--dist=dir]
 *
 * @package WPSD_Feed
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

$dist = 'dist';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--dist=' ) ) {
		$dist = substr( $arg, 7 );
	}
}
$dist = rtrim( $dist, '/' );
if ( '' === $dist ) {
	fwrite( STDERR, "Invalid dist path.\n" );
	exit( 1 );
}

$store = new Pack_Store();
$index = $store->meta();
$latest = isset( $index['latest'] ) ? (string) $index['latest'] : '';
if ( '' === $latest ) {
	fwrite( STDERR, "No published pack found.\n" );
	exit( 1 );
}
$pack = $store->pack_path( $latest );
if ( ! $pack ) {
	fwrite( STDERR, "Latest pack file is missing: {$latest}\n" );
	exit( 1 );
}

if ( ! is_dir( $dist ) && ! @mkdir( $dist, 0755, true ) && ! is_dir( $dist ) ) {
	fwrite( STDERR, "Could not create dist directory: {$dist}\n" );
	exit( 1 );
}

if ( ! copy( $pack, $dist . '/latest.wpdiag-pack' ) ) {
	fwrite( STDERR, "Could not copy latest pack.\n" );
	exit( 1 );
}
file_put_contents( $dist . '/meta.json', json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

$state       = new Fetch_State();
$check       = $state->check( time(), false );
$status      = $check['state'];
$status['latest']        = $latest;
$status['generated_at']  = gmdate( 'c' );
$status['fetch_allowed'] = $check['allowed'];
$status['reason']        = $check['reason'];
unset( $status['last_error'] );
file_put_contents( $dist . '/status.json', json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

$advisory_count = 0;
foreach ( (array) ( $index['packs'] ?? array() ) as $entry ) {
	if ( isset( $entry['version'] ) && (string) $entry['version'] === $latest ) {
		$advisory_count = (int) ( $entry['advisory_count'] ?? 0 );
		break;
	}
}
$status_label = htmlspecialchars( (string) ( $status['last_status'] ?? 'never' ), ENT_QUOTES, 'UTF-8' );
$latest_html  = htmlspecialchars( $latest, ENT_QUOTES, 'UTF-8' );
$html         = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>WP Security Diagnostic feed</title>'
	. '<style>body{font-family:system-ui,sans-serif;max-width:46rem;margin:3rem auto;padding:0 1rem;line-height:1.5}code{background:#f2f2f2;padding:.15rem .35rem}</style>'
	. '</head><body><h1>WP Security Diagnostic feed</h1>'
	. '<p>Latest pack: <code>' . $latest_html . '</code></p>'
	. '<p>Advisories: ' . (int) $advisory_count . '</p>'
	. '<p>Last fetch status: <code>' . $status_label . '</code></p>'
	. '<p><a href="latest.wpdiag-pack">Download latest signed pack</a> · <a href="meta.json">meta.json</a> · <a href="status.json">status.json</a></p>'
	. '</body></html>';
file_put_contents( $dist . '/index.html', $html );

echo "Release assets written to {$dist} (pack {$latest}, {$advisory_count} advisories).\n";
