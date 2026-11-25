<?php
/**
 * Wordfence record normalizer.
 *
 * Supports the real Wordfence Intelligence v3 shape and the legacy fixture
 * shape used by earlier tests.
 *
 * @package WPSD_Feed
 */

class Feed_Normalizer {

	/**
	 * Normalize Wordfence records into the pack data schema.
	 *
	 * @param array $records Raw records.
	 * @return array{schema:string,components:array,skipped:int}
	 */
	public function normalize( array $records ): array {
		$components = array();
		$skipped    = 0;
		$max        = (int) Config::get( 'max_advisory_entries', 200000 );
		$total      = 0;

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				$skipped++;
				continue;
			}
			if ( ! empty( $record['informational'] ) ) {
				$skipped++;
				continue;
			}
			$id    = (string) ( $record['id'] ?? $record['uuid'] ?? '' );
			$title = (string) ( $record['title'] ?? '' );
			if ( '' === $id || strlen( $id ) > 190 || '' === $title || strlen( $title ) > 500 ) {
				$skipped++;
				continue;
			}
			$severity = $this->severity( $record );
			$cvss     = $this->cvss( $record );
			$cve      = $record['cve'] ?? null;
			$cve      = is_string( $cve ) && strlen( $cve ) <= 30 ? $cve : null;
			$desc     = isset( $record['description'] ) ? substr( (string) $record['description'], 0, 5000 ) : '';
			$published = $this->date( $record['published'] ?? null );
			$modified  = $this->date( $record['updated'] ?? $record['modified'] ?? null );
			$software  = $record['software'] ?? null;
			if ( ! is_array( $software ) || empty( $software ) ) {
				$skipped++;
				continue;
			}

			foreach ( $software as $entry ) {
				if ( ! is_array( $entry ) ) {
					$skipped++;
					continue;
				}
				$type = (string) ( $entry['type'] ?? '' );
				$slug = (string) ( $entry['slug'] ?? '' );
				if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) || '' === $slug || strlen( $slug ) > 190 ) {
					$skipped++;
					continue;
				}
				$ranges = $this->ranges( $entry );
				if ( empty( $ranges ) ) {
					$skipped++;
					continue;
				}
				$fixed = $this->fixed_version( $entry );
				$name  = (string) ( $entry['name'] ?? $record['name'] ?? $slug );

				foreach ( $ranges as $range ) {
					if ( $total >= $max ) {
						$skipped++;
						continue;
					}
					if ( strlen( $range['from'] ) > 50 || strlen( $range['to'] ) > 50 ) {
						$skipped++;
						continue;
					}
					$total++;
					$key = $type . '|' . $slug;
					if ( ! isset( $components[ $key ] ) ) {
						$components[ $key ] = array(
							'type'       => $type,
							'slug'       => $slug,
							'name'       => $name,
							'advisories' => array(),
						);
					}
					$components[ $key ]['advisories'][] = array(
						'id'                       => $id,
						'title'                    => $title,
						'severity'                 => $severity,
						'cvss'                     => $cvss,
						'cve'                      => $cve,
						'affected_from'            => $range['from'],
						'affected_to'              => $range['to'],
						'affected_from_inclusive'  => $range['from_inclusive'],
						'affected_to_inclusive'    => $range['to_inclusive'],
						'fixed_version'            => $fixed,
						'description'              => $desc,
						'published_at'             => $published,
						'modified_at'              => $modified,
					);
				}
			}
		}

		$out = array_values( $components );
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['type'] . ':' . $a['slug'], $b['type'] . ':' . $b['slug'] );
			}
		);
		foreach ( $out as &$component ) {
			usort(
				$component['advisories'],
				static function ( $a, $b ) {
					return strcmp(
						$a['id'] . '|' . $a['affected_from'] . '|' . $a['affected_to'],
						$b['id'] . '|' . $b['affected_from'] . '|' . $b['affected_to']
					);
				}
			);
		}
		unset( $component );

		return array(
			'schema'     => 'wpdiag-advisories-v1',
			'components' => $out,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Extract ranges from an affected_versions map or legacy fields.
	 *
	 * @param array $entry Software entry.
	 * @return array
	 */
	private function ranges( array $entry ): array {
		$ranges = array();

		if ( isset( $entry['affected_versions'] ) && is_array( $entry['affected_versions'] ) ) {
			if ( array_key_exists( 'lower', $entry['affected_versions'] ) || array_key_exists( 'upper', $entry['affected_versions'] ) ) {
				$ranges[] = $this->range(
					$entry['affected_versions']['lower'] ?? '',
					$entry['affected_versions']['upper'] ?? '',
					true,
					true
				);
			} else {
				foreach ( $entry['affected_versions'] as $range ) {
					if ( ! is_array( $range ) ) {
						continue;
					}
					if ( array_key_exists( 'from_version', $range ) || array_key_exists( 'to_version', $range ) ) {
						$ranges[] = $this->range(
							$range['from_version'] ?? '',
							$range['to_version'] ?? '',
							$range['from_inclusive'] ?? true,
							$range['to_inclusive'] ?? true
						);
					} elseif ( array_key_exists( 'lower', $range ) || array_key_exists( 'upper', $range ) ) {
						$ranges[] = $this->range( $range['lower'] ?? '', $range['upper'] ?? '', true, true );
					}
				}
			}
		}

		if ( empty( $ranges ) && isset( $entry['affected_ranges'] ) && is_array( $entry['affected_ranges'] ) ) {
			foreach ( $entry['affected_ranges'] as $range ) {
				if ( ! is_array( $range ) ) {
					continue;
				}
				if ( array_key_exists( 'from_version', $range ) || array_key_exists( 'to_version', $range ) ) {
					$ranges[] = $this->range(
						$range['from_version'] ?? '',
						$range['to_version'] ?? '',
						$range['from_inclusive'] ?? true,
						$range['to_inclusive'] ?? true
					);
				} elseif ( array_key_exists( 'lower', $range ) || array_key_exists( 'upper', $range ) ) {
					$ranges[] = $this->range( $range['lower'] ?? '', $range['upper'] ?? '', true, true );
				}
			}
		}

		return array_values( $ranges );
	}

	/**
	 * Build a normalized range.
	 *
	 * @param mixed $from         Lower bound.
	 * @param mixed $to           Upper bound.
	 * @param mixed $from_inc     Lower inclusive.
	 * @param mixed $to_inc       Upper inclusive.
	 * @return array
	 */
	private function range( $from, $to, $from_inc, $to_inc ): array {
		return array(
			'from'           => $this->version_string( $from ),
			'to'             => $this->version_string( $to ),
			'from_inclusive' => (bool) $from_inc,
			'to_inclusive'   => (bool) $to_inc,
		);
	}

	/**
	 * Normalize a version bound; '*' means unbounded.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function version_string( $value ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return '*' === $value ? '' : $value;
	}

	/**
	 * Select the smallest patched version.
	 *
	 * @param array $entry Software entry.
	 * @return string|null
	 */
	private function fixed_version( array $entry ): ?string {
		$patched = $entry['patched_versions'] ?? null;
		if ( is_string( $patched ) || is_numeric( $patched ) ) {
			$patched = array( $patched );
		}
		if ( ! is_array( $patched ) ) {
			return null;
		}
		$fixed = null;
		foreach ( $patched as $item ) {
			$value = '';
			if ( is_array( $item ) ) {
				$value = (string) ( $item['lower'] ?? $item['from_version'] ?? '' );
			} elseif ( is_string( $item ) || is_numeric( $item ) ) {
				$value = (string) $item;
			}
			$value = trim( $value );
			if ( '' === $value || '*' === $value ) {
				continue;
			}
			if ( null === $fixed || version_compare( $value, $fixed, '<' ) ) {
				$fixed = $value;
			}
		}
		return $fixed;
	}

	/**
	 * Severity from the top-level field, CVSS rating, or score.
	 *
	 * @param array $record Record.
	 * @return string|null
	 */
	private function severity( array $record ): ?string {
		$severity = isset( $record['severity'] ) ? strtolower( (string) $record['severity'] ) : '';
		if ( in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			return $severity;
		}
		$rating = isset( $record['cvss']['rating'] ) ? strtolower( (string) $record['cvss']['rating'] ) : '';
		if ( 'none' === $rating ) {
			return null;
		}
		if ( in_array( $rating, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			return $rating;
		}
		return $this->severity_from_cvss( $this->cvss( $record ) );
	}

	/**
	 * CVSS base score.
	 *
	 * @param array $record Record.
	 * @return float|null
	 */
	private function cvss( array $record ): ?float {
		$score = $record['cvss']['score'] ?? $record['cvss']['base_score'] ?? $record['cvss_score'] ?? null;
		return is_numeric( $score ) ? (float) $score : null;
	}

	private function severity_from_cvss( $score ): ?string {
		if ( ! is_numeric( $score ) ) {
			return null;
		}
		$score = (float) $score;
		if ( $score >= 9.0 ) {
			return 'critical';
		}
		if ( $score >= 7.0 ) {
			return 'high';
		}
		if ( $score >= 4.0 ) {
			return 'medium';
		}
		return 'low';
	}

	private function date( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'c', $ts ) : null;
	}
}
