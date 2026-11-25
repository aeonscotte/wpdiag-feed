<?php
/**
 * Copy to config.php and adjust. Environment overrides:
 * WORDFENCE_API_KEY, WPDIAG_* settings.
 */
return array(
	'wordfence_api_key'        => '', // Prefer the WORDFENCE_API_KEY environment variable.
	'wordfence_api_url'        => 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production',
	'refresh_interval_seconds' => 21600, // Six hours; the durable guard enforces it.
	'live_force_min_interval_seconds' => 1800, // Hard floor even with --force on live fetches.
	'max_response_bytes'       => 268435456,
	'max_advisory_entries'     => 200000,
	'retention_count'          => 10,
	'signing_secret_key_path'  => __DIR__ . '/keys/signing-secret.key',
	'packs_dir'                => __DIR__ . '/data/packs',
	'work_dir'                 => __DIR__ . '/data/work',
	'log_dir'                  => __DIR__ . '/data/logs',
	'state_dir'                => __DIR__ . '/data/state', // Mount this as a persistent volume.
	'feed_source'              => 'wordfence',
	'http_timeout'             => 120,
);
