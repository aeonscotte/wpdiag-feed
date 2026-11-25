#!/usr/bin/env php
<?php
/**
 * Normalizer tests against the real Wordfence v3-shaped fixture.
 *
 * @package WPSD_Feed
 */

require dirname( __DIR__ ) . '/lib/bootstrap.php';

$failures = 0;
function normalizer_assert( string $name, bool $ok ): void {
	global $failures;
	if ( $ok ) {
		echo "ok: {$name}\n";
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL: {$name}\n" );
}

$raw    = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/wordfence-sample.json' ), true );
$out    = ( new Feed_Normalizer() )->normalize( $raw );
$by_key = array();
$total  = 0;
foreach ( $out['components'] as $component ) {
	$by_key[ $component['type'] . ':' . $component['slug'] ] = $component;
	$total += count( $component['advisories'] );
}

normalizer_assert( 'five valid advisories', 5 === $total );
normalizer_assert( 'six records skipped two', 2 === (int) $out['skipped'] );

$plugin = $by_key['plugin:sample-plugin']['advisories'][0] ?? array();
normalizer_assert( 'plugin range from', '1.0.0' === ( $plugin['affected_from'] ?? '' ) );
normalizer_assert( 'plugin range to', '2.0.0' === ( $plugin['affected_to'] ?? '' ) );
normalizer_assert( 'plugin fixed version', '2.1.0' === ( $plugin['fixed_version'] ?? '' ) );
normalizer_assert( 'plugin severity from rating', 'high' === ( $plugin['severity'] ?? '' ) );
normalizer_assert( 'plugin cvss score', 8.1 === ( $plugin['cvss'] ?? null ) );
normalizer_assert( 'plugin updated date', '2026-01-03T03:04:05+00:00' === ( $plugin['modified_at'] ?? '' ) );

$core = $by_key['core:wordpress']['advisories'][0] ?? array();
normalizer_assert( 'core range', '6.0' === ( $core['affected_from'] ?? '' ) && '6.4' === ( $core['affected_to'] ?? '' ) );
normalizer_assert( 'core no fixed version', null === ( $core['fixed_version'] ?? null ) );
normalizer_assert( 'core severity', 'critical' === ( $core['severity'] ?? '' ) );

$theme = $by_key['theme:sample-theme']['advisories'] ?? array();
normalizer_assert( 'theme has two ranges', 2 === count( $theme ) );
normalizer_assert( 'theme single-version range', '1.5' === ( $theme[1]['affected_from'] ?? '' ) && '1.5' === ( $theme[1]['affected_to'] ?? '' ) );

$cvss = $by_key['plugin:cvss-plugin']['advisories'][0] ?? array();
normalizer_assert( 'unbounded range', '' === ( $cvss['affected_from'] ?? '' ) && '' === ( $cvss['affected_to'] ?? '' ) );
normalizer_assert( 'severity derived from score', 'high' === ( $cvss['severity'] ?? '' ) );

normalizer_assert( 'informational record excluded', ! isset( $by_key['plugin:informational-plugin'] ) );

// Multi-branch fixed-version selection.
$multi = array(
	'id'       => 'MULTI-BRANCH-1',
	'title'    => 'Multi-branch core advisory',
	'software' => array(
		array(
			'type'              => 'core',
			'slug'              => 'wordpress',
			'name'              => 'WordPress',
			'affected_versions' => array(
				'4.1 - 4.1.34' => array(
					'from_version'  => '4.1',
					'from_inclusive' => true,
					'to_version'    => '4.1.34',
					'to_inclusive'  => false,
				),
				'4.2 - 4.2.31' => array(
					'from_version'  => '4.2',
					'from_inclusive' => true,
					'to_version'    => '4.2.31',
					'to_inclusive'  => true,
				),
				'4.3 - 4.3.27' => array(
					'from_version'  => '4.3',
					'from_inclusive' => true,
					'to_version'    => '4.3.27',
					'to_inclusive'  => true,
				),
				'4.4 - 4.4.26' => array(
					'from_version'  => '4.4',
					'from_inclusive' => true,
					'to_version'    => '4.4.26',
					'to_inclusive'  => true,
				),
				'5.0 - *' => array(
					'from_version'  => '5.0',
					'from_inclusive' => true,
					'to_version'    => '*',
					'to_inclusive'  => true,
				),
			),
			'patched_versions'  => array( '4.1.34', '4.2.20', '4.2.32', '4.3.28', '4.4.27' ),
		),
	),
	'cvss' => array( 'score' => 9.0, 'rating' => 'Critical' ),
);
$multi_out   = ( new Feed_Normalizer() )->normalize( array( $multi ) );
$multi_fixed = array();
foreach ( $multi_out['components'][0]['advisories'] as $advisory ) {
	$multi_fixed[ $advisory['affected_from'] . '-' . $advisory['affected_to'] ] = $advisory['fixed_version'];
}
normalizer_assert( 'multi-branch: exclusive upper fix', '4.1.34' === ( $multi_fixed['4.1-4.1.34'] ?? null ) );
normalizer_assert( 'multi-branch: 4.2 fix', '4.2.32' === ( $multi_fixed['4.2-4.2.31'] ?? null ) );
normalizer_assert( 'multi-branch: 4.3 fix', '4.3.28' === ( $multi_fixed['4.3-4.3.27'] ?? null ) );
normalizer_assert( 'multi-branch: 4.4 fix', '4.4.27' === ( $multi_fixed['4.4-4.4.26'] ?? null ) );
normalizer_assert( 'multi-branch: unbounded has no fix', null === ( $multi_fixed['5.0-'] ?? null ) );

// A patched version inside the affected range is not a fix.
$inside = $multi;
$inside['id'] = 'INSIDE-1';
$inside['software'][0]['affected_versions'] = array(
	'1.0 - 2.0' => array(
		'from_version'  => '1.0',
		'from_inclusive' => true,
		'to_version'    => '2.0',
		'to_inclusive'  => true,
	),
);
$inside['software'][0]['patched_versions'] = array( '1.5', '2.1' );
$inside_out = ( new Feed_Normalizer() )->normalize( array( $inside ) );
normalizer_assert( 'patched version inside range ignored', '2.1' === ( $inside_out['components'][0]['advisories'][0]['fixed_version'] ?? null ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "Normalizer tests: FAIL ({$failures})\n" );
	exit( 1 );
}
echo "Normalizer tests: PASS\n";
