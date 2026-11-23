#!/usr/bin/env php
<?php
/**
 * Verify a signed pack end-to-end.
 *
 * Usage: php bin/verify-pack.php <pack> [--secret=<path>]
 *
 * @package WPSD_Feed
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

$pack   = $argv[1] ?? '';
$secret = (string) Config::get( 'signing_secret_key_path' );
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--secret=' ) ) {
		$secret = substr( $arg, 9 );
	}
}
if ( '' === $pack ) {
	fwrite( STDERR, "Usage: php bin/verify-pack.php <pack> [--secret=<path>]\n" );
	exit( 1 );
}
if ( ! is_file( $secret ) ) {
	fwrite( STDERR, "Secret key not found.\n" );
	exit( 1 );
}
$public = base64_encode( sodium_crypto_sign_publickey_from_secretkey( (string) file_get_contents( $secret ) ) );
try {
	$result = Pack_Verifier::verify( $pack, $public );
	echo 'OK version=' . (string) $result['manifest']['pack_version'] . ' advisories=' . (int) $result['manifest']['advisory_count'] . "\n";
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'FAILED: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
