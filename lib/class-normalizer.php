<?php
/**
 * Wordfence record normalizer.
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
			$id    = (string) ( $record['id'] ?? $record['uuid'] ?? '' );
			$title = (string) ( $record['title'] ?? '' );
			if ( '' === $id || strlen( $id ) > 190 || '' === $title || strlen( $title ) > 500 ) {
				$skipped++;
				continue;
			}
			$severity = (string) ( $record['severity'] ?? '' );
			if ( ! in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
				$severity = $this->severity_from_cvss( $record['cvss']['base_score'] ?? $record['cvss_score'] ?? null );
			}
			$cvss = $record['cvss']['base_score'] ?? $record['cvss_score'] ?? null;
			$cvss = is_numeric( $cvss ) ? (float) $cvss : null;
			$cve  = $record['cve'] ?? null;
			$cve  = is_string( $cve ) && strlen( $cve ) <= 30 ? $cve : null;
			$desc = isset( $record['description'] ) ? substr( (string) $record['description'], 0, 5000 ) : '';
			$published = $this->date( $record['published'] ?? null );
			$modified  = $this->date( $record['modified'] ?? null );
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
				$ranges = array();
				if ( isset( $entry['affected_versions'] ) && is_array( $entry['affected_versions'] ) ) {
					$ranges[] = array( 'from' => (string) ( $entry['affected_versions']['lower'] ?? '' ), 'to' => (string) ( $entry['affected_versions']['upper'] ?? '' ) );
				} elseif ( isset( $entry['affected_ranges'] ) && is_array( $entry['affected_ranges'] ) ) {
					foreach ( $entry['affected_ranges'] as $range ) {
						if ( is_array( $range ) ) {
							$ranges[] = array( 'from' => (string) ( $range['lower'] ?? '' ), 'to' => (string) ( $range['upper'] ?? '' ) );
						}
					}
				}
				if ( empty( $ranges ) ) {
					$skipped++;
					continue;
				}
				$fixed = null;
				if ( isset( $entry['patched_versions'] ) && is_array( $entry['patched_versions'] ) ) {
					foreach ( $entry['patched_versions'] as $patched ) {
						$lower = is_array( $patched ) ? (string) ( $patched['lower'] ?? '' ) : '';
						if ( '' !== $lower && ( null === $fixed || version_compare( $lower, $fixed, '<' ) ) ) {
							$fixed = $lower;
						}
					}
				}
				$name = (string) ( $entry['name'] ?? $record['name'] ?? $slug );
				foreach ( $ranges as $range ) {
					if ( $total >= $max ) {
						$skipped++;
						continue;
					}
					$total++;
					$key = $type . '|' . $slug;
					if ( ! isset( $components[ $key ] ) ) {
						$components[ $key ] = array( 'type' => $type, 'slug' => $slug, 'name' => $name, 'advisories' => array() );
					}
					$components[ $key ]['advisories'][] = array(
						'id'             => $id,
						'title'          => $title,
						'severity'       => null === $severity ? null : $severity,
						'cvss'           => $cvss,
						'cve'            => $cve,
						'affected_from'  => $range['from'],
						'affected_to'    => $range['to'],
						'fixed_version'  => $fixed,
						'description'    => $desc,
						'published_at'   => $published,
						'modified_at'    => $modified,
					);
				}
			}
		}
		$out = array_values( $components );
		usort( $out, static function ( $a, $b ) {
			return strcmp( $a['type'] . ':' . $a['slug'], $b['type'] . ':' . $b['slug'] );
		} );
		return array( 'schema' => 'wpdiag-advisories-v1', 'components' => $out, 'skipped' => $skipped );
	}

	private function severity_from_cvss( $score ): ?string {
		if ( ! is_numeric( $score ) ) {
			return null;
		}
		$score = (float) $score;
		if ( $score >= 9.0 ) { return 'critical'; }
		if ( $score >= 7.0 ) { return 'high'; }
		if ( $score >= 4.0 ) { return 'medium'; }
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
