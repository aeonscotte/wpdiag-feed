<?php
/**
 * Feed server configuration.
 *
 * @package WPSD_Feed
 */

class Config {

	private static $values = null;

	public static function load( array $file_config ): void {
		$defaults = array(
			'wordfence_api_key'        => '',
			'wordfence_api_url'        => 'https://www.wordfence.com/api/intelligence/v2/vulnerabilities/production',
			'refresh_interval_seconds' => 3600,
			'max_response_bytes'       => 268435456,
			'max_advisory_entries'     => 200000,
			'retention_count'          => 10,
			'signing_secret_key_path'  => dirname( __DIR__ ) . '/keys/signing-secret.key',
			'packs_dir'                => dirname( __DIR__ ) . '/data/packs',
			'work_dir'                 => dirname( __DIR__ ) . '/data/work',
			'log_dir'                  => dirname( __DIR__ ) . '/data/logs',
			'feed_source'              => 'wordfence',
			'http_timeout'             => 120,
			'fixture_version_override_allowed' => false,
		);
		self::$values = array_merge( $defaults, $file_config );
		$env_key = getenv( 'WORDFENCE_API_KEY' );
		if ( false !== $env_key && '' !== $env_key ) {
			self::$values['wordfence_api_key'] = $env_key;
		}
	}

	public static function get( string $key, $default = null ) {
		if ( null === self::$values ) {
			self::$values = array();
		}
		$env = getenv( 'WPDIAG_' . strtoupper( $key ) );
		if ( false !== $env && '' !== $env ) {
			if ( is_numeric( $env ) ) {
				return 0 + $env;
			}
			return $env;
		}
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default;
	}
}
