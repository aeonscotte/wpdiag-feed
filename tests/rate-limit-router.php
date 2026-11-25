<?php
/**
 * Test router: always returns a 429 with a long Retry-After.
 *
 * @package WPSD_Feed
 */

header( 'HTTP/1.1 429 Too Many Requests' );
header( 'Content-Type: application/json' );
header( 'Retry-After: 30000' );
echo '{"errors":[{"status":429,"title":"Rate limit exceeded"}]}';
