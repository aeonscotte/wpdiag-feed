<?php
/**
 * Signed pack builder.
 *
 * @package WPSD_Feed
 */

class Pack_Builder {

	/**
	 * Build a signed pack.
	 *
	 * @param array  $data    Advisory data.
	 * @param string $version Pack version.
	 * @return string Pack path.
	 * @throws RuntimeException On failure.
	 */
	public function build( array $data, string $version ): string {
		$data_raw = wp_json_encode( $data );
		if ( false === $data_raw || '' === $data_raw ) {
			throw new RuntimeException( 'Could not encode pack data.' );
		}
		$secret_path = (string) Config::get( 'signing_secret_key_path' );
		if ( ! is_file( $secret_path ) ) {
			throw new RuntimeException( 'Signing key not found: ' . $secret_path );
		}
		$secret = file_get_contents( $secret_path );
		if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
			throw new RuntimeException( 'Invalid signing key.' );
		}
		$count = 0;
		foreach ( $data['components'] as $component ) {
			$count += count( $component['advisories'] );
		}
		$manifest = array(
			'format'         => 'wpdiag-vuln-pack',
			'format_version' => 1,
			'pack_version'   => $version,
			'generated_at'   => gmdate( 'c' ),
			'source'         => (string) Config::get( 'feed_source' ),
			'advisory_count' => $count,
			'data_sha256'    => hash( 'sha256', $data_raw ),
		);
		$manifest_raw = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$signature    = base64_encode( sodium_crypto_sign_detached( $manifest_raw, $secret ) );
		$zip_path     = rtrim( (string) Config::get( 'work_dir' ), '/' ) . '/pack-' . $version . '.wpdiag-pack';
		$zip          = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create pack archive.' );
		}
		$zip->addFromString( 'manifest.json', $manifest_raw );
		$zip->addFromString( 'data.json', $data_raw );
		$zip->addFromString( 'signature.sig', $signature );
		$zip->close();
		return $zip_path;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode helper.
	 *
	 * @param mixed $value Value.
	 * @param int   $flags Flags.
	 * @return string|false
	 */
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
