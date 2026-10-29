<?php
/**
 * Command-line tools (optional; the web installer does the same):
 *   php app/cli.php install --url=https://example.com --name="Owner Name" --email=you@example.com --password='a long password'
 *   php app/cli.php migrate      apply new database changes
 *   php app/cli.php seed         write any missing starter content (never overwrites)
 */
if (PHP_SAPI !== 'cli') exit;
define('ICM', 1);
require __DIR__ . '/bootstrap.php';

$cmd = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) if (preg_match('/^--([a-z_]+)=(.*)$/s', $a, $m)) $opt[$m[1]] = $m[2];
$say = static fn(string $s) => fwrite(STDOUT, $s . "\n");

try {
    switch ($cmd) {
        case 'install':
            if (Installer::installed()) throw new RuntimeException('Already installed.');
            foreach (Installer::requirements() as [$label, $ok, $fix]) if (!$ok) throw new RuntimeException($label . ': ' . $fix);
            Installer::writeConfig((string)($opt['url'] ?? 'http://localhost'), (string)($opt['live'] ?? ''));
            Installer::run((string)($opt['name'] ?? 'Owner'), (string)($opt['email'] ?? ''), (string)($opt['password'] ?? ''), $say);
            $say('Done. Sign in at ' . rtrim((string)($opt['url'] ?? ''), '/') . '/admin/');
            break;
        case 'migrate':
            $say('applied: ' . (implode(', ', Migrator::up()) ?: 'nothing new'));
            break;
        case 'seed':
            require_once APP_DIR . '/seed/RbSeed.php';
            RbSeed::run($say);
            break;
        default:
            $say('Usage: php app/cli.php install|migrate|seed');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
