<?php
/**
 * Durable upstream-fetch state.
 *
 * The state file is written before an upstream request so that a crash or
 * container restart cannot trigger a second request inside the rate-limit
 * window.
 *
 * @package WPSD_Feed
 */

class Fetch_State {

	const SCHEMA = 1;

	private string $path;
	private string $dir;
	private string $legacy_path;

	/**
	 * @param string|null $path        Override state file path (tests).
	 * @param string|null $legacy_path Override legacy timestamp path (tests).
	 */
	public function __construct( ?string $path = null, ?string $legacy_path = null ) {
		if ( null !== $path && '' !== $path ) {
			$this->path = $path;
			$this->dir  = dirname( $path );
		} else {
			$this->dir  = rtrim( (string) Config::get( 'state_dir', dirname( __DIR__ ) . '/data/state' ), '/' );
			$this->path = $this->dir . '/fetch-state.json';
		}
		$this->legacy_path = null !== $legacy_path
			? $legacy_path
			: rtrim( (string) Config::get( 'work_dir', dirname( __DIR__ ) . '/data/work' ), '/' ) . '/last-refresh.txt';
	}

	/**
	 * Default state.
	 *
	 * @return array
	 */
	private function defaults(): array {
		return array(
			'schema'                  => self::SCHEMA,
			'last_attempt_at'         => 0,
			'last_attempt_iso'        => null,
			'last_success_at'         => 0,
			'last_success_iso'        => null,
			'last_status'             => 'never',
			'last_http_status'        => 0,
			'last_error'              => null,
			'pack_version'            => null,
			'next_allowed_at'         => 0,
			'next_allowed_iso'        => null,
			'rate_limited_until'      => 0,
			'rate_limited_until_iso'  => null,
		);
	}

	/**
	 * Read the persisted state, migrating the legacy timestamp file if needed.
	 *
	 * @return array
	 */
	public function read(): array {
		$state = $this->defaults();

		if ( is_file( $this->path ) ) {
			$raw     = @file_get_contents( $this->path );
			$decoded = json_decode( (string) $raw, true );
			if ( is_array( $decoded ) && (int) ( $decoded['schema'] ?? 0 ) === self::SCHEMA ) {
				foreach ( $state as $key => $value ) {
					if ( array_key_exists( $key, $decoded ) ) {
						$state[ $key ] = $decoded[ $key ];
					}
				}
				return $this->normalize( $state );
			}

			// A partial/corrupt file must not cause an immediate re-fetch.
			$mtime = (int) @filemtime( $this->path );
			if ( $mtime <= 0 ) {
				// The file exists but cannot be inspected. Block conservatively.
				$mtime = time();
			}
			Logger::log( 'state_corrupt', 'Using the state file mtime as a conservative last-attempt time.' );
			$state['last_attempt_at']  = $mtime;
			$state['last_attempt_iso'] = gmdate( 'c', $mtime );
			$state['last_status']      = 'unknown';
			$state['next_allowed_at']  = $mtime + $this->interval();
			$state['next_allowed_iso'] = gmdate( 'c', $state['next_allowed_at'] );
			return $state;
		}

		if ( is_file( $this->legacy_path ) ) {
			$ts = (int) file_get_contents( $this->legacy_path );
			if ( $ts > 0 ) {
				$state['last_attempt_at']  = $ts;
				$state['last_attempt_iso'] = gmdate( 'c', $ts );
				$state['last_success_at']  = $ts;
				$state['last_success_iso'] = gmdate( 'c', $ts );
				$state['last_status']      = 'ok';
				$state['next_allowed_at']  = $ts + $this->interval();
				$state['next_allowed_iso'] = gmdate( 'c', $state['next_allowed_at'] );
				try {
					$this->write( $state );
					@unlink( $this->legacy_path );
				} catch ( Throwable $e ) {
					Logger::log( 'state_migration_failed', $e->getMessage() );
				}
			}
		}

		return $this->normalize( $state );
	}

	/**
	 * Atomically persist the state.
	 *
	 * @param array $state State.
	 * @throws RuntimeException When the state cannot be written.
	 */
	public function write( array $state ): void {
		$state['schema'] = self::SCHEMA;
		if ( ! is_dir( $this->dir ) && ! @mkdir( $this->dir, 0750, true ) && ! is_dir( $this->dir ) ) {
			throw new RuntimeException( 'State directory is not writable: ' . $this->dir );
		}
		$raw = json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $raw || '' === $raw ) {
			throw new RuntimeException( 'Could not encode fetch state.' );
		}
		$tmp = $this->path . '.tmp.' . getmypid() . '.' . bin2hex( random_bytes( 4 ) );
		if ( false === file_put_contents( $tmp, $raw, LOCK_EX ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'Could not write fetch state: ' . $this->path );
		}
		if ( function_exists( 'fsync' ) ) {
			$handle = @fopen( $tmp, 'r+' );
			if ( false !== $handle ) {
				@fsync( $handle );
				fclose( $handle );
			}
		}
		if ( ! @rename( $tmp, $this->path ) ) {
			@unlink( $tmp );
			throw new RuntimeException( 'Could not replace fetch state: ' . $this->path );
		}
		@chmod( $this->path, 0640 );
	}

	/**
	 * Decide whether an upstream fetch is allowed.
	 *
	 * @param int  $now   Current unix timestamp.
	 * @param bool $force Explicit operator override.
	 * @return array{allowed:bool,reason:string,state:array,now:int,interval:int,hard_min:int}
	 */
	public function check( int $now, bool $force = false ): array {
		$state    = $this->read();
		$interval = $this->interval();
		$hard_min = max( 0, (int) Config::get( 'live_force_min_interval_seconds', 1800 ) );
		$allowed  = true;
		$reason   = 'due';

		if ( (int) $state['rate_limited_until'] > $now ) {
			// A 429 backoff is never bypassed, even with --force.
			$allowed = false;
			$reason  = 'rate_limited';
		} elseif ( (int) $state['last_attempt_at'] > 0 ) {
			$elapsed = $now - (int) $state['last_attempt_at'];
			if ( $force ) {
				if ( $elapsed < $hard_min ) {
					$allowed = false;
					$reason  = 'hard_limit';
				} else {
					$reason = 'forced';
				}
			} elseif ( $now < (int) $state['next_allowed_at'] ) {
				$allowed = false;
				$reason  = 'interval';
			}
		} elseif ( $force ) {
			$reason = 'forced';
		}

		return array(
			'allowed'  => $allowed,
			'reason'   => $reason,
			'state'    => $state,
			'now'      => $now,
			'interval' => $interval,
			'hard_min' => $hard_min,
		);
	}

	/**
	 * Record that an upstream request is about to be made. Must be persisted
	 * before the request so a crash cannot allow a duplicate fetch.
	 *
	 * @param array $state State.
	 * @param int   $now   Current timestamp.
	 * @return array
	 */
	public function mark_started( array $state, int $now ): array {
		if ( (int) $state['rate_limited_until'] > $now ) {
			throw new RuntimeException( 'Rate-limit backoff is still active.' );
		}
		$state['last_attempt_at']        = $now;
		$state['last_attempt_iso']       = gmdate( 'c', $now );
		$state['last_status']            = 'in_progress';
		$state['last_http_status']       = 0;
		$state['last_error']             = null;
		$state['next_allowed_at']        = $now + $this->interval();
		$state['next_allowed_iso']       = gmdate( 'c', $state['next_allowed_at'] );
		$state['rate_limited_until']     = 0;
		$state['rate_limited_until_iso'] = null;
		$this->write( $state );
		return $state;
	}

	/**
	 * Record a successful fetch/build/publish.
	 *
	 * @param array  $state   State.
	 * @param int    $now     Current timestamp.
	 * @param string $version Published pack version.
	 * @param int    $http    HTTP status.
	 * @return array
	 */
	public function mark_success( array $state, int $now, string $version, int $http = 200 ): array {
		$state['last_status']            = 'ok';
		$state['last_success_at']        = $now;
		$state['last_success_iso']       = gmdate( 'c', $now );
		$state['last_http_status']       = $http;
		$state['last_error']             = null;
		$state['pack_version']           = $version;
		$state['rate_limited_until']     = 0;
		$state['rate_limited_until_iso'] = null;
		$this->write( $state );
		return $state;
	}

	/**
	 * Record a failed or rate-limited fetch/build. The next-allowed time is
	 * never moved earlier than the already-persisted guard.
	 *
	 * @param array  $state       State.
	 * @param int    $now         Current timestamp.
	 * @param string $status      failed|rate_limited.
	 * @param string $error       Error message.
	 * @param int    $http        HTTP status.
	 * @param int    $retry_after Retry-After seconds, when supplied.
	 * @return array
	 */
	public function mark_failure( array $state, int $now, string $status, string $error, int $http = 0, int $retry_after = 0 ): array {
		$state['last_status']      = $status;
		$state['last_http_status'] = $http;
		$state['last_error']       = substr( $error, 0, 1000 );
		$next = max( (int) $state['next_allowed_at'], $now + $this->interval() );
		if ( $retry_after > 0 ) {
			$next = max( $next, $now + $retry_after );
		}
		$state['next_allowed_at']  = $next;
		$state['next_allowed_iso'] = gmdate( 'c', $next );
		if ( 'rate_limited' === $status ) {
			$state['rate_limited_until']     = max( (int) $state['rate_limited_until'], $next );
			$state['rate_limited_until_iso'] = gmdate( 'c', $state['rate_limited_until'] );
		}
		$this->write( $state );
		return $state;
	}

	/**
	 * Effective interval in seconds.
	 *
	 * @return int
	 */
	private function interval(): int {
		return max( 60, (int) Config::get( 'refresh_interval_seconds', 21600 ) );
	}

	/**
	 * Normalize field types and fill missing ISO values/guards.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function normalize( array $state ): array {
		foreach ( array( 'last_attempt_at', 'last_success_at', 'next_allowed_at', 'rate_limited_until' ) as $key ) {
			$state[ $key ] = max( 0, (int) $state[ $key ] );
		}
		$state['last_http_status'] = max( 0, (int) $state['last_http_status'] );
		$state['schema']           = self::SCHEMA;

		if ( $state['last_attempt_at'] > 0 && null === $state['last_attempt_iso'] ) {
			$state['last_attempt_iso'] = gmdate( 'c', $state['last_attempt_at'] );
		}
		if ( $state['last_success_at'] > 0 && null === $state['last_success_iso'] ) {
			$state['last_success_iso'] = gmdate( 'c', $state['last_success_at'] );
		}
		if ( $state['last_attempt_at'] > 0 && $state['next_allowed_at'] <= 0 ) {
			$state['next_allowed_at']  = $state['last_attempt_at'] + $this->interval();
			$state['next_allowed_iso'] = gmdate( 'c', $state['next_allowed_at'] );
		}
		if ( $state['next_allowed_at'] > 0 && null === $state['next_allowed_iso'] ) {
			$state['next_allowed_iso'] = gmdate( 'c', $state['next_allowed_at'] );
		}
		if ( $state['rate_limited_until'] > 0 && null === $state['rate_limited_until_iso'] ) {
			$state['rate_limited_until_iso'] = gmdate( 'c', $state['rate_limited_until'] );
		}
		return $state;
	}
}
