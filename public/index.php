<?php
/**
 * Feed server public entry point.
 *
 * @package WPSD_Feed
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

$store = new Pack_Store();
$path  = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$path  = is_string( $path ) ? $path : '/';

header( 'X-Content-Type-Options: nosniff' );

if ( '/' === $path ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
	$latest = $store->latest_version();
	echo "WP Security Diagnostic — vulnerability data pack service\n";
	echo 'Latest pack: ' . ( $latest ? $latest : 'none' ) . "\n";
	exit;
}
if ( '/healthz' === $path ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "ok\n";
	exit;
}
if ( '/status.json' === $path ) {
	$state = new Fetch_State();
	$check = $state->check( time(), false );
	$data  = $check['state'];
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo json_encode(
		array(
			'now'              => gmdate( 'c', $check['now'] ),
			'last_attempt_at'  => $data['last_attempt_iso'],
			'last_success_at'  => $data['last_success_iso'],
			'last_status'      => $data['last_status'],
			'last_http_status' => $data['last_http_status'],
			'pack_version'     => $data['pack_version'],
			'next_allowed_at'  => $data['next_allowed_iso'],
			'rate_limited_until' => $data['rate_limited_until_iso'],
			'fetch_allowed'    => $check['allowed'],
			'reason'           => $check['reason'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
	exit;
}
if ( '/meta.json' === $path ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $store->meta(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	exit;
}
if ( '/latest' === $path ) {
	$pack = $store->latest_pack_path();
	if ( ! $pack ) {
		http_response_code( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "No pack available.\n";
		exit;
	}
	serve_pack( $pack, $store->latest_version() );
}
if ( preg_match( '#^/v/(\d{4}\.\d{2}\.\d{2}\.\d{4})$#', $path, $matches ) ) {
	$pack = $store->pack_path( $matches[1] );
	if ( ! $pack ) {
		http_response_code( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "Unknown pack version.\n";
		exit;
	}
	serve_pack( $pack, $matches[1] );
}
http_response_code( 404 );
header( 'Content-Type: text/plain; charset=utf-8' );
echo "Not found.\n";

/**
 * Stream a pack file.
 *
 * @param string $pack    Pack path.
 * @param string $version Version.
 */
function serve_pack( string $pack, string $version ): void {
	header( 'Content-Type: application/zip' );
	header( 'Content-Length: ' . (string) filesize( $pack ) );
	header( 'Content-Disposition: attachment; filename="pack-' . $version . '.wpdiag-pack"' );
	header( 'Cache-Control: public, max-age=300' );
	readfile( $pack );
	exit;
}
