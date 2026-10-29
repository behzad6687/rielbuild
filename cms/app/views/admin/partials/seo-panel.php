<?php
/**
 * The SEO check panel (spec 4.2.2, 4.2.13). Owner: W7; used by the posts and pages editors.
 *   admin_partial('seo-panel', ['audit' => SeoScore::forPost($post), 'url' => url('/blog/' . $slug . '/'),
 *                               'title_field' => 'f-seo-title', 'meta_field' => 'f-meta-description']);
 * The Google preview updates live from the two fields (admin.js, data-serp).
 */
defined('ICM') or exit;
$score = (int)($audit['score'] ?? 0);
?>
<section class="card seo" aria-labelledby="seo-h" data-serp<?= !empty($title_field) ? ' data-serp-title="' . e($title_field) . '"' : '' ?><?= !empty($meta_field) ? ' data-serp-meta="' . e($meta_field) . '"' : '' ?>>
  <h2 id="seo-h">Search appearance</h2>
  <div class="serp" aria-label="Google result preview">
    <span class="serp__url"><?= e((string)($url ?? '')) ?></span>
    <span class="serp__title" data-serp-title-out><?= e((string)($audit['title'] ?? '')) ?></span>
    <span class="serp__desc" data-serp-meta-out><?= e((string)($meta ?? '')) ?></span>
  </div>
  <div class="meter" role="img" aria-label="SEO check <?= $score ?> out of 100">
    <span class="meter__bar"><i style="width:<?= $score ?>%"></i></span><span class="meter__v"><?= $score ?></span>
  </div>
  <ul class="checks">
<?php foreach ((array)($audit['checks'] ?? []) as $c): ?>
    <li class="<?= $c['ok'] ? 'is-ok' : 'is-no' ?>">
      <span class="checks__mark" aria-hidden="true"><?= $c['ok'] ? '&#10003;' : '!' ?></span>
      <span><?= e($c['label']) ?><?php if (!$c['ok'] && $c['hint'] !== ''): ?><small><?= e($c['hint']) ?></small><?php endif; ?></span>
      <span class="sr-only"><?= $c['ok'] ? 'passes' : 'needs work' ?></span>
    </li>
<?php endforeach; ?>
  </ul>
</section>
