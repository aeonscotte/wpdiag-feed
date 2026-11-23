<?php
/**
 * Copy to config.php and adjust. Environment overrides:
 * WORDFENCE_API_KEY, WPDIAG_* settings.
 */
return array(
	'wordfence_api_key'        => '', // Prefer the WORDFENCE_API_KEY environment variable.
	'wordfence_api_url'        => 'https://www.wordfence.com/api/intelligence/v2/vulnerabilities/production',
	'refresh_interval_seconds' => 3600,
	'max_response_bytes'       => 268435456,
	'max_advisory_entries'     => 200000,
	'retention_count'          => 10,
	'signing_secret_key_path'  => __DIR__ . '/keys/signing-secret.key',
	'packs_dir'                => __DIR__ . '/data/packs',
	'work_dir'                 => __DIR__ . '/data/work',
	'log_dir'                  => __DIR__ . '/data/logs',
	'feed_source'              => 'wordfence',
	'http_timeout'             => 120,
);
