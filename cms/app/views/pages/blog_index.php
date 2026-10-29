<?php
/**
 * The blog: the newest posts first, nine a page (/blog/page/2/ ...), each card linking to its article.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 * @var int|null $pageNum
 */
defined('ICM') or exit;

$n = max(1, (int)($pageNum ?? 1));
$res = Content::newsPage($n);
$rows = $res['rows'] ?? [];
$pages = (int)($res['pages'] ?? 1);
if ($n > 1) {
    if (!$rows) Router::notFound();
    $view->seo(['title_override' => plainText($page['seo_title'] ?: $page['title']) . ', page ' . $n, 'canonical' => '/blog/page/' . $n . '/']);
}
if ($n > 1) $view->head('<link rel="prev" href="' . e(url($n === 2 ? '/blog/' : '/blog/page/' . ($n - 1) . '/')) . '">');
if ($n < $pages) $view->head('<link rel="next" href="' . e(url('/blog/page/' . ($n + 1) . '/')) . '">');
$view->schema(['@type' => 'Blog', '@id' => url('/blog/') . '#blog', 'name' => siteName() . ' blog', 'url' => url('/blog/'), 'publisher' => ['@id' => Seo::orgId()],
    'blogPost' => array_map(static fn($p) => ['@type' => 'BlogPosting', 'headline' => (string)$p['title'], 'url' => url(Content::postPath($p)), 'datePublished' => Time::iso($p['published_at'])], $rows)]);
$view->partial('page-hero', ['heroStill' => 'stills/planning']);
?>
<section class="sec">
  <div class="wrap">
    <?php if ($rows): ?>
    <div class="post-grid stagger">
      <?php foreach ($rows as $i => $p) $view->partial('post-card', ['post' => $p, 'h' => 'h2', 'eager' => $i < 2 && $n === 1]); ?>
    </div>
    <?php else: ?>
    <p class="lede">New articles are on the way. In the meantime, <a class="tlink" href="/faq/">read the questions we hear most</a>.</p>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Blog pages">
      <?php if ($n > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e($n === 2 ? '/blog/' : '/blog/page/' . ($n - 1) . '/') ?>" rel="prev">Newer articles</a><?php endif; ?>
      <span class="pager__n">Page <?= $n ?> of <?= $pages ?></span>
      <?php if ($n < $pages): ?><a class="btn btn--ghost btn--sm" href="/blog/page/<?= $n + 1 ?>/" rel="next">Older articles</a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
</section>

<section class="sec sec--tight sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal"><p class="kicker">What we build</p><h2 class="h2" style="margin-top:16px">Ready to start your own project?</h2><?= Content::dimline() ?></div>
    <?php $view->partial('services-grid', ['services' => Content::services()]); ?>
  </div>
</section>
<?php $view->partial('cta'); ?>
