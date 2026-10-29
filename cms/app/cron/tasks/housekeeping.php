<?php
/** Deferred emails go out while the daily cap allows; old rate-limit windows are dropped. */
defined('ICM') or exit;
return static function (array $ctx): string {
    $mail = Mailer::retryDeferred();
    $n = Db::run('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400])->rowCount();
    return 'mail: ' . $mail . '; rate limits pruned: ' . $n;
};
