<?php
/**
 * An image field for any edit screen (spec 4.1.3). Owner: W7; used by W7b modules.
 *   admin_partial('image-field', [
 *       'name'     => 'featured_media_id',        // hidden input with the media id
 *       'media'    => $mediaRowOrNull,
 *       'label'    => 'Featured image',
 *       'alt_name' => 'featured_alt',             // optional: the row's own alt column;
 *       'alt'      => $row['featured_alt'] ?? '', //   without it, the alt is saved on the media item
 *       'require_alt' => true,                    // refuse the form while an image has no alt
 *       'social'   => true,                       // show the 1200x630 social crop
 *   ]);
 * The file input accepts JPEG, PNG and WebP (iOS converts HEIC to JPEG); a HEIC that
 * still arrives is refused with the plain message. One file per request.
 */
defined('ICM') or exit;

$m = $media ?? null;
$j = $m ? Media::toJson($m) : null;
$id = 'img-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$name));
$altName = $alt_name ?? null;
$altValue = $altName ? (string)($alt ?? '') : (string)($m['alt'] ?? '');
$fx = $m ? (float)$m['focal_x'] : 0.5;
$fy = $m ? (float)$m['focal_y'] : 0.4;
?>
<div class="imgfield field" data-image-field<?= !empty($require_alt) ? ' data-require-alt' : '' ?><?= $altName ? '' : ' data-alt-on-media' ?>>
  <p class="field__label" id="<?= e($id) ?>-l"><?= e($label ?? 'Image') ?></p>
  <input type="hidden" name="<?= e((string)$name) ?>" value="<?= $m ? (int)$m['id'] : '' ?>" data-image-id>
  <div class="imgfield__body">
    <div class="focal<?= $m ? '' : ' is-empty' ?>" data-focal data-media-focal data-x="<?= e((string)$fx) ?>" data-y="<?= e((string)$fy) ?>">
      <img src="<?= $j ? e($j['preview']) : '' ?>" alt="" data-image-preview<?= $j ? '' : ' hidden' ?>>
      <button type="button" class="focal__dot" style="left:<?= round($fx * 100, 1) ?>%;top:<?= round($fy * 100, 1) ?>%" aria-label="Focal point"<?= $m ? '' : ' hidden' ?>></button>
      <span class="imgfield__empty"<?= $m ? ' hidden' : '' ?>>No image</span>
    </div>
<?php if (!empty($social)): ?>
    <div class="ogcrop" data-ogcrop<?= $j ? ' style="background-image:url(\'' . e($j['preview']) . '\');background-position:' . round($fx * 100, 1) . '% ' . round($fy * 100, 1) . '%"' : ' hidden' ?> role="img" aria-label="The social crop, 1200 by 630"></div>
<?php endif; ?>
  </div>
  <div class="btn-row">
    <label class="btn btn--line btn--sm">Upload<input type="file" accept="image/jpeg,image/png,image/webp" data-image-upload class="sr-only"></label>
    <button type="button" class="btn btn--line btn--sm" data-image-pick>Choose from the library</button>
    <button type="button" class="btn btn--line btn--sm" data-image-clear<?= $m ? '' : ' hidden' ?>>Remove</button>
  </div>
  <p class="field__hint">Tap the photo to set its focal point.</p>
  <label class="field__label" for="<?= e($id) ?>-alt">Alt text<?= !empty($require_alt) ? ' <span class="req" aria-hidden="true">*</span>' : '' ?></label>
  <input type="text" id="<?= e($id) ?>-alt" maxlength="300" value="<?= e($altValue) ?>" data-image-alt<?= $altName ? ' name="' . e((string)$altName) . '"' : '' ?> aria-describedby="<?= e($id) ?>-alth">
  <span class="field__hint" id="<?= e($id) ?>-alth">Describe what the photo shows. Required before publishing. Never name a child.</span>
  <span class="field__error" data-image-error hidden></span>
</div>
