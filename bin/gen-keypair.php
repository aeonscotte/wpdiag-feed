#!/usr/bin/env php
<?php
/**
 * Generate the feed signing keypair.
 *
 * @package WPSD_Feed
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

$sec_path = (string) Config::get( 'signing_secret_key_path' );
$dir      = dirname( $sec_path );
$pub_path = $dir . '/signing-public.key';

if ( is_file( $sec_path ) ) {
	fwrite( STDERR, "Signing key already exists at {$sec_path}\n" );
	if ( is_file( $pub_path ) ) {
		echo trim( (string) file_get_contents( $pub_path ) ) . "\n";
	}
	exit( 1 );
}
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
	fwrite( STDERR, "Could not create key directory: {$dir}\n" );
	exit( 1 );
}
$keypair = sodium_crypto_sign_keypair();
$public  = sodium_crypto_sign_publickey( $keypair );
file_put_contents( $sec_path, sodium_crypto_sign_secretkey( $keypair ) );
file_put_contents( $pub_path, base64_encode( $public ) . "\n" );
chmod( $sec_path, 0600 );
echo base64_encode( $public ) . "\n";
