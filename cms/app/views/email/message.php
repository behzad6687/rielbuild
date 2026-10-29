<?php
/**
 * A plain transactional message: form auto-replies (form_types) and owner
 * alerts (Notify::owners). Owner: W4.
 *
 * @var string $title @var list<string> $paragraphs @var string $identity @var string $baseUrl
 */
defined('ICM') or exit;

$font = 'Arial,Helvetica,sans-serif';
$host = (string)(parse_url($baseUrl, PHP_URL_HOST) ?: 'rielbuild.ca');
?><!doctype html>
<html lang="en-CA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
</head>
<body style="margin:0;padding:0;background:#f0eeea;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0eeea;">
<tr><td style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e2ded6;">
<tr><td style="padding:26px 30px 6px;font:700 18px/1.3 <?= $font ?>;color:#17161a;"><?= e($title) ?></td></tr>
<tr><td style="padding:8px 30px 20px;font:15px/1.6 <?= $font ?>;color:#17161a;">
<?php foreach ($paragraphs as $p): ?>
<p style="margin:0 0 14px;"><?= nl2br(e($p)) ?></p>
<?php endforeach; ?>
</td></tr>
<tr><td style="padding:16px 30px 22px;border-top:1px solid #e2ded6;font:12px/1.6 <?= $font ?>;color:#55535a;">
<?= e($identity) ?><br><a href="<?= e($baseUrl) ?>" style="color:#55535a;"><?= e($host) ?></a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
