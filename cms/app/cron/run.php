<?php
/** The optional cron entry point: php app/cron/run.php (every 5 minutes). */
if (PHP_SAPI !== 'cli') exit;
define('ICM', 1);
require dirname(__DIR__) . '/bootstrap.php';
if ($why = Cron::checkRuntime()) {
    Log::write('cron', 'refused: ' . $why);
    fwrite(STDERR, $why . "\n");
    exit(1);
}
Cron::run(50, 'cli');
