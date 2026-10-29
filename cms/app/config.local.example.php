<?php
/**
 * Manual-install fallback. The /install/ page normally writes app/config.local.php for you.
 * Only if you cannot run it: copy this file to config.local.php and fill in the values.
 * base_url has a scheme and host and no path. Never share or commit config.local.php.
 */
defined('ICM') or exit;

return [
    'base_url'   => 'https://rielbuild.ca',
    'live_url'   => '',          // set to the same address as base_url when the site is the real, public one
    'db'         => ['driver' => 'sqlite', 'sqlite' => 'storage/site.sqlite'],
    'app_secret' => '',          // 64 hex characters: bin2hex(random_bytes(32))
    'cron_token' => '',          // 64 hex characters: bin2hex(random_bytes(32))
    'debug'      => false,
];
