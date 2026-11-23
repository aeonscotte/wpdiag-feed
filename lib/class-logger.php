<?php
/**
 * Feed server logger.
 *
 * @package WPSD_Feed
 */

class Logger {

	public static function log( string $level, string $message ): void {
		$dir = Config::get( 'log_dir' );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}
		$file = $dir . '/refresh.log';
		if ( is_file( $file ) && filesize( $file ) > 5 * 1024 * 1024 ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES );
			$lines = array_slice( (array) $lines, -20000 );
			file_put_contents( $file, implode( "\n", $lines ) . "\n" );
		}
		file_put_contents( $file, gmdate( 'Y-m-d H:i:s' ) . ' ' . $level . ' ' . $message . "\n", FILE_APPEND );
	}
}
