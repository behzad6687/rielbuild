<?php
/**
 * Staff notification for a form submission (spec 5.1 step 4). Owner: W4.
 * Every field as label and value, files by name only (never attached), a link
 * to the admin detail. Reply-To is set to the submitter by Notify.
 *
 * @var string $title @var string $label @var list<array{0:string,1:string}> $rows
 * @var list<array> $files @var string $adminUrl @var string $received @var string $source @var string $replyTo
 */
defined('ICM') or exit;

$font = 'Arial,Helvetica,sans-serif';
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
<tr><td style="padding:24px 28px 6px;font:700 18px/1.3 <?= $font ?>;color:#17161a;"><?= e($title) ?></td></tr>
<tr><td style="padding:0 28px 18px;font:13px/1.5 <?= $font ?>;color:#55535a;">Received <?= e($received) ?><?= $source !== '' ? ' from ' . e($source) : '' ?></td></tr>
<tr><td style="padding:0 28px 6px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<?php foreach ($rows as [$k, $v]): ?>
<tr>
<td style="padding:8px 14px 8px 0;border-top:1px solid #e2ded6;font:700 13px/1.4 <?= $font ?>;color:#55535a;vertical-align:top;width:38%;"><?= e($k) ?></td>
<td style="padding:8px 0;border-top:1px solid #e2ded6;font:14px/1.5 <?= $font ?>;color:#17161a;vertical-align:top;"><?= nl2br(e($v)) ?></td>
</tr>
<?php endforeach; ?>
<?php foreach ($files as $file): ?>
<tr>
<td style="padding:8px 14px 8px 0;border-top:1px solid #e2ded6;font:700 13px/1.4 <?= $font ?>;color:#55535a;vertical-align:top;width:38%;">File</td>
<td style="padding:8px 0;border-top:1px solid #e2ded6;font:14px/1.5 <?= $font ?>;color:#17161a;vertical-align:top;"><?= e((string)$file['original_name']) ?> (<?= e((string)max(1, (int)round(((int)$file['bytes']) / 1024))) ?> KB). Download it in the admin.</td>
</tr>
<?php endforeach; ?>
</table>
</td></tr>
<tr><td style="padding:18px 28px 26px;font:14px/1.6 <?= $font ?>;color:#17161a;">
<a href="<?= e($adminUrl) ?>" style="color:#17161a;font-weight:700;">Open it in the admin</a>
<?php if ($replyTo !== ''): ?>
<br><span style="font-size:13px;color:#55535a;">Reply to this email to answer <?= e($replyTo) ?>.</span>
<?php endif; ?>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
