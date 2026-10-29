<?php
/**
 * Admin error page (403, 404, 405, 500). Owner: W7.
 * @var int $code
 * @var string $title
 * @var string $message
 * @var bool $bare
 */
defined('ICM') or exit;
$lines = [
    403 => 'Your role does not include this screen. Ask the owner if you need it.',
    404 => 'That admin page does not exist, or it has moved.',
    405 => 'This address only accepts a different kind of request.',
    500 => 'Something went wrong on the server. The error was logged. Please try again in a minute.',
];
?>
<div class="<?= !empty($bare) ? 'login' : 'page' ?>">
  <div class="<?= !empty($bare) ? 'login__box' : 'card' ?>">
    <h1><?= e($title) ?></h1>
    <p><?= e($message !== '' && $code !== 500 ? $message : ($lines[$code] ?? 'Please try again.')) ?></p>
<?php if ($code === 500 && $message !== ''): ?>
    <pre class="log"><?= e($message) ?></pre>
<?php endif; ?>
    <p><a class="btn btn--line" href="/admin/">Go to the dashboard</a></p>
  </div>
</div>
