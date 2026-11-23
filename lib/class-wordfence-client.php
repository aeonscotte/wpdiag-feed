<?php
/**
 * Wordfence Intelligence client.
 *
 * @package WPSD_Feed
 */

class Wordfence_Client_Exception extends Exception {}

class Wordfence_Client {

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
		$status = 0;
		foreach ( (array) ( $http_response_header ?? array() ) as $header ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $header, $m ) ) {
				$status = (int) $m[1];
			}
		}
		if ( 200 !== $status ) {
			throw new Wordfence_Client_Exception( 'Feed returned HTTP ' . $status );
		}
		if ( strlen( $body ) > $max ) {
			throw new Wordfence_Client_Exception( 'Feed response exceeds the size limit.' );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new Wordfence_Client_Exception( 'Feed response is not valid JSON.' );
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
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			throw new Wordfence_Client_Exception( 'Fixture is not valid JSON.' );
		}
		return $data;
	}
}
