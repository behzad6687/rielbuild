<?php
/** A database backup into storage/backups (the oldest are pruned). */
defined('ICM') or exit;
return static function (array $ctx): string {
    $out = Backup::nightly($ctx['deadline']);
    Backup::prune();
    return $out;
};
