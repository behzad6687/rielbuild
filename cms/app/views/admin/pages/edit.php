<?php
/**
 * Page editor (spec 4.2.13). Vars: $row, $values, $blocks, $errors, $warnings, $fields, $data, $isOwner,
 * $audit, $preview, $shareUrl, $parents, $locations, $shared, $courses.
 */
defined('ICM') or exit;

$v = $values;
$id = (int)$row['id'];
$isHome = $row['path'] === '/';
$blockTypes = ContentAdmin::BLOCK_TYPES;
if ($row['template'] !== 'home') unset($blockTypes['hero_band']);

echo AdminUi::head((string)$row['title'], 'Template: ' . ucfirst(str_replace('_', ' ', (string)$row['template'])) . '. Last saved ' . AdminKit::stamp((string)$row['updated_at']) . '.',
    AdminUi::link('Preview', $preview, 'btn btn--line', ['target' => '_blank', 'rel' => 'noopener'])
    . ($row['status'] === 'published' ? AdminUi::link('View on the site', (string)$row['path'], 'btn btn--line', ['target' => '_blank', 'rel' => 'noopener']) : '')
    . AdminUi::link('Back to pages', AdminUi::url('pages')));
echo AdminUi::errors($errors);
?>
<form method="post" class="ed-form" enctype="multipart/form-data" action="<?= e(AdminUi::url('pages/edit', ['id' => $id])) ?>">
  <?= AdminUi::csrf() ?>
  <?= AdminKit::lockField($row) ?>
  <?= AdminUi::lint($warnings) ?>
  <div class="ed-split">
    <div class="ed-main">
      <section class="card" aria-labelledby="content-h">
        <h2 id="content-h">Content</h2>
        <div class="ed-row">
          <?= AdminUi::input('title', 'Page name', $v['title'], ['max' => 160, 'required' => true, 'hint' => 'Used in the admin, menus and breadcrumbs.']) ?>
          <?= AdminUi::input('h1', 'Main heading (H1)', $v['h1'], ['max' => 200, 'hint' => $isHome ? 'Read by search engines and screen readers; the scroll film shows the captions below.' : 'Empty uses the page name. Wrap words in *stars* to show them in orange.']) ?>
        </div>
        <?php if (!$isHome): ?>
        <?= AdminUi::textarea('intro_html', 'Introduction (under the main heading)', $v['intro_html'], ['rich' => true, 'rte' => 'basic', 'rows' => 4]) ?>
        <?= AdminUi::textarea('body_html', $row['template'] === 'about' ? 'Who we are: the longer text' : 'Body (optional extra text)', $v['body_html'], ['rich' => true, 'rows' => 10, 'hint' => 'Use H2 for sections. Everything is cleaned when you save.']) ?>
        <?php endif; ?>
      </section>

      <?php if ($fields || in_array($row['template'], ContentAdmin::COURSE_TEMPLATES, true) || $isOwner): ?>
        <section class="card" aria-labelledby="tpl-h">
          <h2 id="tpl-h">Page wording and images</h2>
          <p class="muted small">Each part of this page. Leaving a line empty hides it on the page.</p>
          <?php if (in_array($row['template'], ContentAdmin::COURSE_TEMPLATES, true)): ?>
            <?= AdminUi::select('course_id', 'Course shown on this page', $courses, $v['course_id'] ?? '', ['blank' => 'None', 'hint' => 'Its status, price and registration link show here.']) ?>
          <?php endif; ?>
          <?php foreach ($fields as $f):
              $k = $f['key'];
              $name = 'data[' . $k . ']';
              switch ($f['type']):
                  case 'heading': echo '<h3 class="ed-subhead">' . e($f['label']) . '</h3>' . (!empty($f['hint']) ? '<p class="field__hint">' . e($f['hint']) . '</p>' : ''); break;
                  case 'line': echo AdminUi::input($name, $f['label'], (string)($data[$k] ?? ''), ['max' => 300, 'hint' => $f['hint'] ?? '']); break;
                  case 'location': echo AdminUi::select($name, $f['label'], $locations, $data[$k] ?? '', ['blank' => 'None', 'hint' => $f['hint'] ?? '']); break;
                  case 'select': echo AdminUi::select($name, $f['label'], $f['options'], $data[$k] ?? ''); break;
                  case 'check': echo AdminUi::check($name, $f['label'], !empty($data[$k])); break;
                  case 'offswitch': echo AdminUi::check($name, $f['label'], ($data[$k] ?? true) !== false); break;
                  case 'codes': echo AdminUi::input($name, $f['label'], implode(', ', array_map('strval', (array)($data[$k] ?? []))), ['max' => 400, 'hint' => $f['hint'] ?? '']); break;
                  case 'path': echo AdminUi::input($name, $f['label'], $data[$k] ?? '', ['max' => 255, 'hint' => $f['hint'] ?? '']); break;
                  case 'text': echo AdminUi::textarea($name, $f['label'], (string)($data[$k] ?? ''), ['rows' => 3, 'max' => 2000, 'hint' => $f['hint'] ?? '']); break;
                  case 'lines': echo AdminUi::textarea($name, $f['label'], implode("\n", array_map('strval', (array)($data[$k] ?? []))), ['rows' => 6, 'hint' => $f['hint'] ?? '']); break;
                  case 'image': echo AdminUi::imageField('tplimg_' . $k, !empty($data['media'][$k]) ? (int)$data['media'][$k] : null, null, ['label' => $f['label'], 'hint' => $f['hint'] ?? '']); break;
                  case 'pdf':
                      $pdf = !empty($data['media'][$k]) ? mediaRow((int)$data['media'][$k]) : null; ?>
                      <div class="field"><span class="field__label"><?= e($f['label']) ?></span>
                        <?php if ($pdf && ($pdf['kind'] ?? '') === 'pdf'): ?><p>Now: <a href="<?= e(mediaUrl($pdf)) ?>" target="_blank" rel="noopener"><?= e((string)($pdf['original_name'] ?: basename((string)$pdf['path']))) ?></a> (<?= e(number_format((int)$pdf['bytes'] / 1048576, 1)) ?> MB)</p>
                        <?= AdminUi::check('tplpdf_remove[' . $k . ']', 'Remove the PDF', false) ?><?php endif; ?>
                        <input type="file" name="tplpdf_<?= e($k) ?>" accept="application/pdf,.pdf" aria-label="<?= e($f['label']) ?>">
                        <p class="field__hint"><?= $pdf ? 'Choose a new PDF to replace it.' : 'Choose the PDF.' ?> 10 MB at most.</p>
                      </div>
                      <?php break;
                  case 'shared':
                      $chosen = array_map('strval', (array)($data[$k] ?? []));
                      $ordered = array_merge(array_intersect_key(array_flip($chosen), $shared) ? array_values(array_intersect($chosen, array_keys($shared))) : [], array_values(array_diff(array_keys($shared), $chosen))); ?>
                      <fieldset class="field"><legend class="field__label"><?= e($f['label']) ?></legend>
                        <?php foreach ($ordered as $sk): ?>
                          <?= AdminUi::check($name . '[]', $shared[$sk] . ' (' . $sk . ')', in_array($sk, $chosen, true), ['value' => $sk]) ?>
                        <?php endforeach; ?>
                        <?php if (!$shared): ?><p class="muted">No shared blocks yet.</p><?php endif; ?>
                      </fieldset>
                      <?php break;
              endswitch;
          endforeach; ?>
          <?php if ($isOwner): $raw = $data ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''; ?>
            <details class="details">
              <summary>Advanced: template data (JSON, owner only)</summary>
              <input type="hidden" name="data_raw_before" value="<?= e($raw) ?>">
              <?= AdminUi::textarea('data_raw', 'Template data', $raw, ['rows' => 8, 'hint' => 'Image slots such as {"media": {"header": 12}} live here. A change here replaces the fields above.']) ?>
            </details>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="card" id="blocks" aria-labelledby="blocks-h">
        <h2 id="blocks-h">Blocks</h2>
        <p class="muted small">Sections of the page in order. Use the arrows to reorder and "Shown" to hide one without deleting it. Save the page to keep changes.</p>
        <?php if (!$blocks): ?><p class="muted">No blocks on this page.</p><?php endif; ?>
        <div class="blocks">
          <?php foreach ($blocks as $b): $bid = (int)$b['id']; $n = 'blocks[' . $bid . ']'; $bd = json_decode((string)($b['data_json'] ?? ''), true) ?: []; ?>
            <div class="block-row<?= (int)$b['is_visible'] === 1 ? '' : ' is-hidden' ?>" id="block-<?= $bid ?>" data-rep-row>
              <div class="block-row__head">
                <span class="tag"><?= e(ContentAdmin::BLOCK_TYPES[$b['type']] ?? $b['type']) ?></span>
                <?php if ($b['block_key']): ?><span class="mono small muted"><?= e($b['block_key']) ?></span><?php endif; ?>
                <?php if ($b['type'] === 'hero_band' && $b['max_chars']): ?><span class="tag tag--warn"><?= (int)$b['max_chars'] ?> characters at most for the two lines</span><?php endif; ?>
              </div>
              <?php if ($b['type'] === 'hero_band'):
                  // Round 2 (WP-A): the kicker and the two lines of one band of the homepage film, each with its limit.
                  $lim = Content::HERO_BANDS[(string)$b['block_key']] ?? [null, null, null, 40, 60, 60];
                  $lines = Content::bandLines($b); ?>
                <?= AdminUi::input($n . '[title]', 'Kicker (the small label above the lines)', $b['title'], ['max' => (int)$lim[3], 'count' => '0,' . (int)$lim[3]]) ?>
                <?= AdminUi::input($n . '[line1]', 'Line 1', $lines[0] ?? '', ['max' => (int)$lim[4], 'count' => '0,' . (int)$lim[4], 'hint' => 'The big line. On phones only the kicker and this line show.']) ?>
                <?= AdminUi::input($n . '[line2]', 'Line 2', $lines[1] ?? '', ['max' => (int)$lim[5], 'count' => '0,' . (int)$lim[5], 'hint' => 'Short lines keep the scroll film readable. An empty field shows the site\'s own wording.']) ?>
              <?php else: ?>
              <?= AdminUi::input($n . '[title]', $b['type'] === 'cta' ? 'Button label' : ($b['type'] === 'image' ? 'Caption' : 'Title'), $b['title'], ['max' => 200]) ?>
              <?php endif; ?>
              <?php if (in_array($b['type'], ['rich', 'card', 'accordion_item', 'table'], true)): ?>
                <?= AdminUi::textarea($n . '[body]', 'Text', $b['body_html'], ['rich' => true, 'rte' => $b['type'] === 'table' ? 'table' : 'basic', 'rows' => 6]) ?>
              <?php elseif ($b['type'] === 'hero_band'): ?>
              <?php elseif ($b['type'] === 'cta'): ?>
                <?= AdminUi::input($n . '[path]', 'Links to', $bd['path'] ?? '', ['max' => 255, 'placeholder' => '/all-online-registrations/']) ?>
              <?php elseif ($b['type'] === 'image'): ?>
                <?= AdminUi::input($n . '[media_id]', 'Image number from the media library', $bd['media_id'] ?? '', ['type' => 'number', 'min' => 1, 'class' => 'field--small',
                    'hint' => !empty($bd['media_id']) && ($th = AdminKit::thumb((int)$bd['media_id'])) ? 'Current image shown on the right.' : 'Upload it in Media first, then enter its number.']) ?>
                <?php if (!empty($bd['media_id']) && ($th = AdminKit::thumb((int)$bd['media_id']))): ?><img src="<?= e($th) ?>" alt="" width="120" height="120"><?php endif; ?>
              <?php elseif ($b['type'] === 'shared_ref'): ?>
                <?= AdminUi::select($n . '[key]', 'Shared block', $shared, $bd['key'] ?? '', ['hint' => 'Its text comes from Shared blocks; editing it there updates every page.']) ?>
              <?php endif; ?>
              <?= AdminUi::check($n . '[visible]', 'Shown', (int)$b['is_visible'] === 1) ?>
              <div class="rep-tools">
                <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-up aria-label="Move this block up">↑</button>
                <button type="button" class="btn btn--line btn--sm btn--icon" data-rep-down aria-label="Move this block down">↓</button>
                <?php if (!$b['block_key']): ?><button type="submit" class="btn btn--danger btn--sm" form="block-del-<?= $bid ?>">Delete</button><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <aside class="ed-side" aria-label="Publishing and search">
      <section class="card" aria-labelledby="pub-h">
        <h2 id="pub-h">Publishing</h2>
        <?php if ($isHome): ?>
          <p><?= AdminKit::chip('published') ?> The home page is always published.</p>
        <?php else: ?>
          <?= AdminUi::select('status', 'Status', ContentAdmin::PAGE_STATUSES, $v['status'], ['hint' => 'A draft answers "not found" to visitors; the preview still works.']) ?>
        <?php endif; ?>
        <?= AdminUi::select('parent_id', 'Breadcrumb parent', $parents, $v['parent_id'] ?? '', ['blank' => 'None (under Home)']) ?>
        <?php if (!$isHome): ?>
          <div class="field">
            <label class="field__label" for="page-path">Web address</label>
            <input type="text" id="page-path" name="path" value="<?= e($row['path']) ?>" maxlength="191" data-lock-until="page-change-path" autocapitalize="none" spellcheck="false">
          </div>
          <?= AdminUi::check('change_path', 'Change web address', false, ['id' => 'page-change-path', 'hint' => 'The old address will redirect to the new one, and menus follow.']) ?>
        <?php endif; ?>
        <p class="field__hint">Share a preview for 7 days (no account needed):</p>
        <p><input type="text" readonly value="<?= e($shareUrl) ?>" aria-label="Preview link for reviewers" class="mono"></p>
      </section>

      <section class="card" aria-labelledby="search-h">
        <h2 id="search-h">Search</h2>
        <?= AdminUi::seoFields('seo_title', $v['seo_title'], 'meta_description', $v['meta_description']) ?>
        <?= AdminUi::select('robots', 'Search engines', ContentAdmin::ROBOTS, $v['robots']) ?>
        <?= AdminUi::check('in_sitemap', 'List in the sitemap', (int)$v['in_sitemap'] === 1) ?>
      </section>
      <?= AdminUi::seoPanel($audit, url((string)$row['path']), (string)$v['meta_description']) ?>
      <section class="card" aria-label="Social image">
        <?= AdminUi::imageField('og', $v['og_media_id'] ? (int)$v['og_media_id'] : null, null, ['label' => 'Social image (optional)', 'og' => true,
            'hint' => 'Shown when the page is shared. Empty uses the site default.']) ?>
      </section>
    </aside>
  </div>
  <?= AdminUi::sticky('<button class="btn btn--primary" type="submit">Save page</button>' . AdminUi::link('Cancel', AdminUi::url('pages'), 'btn btn--line')) ?>
</form>

<?php foreach ($blocks as $b): if ($b['block_key']) continue; ?>
  <form id="block-del-<?= (int)$b['id'] ?>" method="post" action="<?= e(AdminUi::url('pages/block-delete')) ?>" hidden data-confirm="Delete this block? This cannot be undone.">
    <?= AdminUi::csrf() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
  </form>
<?php endforeach; ?>

<section class="card" aria-labelledby="addb-h">
  <h2 id="addb-h">Add a block</h2>
  <form class="form form--inline" method="post" action="<?= e(AdminUi::url('pages/block-add')) ?>">
    <?= AdminUi::csrf() ?><input type="hidden" name="page_id" value="<?= $id ?>">
    <?= AdminUi::select('type', 'Kind', $blockTypes, 'rich') ?>
    <?= AdminUi::input('title', 'Title (optional)', '', ['max' => 200]) ?>
    <div class="field"><button class="btn btn--line" type="submit">Add block</button></div>
  </form>
  <p class="field__hint">Save your other changes first; adding a block reloads the page.</p>
</section>

<?php if ($row['status'] === 'draft' && !$isHome): ?>
  <div class="ed-danger">
    <?= AdminUi::postButton('pages/delete', [], ['id' => $id], 'Delete this draft page', ['class' => 'btn btn--danger btn--sm', 'confirm' => 'Delete the draft ' . $row['path'] . '? This cannot be undone.']) ?>
  </div>
<?php endif; ?>
