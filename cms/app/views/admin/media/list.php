<?php
/**
 * Media library grid (spec 4.2.13). Owner: W7, then WP-A (round 2: the "video" kind, MP4 only).
 * @var array $f   q, filter, kind, page
 * @var array $pg  Db::paginate result
 * @var array $used media ids in use
 * @var int $limit upload limit in MB
 */
defined('ICM') or exit;
$filters = ['' => 'All', 'real' => 'Real photos', 'ai' => 'AI images', 'noalt' => 'No alt text', 'unused' => 'Unused'];
$kinds = ['pdf' => 'PDFs', 'video' => 'Videos'];
?>
<div class="page-head page-head--row">
  <h1>Media</h1>
</div>

<section class="card upload" data-upload data-reload aria-labelledby="up-h">
  <h2 id="up-h" class="sr-only">Upload</h2>
  <label class="drop">
    <input type="file" name="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,.mp4" data-upload-input>
    <span class="drop__big">Add photos, PDFs or videos</span>
    <span class="drop__small">JPEG, PNG or WebP up to 12 MB (<?= (int)$limit ?> MB on this server), PDFs up to 10 MB, MP4 videos up to 8 MB. Several at once is fine.</span>
  </label>
  <ul class="uploads" data-upload-list aria-live="polite"></ul>
</section>

<form method="get" action="/admin/media" class="toolbar" role="search">
  <div class="field field--grow">
    <label class="sr-only" for="q">Search media</label>
    <input type="search" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Search alt text, captions or file names" enterkeyhint="search">
  </div>
  <input type="hidden" name="filter" value="<?= e($f['filter']) ?>">
  <button class="btn btn--line" type="submit">Search</button>
</form>
<nav class="chips" aria-label="Filters">
<?php foreach ($filters as $k => $label): ?>
  <a class="chip<?= $f['filter'] === $k && !isset($kinds[$f['kind']]) ? ' is-on' : '' ?>" href="<?= e(admin_url('media', ['filter' => $k, 'q' => $f['q']])) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
<?php foreach ($kinds as $k => $label): ?>
  <a class="chip<?= $f['kind'] === $k ? ' is-on' : '' ?>" href="<?= e(admin_url('media', ['kind' => $k, 'q' => $f['q']])) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
</nav>

<?php if (!$pg['rows']): ?>
<p class="muted">Nothing here yet.</p>
<?php else: ?>
<p class="muted small"><?= number_format((int)$pg['total']) ?> <?= $pg['total'] === 1 ? 'item' : 'items' ?></p>
<ul class="media-grid">
<?php foreach ($pg['rows'] as $m): $img = $m['kind'] === 'image'; ?>
  <li class="media-tile">
    <a href="<?= e(admin_url('media/edit', ['id' => (int)$m['id']])) ?>">
<?php if ($img): ?>
      <img src="<?= e(mediaUrl($m, 'thumb')) ?>" alt="" width="160" height="160" loading="lazy" decoding="async">
<?php else: ?>
      <span class="media-tile__doc" aria-hidden="true"><?= $m['kind'] === 'video' ? 'Video' : 'PDF' ?></span>
<?php endif; ?>
      <span class="media-tile__name"><?= e((string)($m['alt'] ?: ($m['caption'] ?: ($m['original_name'] ?: basename((string)$m['path']))))) ?></span>
    </a>
    <span class="media-tile__tags">
<?php if ($img && trim((string)$m['alt']) === ''): ?><span class="tag tag--warn">No alt text</span><?php endif; ?>
<?php if (!empty($m['is_ai_generated'])): ?><span class="tag">AI</span><?php endif; ?>
<?php if (!empty($m['is_real_photo'])): ?><span class="tag">Real</span><?php endif; ?>
<?php if (!isset($used[(int)$m['id']])): ?><span class="tag">Unused</span><?php endif; ?>
    </span>
  </li>
<?php endforeach; ?>
</ul>
<?php admin_partial('pager', ['pg' => $pg, 'route' => 'media', 'query' => ['q' => $f['q'], 'filter' => $f['filter'], 'kind' => $f['kind']]]); ?>
<?php endif; ?>
