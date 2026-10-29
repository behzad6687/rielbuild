<?php
/**
 * Public front controller (spec 1.5.1). Every request that is not a real file,
 * /admin or /install arrives here through .htaccess (or _dev/router.php).
 */
define('ICM', 1);
require __DIR__ . '/app/bootstrap.php';

// HEAD gets the same headers as GET and no body.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    ob_start(static fn(string $buffer): string => '');
}

Router::handle();
