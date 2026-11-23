<?php
/**
 * Pack store: publishing, index and retention.
 *
 * @package WPSD_Feed
 */

class Pack_Store {

	private function index_path(): string {
		return rtrim( (string) Config::get( 'packs_dir' ), '/' ) . '/index.json';
	}

	public function latest_version(): ?string {
		$index = $this->meta();
		return isset( $index['latest'] ) ? (string) $index['latest'] : null;
	}

	public function latest_pack_path(): ?string {
		$version = $this->latest_version();
		return $version ? $this->pack_path( $version ) : null;
	}

	public function pack_path( string $version ): ?string {
		if ( ! preg_match( '/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $version ) ) {
			return null;
		}
		$path = rtrim( (string) Config::get( 'packs_dir' ), '/' ) . '/pack-' . $version . '.wpdiag-pack';
		return is_file( $path ) ? $path : null;
	}

	public function meta(): array {
		$raw = is_file( $this->index_path() ) ? json_decode( (string) file_get_contents( $this->index_path() ), true ) : null;
		return is_array( $raw ) ? $raw : array();
	}

	public function publish( string $work_zip, string $version ): void {
		if ( $this->latest_version() === $version ) {
			throw new RuntimeException( 'Pack version already published this minute.' );
		}
		$dest = rtrim( (string) Config::get( 'packs_dir' ), '/' ) . '/pack-' . $version . '.wpdiag-pack';
		if ( ! rename( $work_zip, $dest ) ) {
			throw new RuntimeException( 'Could not publish pack.' );
		}
		$meta = isset( $this->meta()['packs'] ) ? (array) $this->meta()['packs'] : array();
		$manifest = Pack_Verifier::verify( $dest, $this->public_key() )['manifest'];
		array_unshift(
			$meta,
			array(
				'version'        => $version,
				'generated_at'   => (string) $manifest['generated_at'],
				'advisory_count' => (int) $manifest['advisory_count'],
				'size'           => (int) filesize( $dest ),
			)
		);
		$index = array( 'latest' => $version, 'packs' => $meta );
		$tmp   = $this->index_path() . '.tmp';
		file_put_contents( $tmp, json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		rename( $tmp, $this->index_path() );
	}

	public function prune(): int {
		$retention = max( 1, (int) Config::get( 'retention_count', 10 ) );
		$meta      = $this->meta();
		$packs     = isset( $meta['packs'] ) ? (array) $meta['packs'] : array();
		$deleted   = 0;
		while ( count( $packs ) > $retention ) {
			$oldest = array_pop( $packs );
			if ( ! $oldest || (string) $oldest['version'] === (string) ( $meta['latest'] ?? '' ) ) {
				array_unshift( $packs, $oldest );
				break;
			}
			$path = rtrim( (string) Config::get( 'packs_dir' ), '/' ) . '/pack-' . $oldest['version'] . '.wpdiag-pack';
			if ( is_file( $path ) ) {
				unlink( $path );
				$deleted++;
			}
		}
		$meta['packs'] = $packs;
		file_put_contents( $this->index_path(), json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		return $deleted;
	}

	public function public_key(): string {
		$secret = (string) Config::get( 'signing_secret_key_path' );
		return base64_encode( sodium_crypto_sign_publickey_from_secretkey( (string) file_get_contents( $secret ) ) );
	}
}
