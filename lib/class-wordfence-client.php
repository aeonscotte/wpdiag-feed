<?php
/**
 * Wordfence Intelligence client.
 *
 * @package WPSD_Feed
 */

class Wordfence_Client_Exception extends Exception {

	/** @var int HTTP status, when one was received. */
	public int $http_status = 0;

	public function __construct( string $message, int $http_status = 0 ) {
		parent::__construct( $message );
		$this->http_status = $http_status;
	}
}

class Wordfence_Rate_Limit_Exception extends Wordfence_Client_Exception {

	/** @var int Seconds to wait from Retry-After, or 0 when absent. */
	public int $retry_after = 0;

	public function __construct( string $message, int $http_status = 429, int $retry_after = 0 ) {
		parent::__construct( $message, $http_status );
		$this->retry_after = $retry_after;
	}
}

class Wordfence_Client {

	/** @var int Bytes in the last downloaded feed. */
	public int $last_bytes = 0;

	/** @var int HTTP status of the last download. */
	public int $last_status = 0;

	/**
	 * Fetch the feed.
	 *
	 * @return array
	 * @throws Wordfence_Client_Exception On failure.
	 */
	public function fetch(): array {
		$key = (string) Config::get( 'wordfence_api_key' );
		if ( '' === $key ) {
			throw new Wordfence_Client_Exception( 'No Wordfence API key configured.' );
		}
		$url     = (string) Config::get( 'wordfence_api_url' );
		$timeout = (int) Config::get( 'http_timeout' );
		$max     = (int) Config::get( 'max_response_bytes' );
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => $timeout,
					'ignore_errors' => true,
					'header'        => "Authorization: Bearer {$key}\r\nAccept: application/json\r\nUser-Agent: wpdiag-feed/1.0\r\n",
				),
			)
		);
		$body = @file_get_contents( $url, false, $context );
		if ( false === $body ) {
			throw new Wordfence_Client_Exception( 'Feed download failed.' );
		}
		$status      = 0;
		$retry_after = 0;
		foreach ( (array) ( $http_response_header ?? array() ) as $header ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $header, $m ) ) {
				$status = (int) $m[1];
			} elseif ( preg_match( '/^Retry-After:\s*(.+)$/i', $header, $m ) ) {
				$value = trim( $m[1] );
				if ( ctype_digit( $value ) ) {
					$retry_after = (int) $value;
				} elseif ( false !== strtotime( $value ) ) {
					$retry_after = max( 0, (int) strtotime( $value ) - time() );
				}
			}
		}
		$this->last_bytes  = strlen( $body );
		$this->last_status = $status;

		if ( 429 === $status ) {
			throw new Wordfence_Rate_Limit_Exception( 'Feed rate limit reached (HTTP 429).', 429, $retry_after );
		}
		if ( 200 !== $status ) {
			throw new Wordfence_Client_Exception( 'Feed returned HTTP ' . $status, $status );
		}
		if ( strlen( $body ) > $max ) {
			throw new Wordfence_Client_Exception( 'Feed response exceeds the size limit.', $status );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new Wordfence_Client_Exception( 'Feed response is not valid JSON.', $status );
		}
		return $data;
	}

	/**
	 * Load a local fixture.
	 *
	 * @param string $path Fixture path.
	 * @return array
	 * @throws Wordfence_Client_Exception On failure.
	 */
	public function fetch_fixture( string $path ): array {
		if ( ! is_file( $path ) ) {
			throw new Wordfence_Client_Exception( 'Fixture not found: ' . $path );
		}
		$raw              = (string) file_get_contents( $path );
		$this->last_bytes = strlen( $raw );
		$data             = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new Wordfence_Client_Exception( 'Fixture is not valid JSON.' );
		}
		return $data;
	}
}
