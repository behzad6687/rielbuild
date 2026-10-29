<?php
/**
 * Site configuration: safe defaults, committed and shipped.
 *
 * Only the database settings, app_secret, cron_token, base_url, live_url, paths and
 * debug live in files. Everything staff may change lives in the settings table
 * (Admin > Settings). app/config.local.php (written by the installer, never shipped)
 * overrides this file through array_replace_recursive.
 */
defined('ICM') or exit;

$config = [
    'site_name'  => 'RIELBUILD',
    'base_url'   => 'https://rielbuild.ca',      // overwritten by config.local.php
    'live_url'   => '',                          // the real domain once live (empty: demo, noindex everywhere)
    'locale'     => 'en_CA',
    'timezone'   => 'America/Toronto',           // display zone; PHP itself runs in UTC (see bootstrap)
    'db'         => [
        'driver'  => 'sqlite',                   // sqlite needs no database setup; mysql is supported too
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
        'sqlite'  => dirname(__DIR__) . '/storage/site.sqlite',
    ],
    'paths'      => ['private' => 'storage/private'],   // RELATIVE to the site folder
    'app_secret' => '',        // 64 hex chars, required: HMAC key for form tokens, file tokens and sealed settings
    'cron_token' => '',        // 64 hex chars
    'debug'      => false,
    'mail'       => ['transport' => 'smtp'],   // real mail settings live in the settings table (Admin > Settings)
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $over = require $local;
    if (is_array($over)) {
        $config = array_replace_recursive($config, $over);
    }
}

// Tests and the installer may inject overrides before bootstrap runs.
if (isset($GLOBALS['ICM_CONFIG_OVERRIDE']) && is_array($GLOBALS['ICM_CONFIG_OVERRIDE'])) {
    $config = array_replace_recursive($config, $GLOBALS['ICM_CONFIG_OVERRIDE']);
}

return $config;
