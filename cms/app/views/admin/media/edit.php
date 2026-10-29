<?php
/**
 * One media item (spec 4.2.13): details, focal point, social crop, where it is used. Owner: W7.
 * @var array $m
 * @var array $errors
 * @var array $uses
 */
defined('ICM') or exit;
$img = $m['kind'] === 'image';
$j = Media::toJson($m);
$fx = (float)$m['focal_x'];
$fy = (float)$m['focal_y'];
?>
<div class="page-head">
  <p class="crumbs"><a href="/admin/media">Media</a></p>
  <h1><?= e((string)($m['original_name'] ?: basename((string)$m['path']))) ?></h1>
  <p class="muted"><?= $img ? (int)$m['width'] . ' by ' . (int)$m['height'] . ' pixels, ' : ($m['kind'] === 'video' ? 'MP4 video, ' : 'PDF, ') ?><?= e(Backup::human((int)$m['bytes'])) ?>, added <?= e(Time::long((string)$m['created_at'])) ?></p>
</div>
<?php if (!empty($errors['_form'])): ?>
<div class="alert alert--bad" role="alert"><?= e($errors['_form']) ?></div>
<?php endif; ?>

<form method="post" action="/admin/media/edit?id=<?= (int)$m['id'] ?>" class="split">
  <?= csrfField() ?>
  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
  <input type="hidden" name="updated_at" value="<?= e((string)$m['updated_at']) ?>">
  <div class="card">
<?php if ($img): ?>
    <p class="field__label" id="focal-l">Focal point</p>
    <div class="focal" data-focal data-x="<?= e((string)$fx) ?>" data-y="<?= e((string)$fy) ?>">
      <img src="<?= e($j['preview']) ?>" alt="<?= e((string)$m['alt']) ?>" width="<?= (int)min(800, (int)$m['width']) ?>" height="<?= (int)round(min(800, (int)$m['width']) * (int)$m['height'] / max(1, (int)$m['width'])) ?>">
      <button type="button" class="focal__dot" style="left:<?= round($fx * 100, 1) ?>%;top:<?= round($fy * 100, 1) ?>%" aria-labelledby="focal-l" aria-describedby="focal-h"></button>
    </div>
    <p class="field__hint" id="focal-h">Tap the most important part of the photo. Crops (the social image and the thumbnail) keep it in view. Arrow keys move the point too.</p>
    <input type="hidden" name="focal_x" value="<?= e((string)$fx) ?>" data-focal-x>
    <input type="hidden" name="focal_y" value="<?= e((string)$fy) ?>" data-focal-y>
    <p class="field__label">Social crop (1200 by 630)</p>
    <div class="ogcrop" data-ogcrop style="background-image:url('<?= e($j['preview']) ?>');background-position:<?= round($fx * 100, 1) ?>% <?= round($fy * 100, 1) ?>%" role="img" aria-label="How the photo looks when the page is shared"></div>
<?php elseif ($m['kind'] === 'video'): ?>
    <video class="cv-preview" src="<?= e('/' . ltrim((string)$m['path'], '/')) ?>" controls muted playsinline preload="metadata" width="320" height="320"></video>
    <p class="field__hint">An MP4 video, shown as uploaded. Pathways, Certificate video can show it on the pathway page, the homepage and the certificate lookup page.</p>
    <p class="mono wrap-any"><?= e('/' . ltrim((string)$m['path'], '/')) ?></p>
<?php else: ?>
    <p><a class="btn btn--line" href="<?= e('/' . ltrim((string)$m['path'], '/')) ?>" target="_blank" rel="noopener">Open the PDF</a></p>
    <p class="mono wrap-any"><?= e('/' . ltrim((string)$m['path'], '/')) ?></p>
<?php endif; ?>
  </div>

  <div class="card">
<?php if ($img): ?>
    <?php admin_partial('field', ['f' => ['key' => 'alt', 'label' => 'Alt text', 'type' => 'textarea', 'rows' => 2, 'max' => 300, 'hint' => 'What the photo shows, for people using screen readers. Required before the photo goes on a page. Never name a child.'], 'value' => (string)$m['alt'], 'error' => null]); ?>
<?php elseif ($m['kind'] === 'video'): ?>
    <?php admin_partial('field', ['f' => ['key' => 'alt', 'label' => 'What the video shows', 'type' => 'textarea', 'rows' => 2, 'max' => 300, 'hint' => 'A short description for the media library. On the site the caption beside the video carries its meaning. Never name a child.'], 'value' => (string)$m['alt'], 'error' => null]); ?>
<?php endif; ?>
    <?php admin_partial('field', ['f' => ['key' => 'caption', 'label' => $img ? 'Caption' : 'Title', 'type' => 'text', 'max' => 300], 'value' => (string)$m['caption'], 'error' => null]); ?>
    <?php admin_partial('field', ['f' => ['key' => 'credit', 'label' => 'Credit', 'type' => 'text', 'max' => 160, 'hint' => 'Optional, for example the photographer.'], 'value' => (string)$m['credit'], 'error' => null]); ?>
<?php if ($img): ?>
    <fieldset class="field">
      <legend class="field__label">What kind of image is it?</legend>
      <label class="check"><input type="radio" name="kind_flag" value="real" data-flag-real<?= (int)$m['is_real_photo'] === 1 ? ' checked' : '' ?>><span>A real photo of our work</span></label>
      <label class="check"><input type="radio" name="kind_flag" value="ai" data-flag-ai<?= (int)$m['is_ai_generated'] === 1 ? ' checked' : '' ?>><span>An illustration, AI image or graphic</span></label>
      <label class="check"><input type="radio" name="kind_flag" value=""<?= (int)$m['is_real_photo'] !== 1 && (int)$m['is_ai_generated'] !== 1 ? ' checked' : '' ?>><span>Not sure</span></label>
      <input type="hidden" name="is_real_photo" value="<?= (int)$m['is_real_photo'] ?>" data-flag-real-v>
      <input type="hidden" name="is_ai_generated" value="<?= (int)$m['is_ai_generated'] ?>" data-flag-ai-v>
    </fieldset>
<?php endif; ?>
    <div class="actions actions--sticky"><button class="btn btn--primary" type="submit">Save</button></div>
  </div>
</form>

<section class="card" aria-labelledby="use-h">
  <h2 id="use-h">Where it is used</h2>
<?php if (!$uses): ?>
  <p>Not used anywhere.</p>
  <form method="post" action="/admin/media/delete" data-confirm="Delete this item and its files? This cannot be undone."><?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn--danger" type="submit">Delete</button></form>
<?php else: ?>
  <ul class="plain-list">
<?php foreach ($uses as $u): ?>
    <li><?= e($u['label']) ?>: <?php if ($u['id'] > 0): ?><a href="<?= e(admin_url($u['route'], ['id' => $u['id']])) ?>"><?= e($u['title'] !== '' ? $u['title'] : '#' . $u['id']) ?></a><?php else: ?><a href="<?= e(admin_url($u['route'])) ?>"><?= e($u['title']) ?></a><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
  <p class="muted small">It cannot be deleted while it is in use.</p>
<?php endif; ?>
</section>
