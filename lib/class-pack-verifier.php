<?php
/**
 * Pack verifier.
 *
 * @package WPSD_Feed
 */

class Pack_Verifier {

	/**
	 * Verify a pack.
	 *
	 * @param string $zip_path   Path.
	 * @param string $public_key Base64 public key.
	 * @return array{manifest:array,data:array}|\WP_Error-like
	 */
	public static function verify( string $zip_path, string $public_key ) {
		$fail = static function ( string $code, string $message ) {
			return new RuntimeException( $code . ': ' . $message, 0 );
		};
		if ( ! is_file( $zip_path ) ) {
			throw new RuntimeException( 'pack_unreadable' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) || 3 !== (int) $zip->numFiles ) {
			throw new RuntimeException( 'pack_entries' );
		}
		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$data_raw     = $zip->getFromName( 'data.json' );
		$sig_raw      = $zip->getFromName( 'signature.sig' );
		$zip->close();
		if ( ! is_string( $manifest_raw ) || ! is_string( $data_raw ) || ! is_string( $sig_raw ) ) {
			throw new RuntimeException( 'pack_unreadable' );
		}
		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) || 'wpdiag-vuln-pack' !== ( $manifest['format'] ?? '' ) || 1 !== (int) ( $manifest['format_version'] ?? 0 ) ) {
			throw new RuntimeException( 'manifest_invalid' );
		}
		if ( ! preg_match( '/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', (string) ( $manifest['pack_version'] ?? '' ) ) ) {
			throw new RuntimeException( 'manifest_version' );
		}
		$signature = base64_decode( trim( $sig_raw ), true );
		if ( false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			throw new RuntimeException( 'signature_malformed' );
		}
		if ( ! sodium_crypto_sign_verify_detached( $signature, $manifest_raw, base64_decode( $public_key, true ) ) ) {
			throw new RuntimeException( 'signature_invalid' );
		}
		if ( ! hash_equals( (string) $manifest['data_sha256'], hash( 'sha256', $data_raw ) ) ) {
			throw new RuntimeException( 'hash_mismatch' );
		}
		$data = json_decode( $data_raw, true );
		if ( ! is_array( $data ) || 'wpdiag-advisories-v1' !== ( $data['schema'] ?? '' ) || empty( $data['components'] ) ) {
			throw new RuntimeException( 'schema_invalid' );
		}
		return array( 'manifest' => $manifest, 'data' => $data );
	}
}
