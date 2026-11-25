<?php
/**
 * Feed server bootstrap.
 *
 * @package WPSD_Feed
 */

require_once __DIR__ . '/class-config.php';
require_once __DIR__ . '/class-logger.php';

$config_file = dirname( __DIR__ ) . '/config.php';
$config      = array();
if ( is_file( $config_file ) ) {
	$config = require $config_file;
	if ( ! is_array( $config ) ) {
		$config = array();
	}
}
Config::load( $config );

foreach ( array( 'packs_dir', 'work_dir', 'log_dir', 'state_dir' ) as $dir_key ) {
	$dir = Config::get( $dir_key );
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}
}

require_once __DIR__ . '/class-fetch-state.php';
require_once __DIR__ . '/class-wordfence-client.php';
require_once __DIR__ . '/class-normalizer.php';
require_once __DIR__ . '/class-pack-builder.php';
require_once __DIR__ . '/class-pack-verifier.php';
require_once __DIR__ . '/class-pack-store.php';
