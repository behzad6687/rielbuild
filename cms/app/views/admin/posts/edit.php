<?php
/**
 * News post editor (spec 4.2.2). Vars: $row, $values, $errors, $warnings, $gallery, $campaign, $audit, $me.
 */
defined('ICM') or exit;

$isNew = !$row;
$v = $values;
$live = $row && in_array($row['status'], ['published', 'scheduled'], true);
$titleId = 'post-title';
$slugId = 'post-slug';
$changeId = 'post-change-slug';

$actions = AdminUi::link('Back to posts', AdminUi::url('posts'));
if ($row) {
    $actions = AdminUi::link('Preview', AdminUi::url('preview/post', ['id' => $row['id']]), 'btn btn--line', ['target' => '_blank', 'rel' => 'noopener']) . $actions;
    if (ContentAdmin::postLive($row)) {
        $actions = AdminUi::link('View on the site', ContentAdmin::postPath((string)$row['slug']), 'btn btn--line', ['target' => '_blank', 'rel' => 'noopener']) . $actions;
    }
}
echo AdminUi::head($isNew ? 'Write a post' : 'Edit post', $row ? 'Last saved ' . AdminKit::stamp((string)$row['updated_at']) . '.' : '', $actions);
echo AdminUi::errors($errors);
?>
<form method="post" class="ed-form" enctype="multipart/form-data" action="<?= e(AdminUi::url('posts/edit', $isNew ? [] : ['id' => $row['id']])) ?>">
  <?= AdminUi::csrf() ?>
  <?= $row ? AdminKit::lockField($row) : '' ?>
  <?= AdminUi::lint($warnings) ?>

  <div class="ed-split">
    <div class="ed-main">
      <div class="card">
        <?= AdminUi::input('title', 'Headline', $v['title'], ['id' => $titleId, 'max' => 250, 'required' => true]) ?>

        <div class="field slug-field">
          <label for="<?= $slugId ?>">Web address</label>
          <div class="slug-row"><span class="slug-prefix" aria-hidden="true">/blog/</span>
            <input type="text" id="<?= $slugId ?>" name="slug" value="<?= e($v['slug']) ?>" maxlength="180" pattern="[a-z0-9-]*"
              aria-describedby="<?= $slugId ?>-h"<?= $live ? ' data-lock-until="' . $changeId . '"' : ' data-slug-from="' . $titleId . '"' ?>><span class="slug-suffix" aria-hidden="true">/</span></div>
          <span class="field__hint" id="<?= $slugId ?>-h">Full address: <span class="mono">/blog/<span data-slug-out="<?= $slugId ?>"><?= e($v['slug'] ?: 'your-headline') ?></span>/</span>.
            <?= $live ? '' : 'Built from the headline while this is a draft.' ?></span>
          <?php if ($live): ?>
            <?= AdminUi::check('change_slug', 'Change web address', false, ['id' => $changeId, 'hint' => 'The old address will redirect to the new one automatically, so shared links keep working.']) ?>
          <?php endif; ?>
        </div>

        <?= AdminUi::textarea('excerpt', 'Summary', $v['excerpt'], ['rows' => 3, 'max' => 400, 'count' => '0,400', 'hint' => 'Shown on the blog cards and under the headline. Keep it to one or two sentences.']) ?>
        <?= AdminUi::textarea('body_html', 'Body', $v['body_html'], ['rich' => true, 'rte' => 'post', 'rows' => 18,
            'hint' => 'Use H2 for sections. Paste keeps the words and drops the styling. Everything is cleaned when you save.']) ?>
      </div>

      <fieldset class="card gallery" data-gallery data-upload-url="<?= e(AdminUi::url('api/posts/upload')) ?>" data-name="<?= e($v['slug'] ?: 'blog') ?>">
        <legend>Gallery</legend>
        <p class="field__hint">Photos show under the post. Drag them, or use the arrows, to change the order. Each photo needs alt text.</p>
        <ol class="gallery__list" data-rep-rows>
          <?php foreach ($gallery as $g): $mid = (int)$g['media_id']; ?>
            <li class="gallery__item" data-rep-row data-id="<?= $mid ?>">
              <img src="<?= e(mediaUrl($g, 'thumb')) ?>" alt="" width="96" height="96" loading="lazy">
              <input type="hidden" name="gallery[<?= $mid ?>][keep]" value="1">
              <div class="gallery__fields">
                <?= AdminUi::input('gallery[' . $mid . '][alt]', 'Alt text', $g['alt'] ?? '', ['max' => 300]) ?>
                <?= AdminUi::input('gallery[' . $mid . '][caption]', 'Caption (optional)', $g['caption'] ?? '', ['max' => 300]) ?>
              </div>
              <div class="rep-tools">
                <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-up aria-label="Move up">↑</button>
                <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-down aria-label="Move down">↓</button>
                <button type="button" class="btn btn--danger btn--sm" data-rep-remove>Remove</button>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
        <template data-rep-template>
          <li class="gallery__item" data-rep-row data-id="__i__">
            <img src="" alt="" width="96" height="96" data-gallery-thumb>
            <input type="hidden" name="gallery[__i__][keep]" value="1">
            <div class="gallery__fields">
              <div class="field"><label>Alt text<input type="text" name="gallery[__i__][alt]" maxlength="300"></label></div>
              <div class="field"><label>Caption (optional)<input type="text" name="gallery[__i__][caption]" maxlength="300"></label></div>
            </div>
            <div class="rep-tools">
              <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-up aria-label="Move up">↑</button>
              <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-down aria-label="Move down">↓</button>
              <button type="button" class="btn btn--danger btn--sm" data-rep-remove>Remove</button>
            </div>
          </li>
        </template>
        <?= AdminUi::input('gallery_files[]', 'Add photos', '', ['type' => 'file', 'accept' => 'image/jpeg,image/png,image/webp',
            'attrs' => ['multiple' => true, 'data-gallery-input' => true], 'hint' => 'JPEG, PNG or WebP. Photos upload one at a time; captions and order save with the post.']) ?>
        <p class="gallery__status" data-gallery-status aria-live="polite"></p>
      </fieldset>

      <div class="card">
        <?= AdminUi::seoFields('seo_title', $v['seo_title'], 'meta_description', $v['meta_description']) ?>
        <?= AdminUi::input('tags', 'Tags', $v['tags'], ['max' => 255, 'hint' => 'Words separated by commas, for example: kitchen, budget, permits. Posts sharing tags are shown as related articles.']) ?>
      </div>
    </div>

    <aside class="ed-side" aria-label="Publishing">
      <section class="card" aria-labelledby="pub-h">
        <h2 id="pub-h">Publishing</h2>
        <?= AdminUi::select('status', 'Status', ContentAdmin::POST_STATUSES, $v['status']) ?>
        <?= AdminUi::input('published_at', 'Date and time (Toronto)', AdminKit::localInput($v['published_at']), ['type' => 'datetime-local',
            'hint' => 'Published posts show this date. Scheduled posts go live at this time.']) ?>
        <?= AdminUi::select('category', 'Category', Content::postCategories(), $v['category'] ?? 'tips', ['hint' => 'A service category links the post to that service page and shows it there under "From the blog".']) ?>
        <?= AdminUi::input('author_name', 'Author', $v['author_name'], ['max' => 80]) ?>
        <?= AdminUi::check('is_featured', 'Feature on the home page ("From the blog")', (int)$v['is_featured'] === 1) ?>
      </section>

      <section class="card" aria-label="Featured image">
        <?= AdminUi::imageField('featured', $v['featured_media_id'] ? (int)$v['featured_media_id'] : null, (string)($v['featured_alt'] ?? ''), [
            'label' => 'Featured image', 'og' => true, 'required_alt' => true, 'alt_label' => 'Alt text (required before publishing)',
        ]) ?>
      </section>

      <?= AdminUi::seoPanel($audit, url('/blog/' . ($v['slug'] ?: 'your-headline') . '/'), (string)$v['meta_description']) ?>

    </aside>
  </div>

  <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">Save</button>' . AdminUi::link('Cancel', AdminUi::url('posts'), 'btn btn--line')) ?>
</form>



<?php if ($row): ?>
  <div class="ed-danger">
    <?php if ($row['status'] !== 'draft'): ?>
      <?= AdminUi::postButton('posts/unpublish', [], ['id' => $row['id']], 'Move back to draft', ['class' => 'btn btn--line btn--sm', 'confirm' => 'Take this post off the site and keep it as a draft?']) ?>
    <?php elseif ($row['status'] === 'draft'): ?>
      <?= AdminUi::postButton('posts/publish', [], ['id' => $row['id'], 'back' => 'edit'], 'Publish now', ['class' => 'btn btn--primary btn--sm']) ?>
    <?php endif; ?>
    <?= AdminUi::postButton('posts/delete', [], ['id' => $row['id']], 'Delete this post', ['class' => 'btn btn--danger btn--sm',
        'confirm' => 'Delete this post for good? Its address will show the blog page instead. This cannot be undone.']) ?>
  </div>
<?php endif; ?>
