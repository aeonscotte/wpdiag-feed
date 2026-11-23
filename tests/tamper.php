#!/usr/bin/env php
<?php
/**
 * Rebuild a pack with one corrupted data byte (integrity test helper).
 */

$src = $argv[1] ?? '';
$dst = $argv[2] ?? '';
if ( '' === $src || '' === $dst || ! is_file( $src ) ) {
	fwrite( STDERR, "Usage: tamper.php <src.pack> <dst.pack>\n" );
	exit( 1 );
}
$zip = new ZipArchive();
$zip->open( $src );
$entries = array();
foreach ( array( 'manifest.json', 'data.json', 'signature.sig' ) as $name ) {
	$entries[ $name ] = $zip->getFromName( $name );
}
$zip->close();
$entries['data.json'][5] = ( 'X' === $entries['data.json'][5] ) ? 'Y' : 'X';
$out = new ZipArchive();
$out->open( $dst, ZipArchive::CREATE | ZipArchive::OVERWRITE );
foreach ( $entries as $name => $content ) {
	$out->addFromString( $name, $content );
}
$out->close();
echo "Tampered pack written: {$dst}\n";
