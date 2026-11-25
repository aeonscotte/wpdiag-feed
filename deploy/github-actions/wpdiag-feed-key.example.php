<?php
/**
 * Trust the GitHub Releases feed signing key.
 *
 * Copy to wp-content/mu-plugins/wpdiag-feed-key.php and replace the key.
 *
 * @package WPSD
 */

add_filter(
	'wpdiag_pack_public_key',
	static function () {
		return 'PASTE_SIGNING_PUBLIC_KEY_HERE';
	}
);
