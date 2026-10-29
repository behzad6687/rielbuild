<?php
/**
 * One blog post: breadcrumbs, the H1, date, category and reading time, the photo, "In this article",
 * the body, the matching service (internal link), the estimate box and related articles.
 *
 * @var View $view
 * @var array $post
 */
defined('ICM') or exit;

$p = $post;
$path = Content::postPath($p);
$cats = Content::postCategories();
$date = Content::postDate($p);
$m = !empty($p['featured_media_id']) ? Content::media((int)$p['featured_media_id']) : null;
$svc = null;
foreach (Content::services() as $s) if ($s['slug'] === $p['category']) $svc = $s;

$view->seo([
    'title'       => (string)(($p['seo_title'] ?? '') ?: $p['title']),
    'description' => (string)(($p['meta_description'] ?? '') ?: (($p['excerpt'] ?? '') ?: excerpt((string)$p['body_html'], 160))),
    'canonical'   => $path,
    'type'        => 'article',
    'published'   => $p['published_at'],
    'modified'    => ($p['updated_at'] ?? '') ?: $p['published_at'],
    'image'       => $m ? (int)$m['id'] : null,
    'breadcrumbs' => [['Home', '/'], ['Blog', '/blog/'], [(string)$p['title'], $path]],
]);
$view->schema(Seo::article($p));
$view->bodyClass('page-post');

$toc = [];
$body = Content::anchorHeadings(Content::prose((string)$p['body_html']), $toc);
$related = Content::relatedPosts(['exclude' => (int)$p['id'], 'category' => (string)$p['category'], 'tags' => (string)($p['tags'] ?? ''), 'limit' => 3]);
$gallery = Content::postGallery((int)$p['id']);
?>
<article class="post">
  <header class="page-hero page-hero--post">
    <div class="page-hero__bg" style="background-image:url('<?= e(Content::imgUrl($m, 'stills/planning', 1600)) ?>')" aria-hidden="true"></div>
    <div class="wrap">
      <?= Content::crumbs($view) ?>
      <p class="kicker kicker--light" style="margin-top:26px"><?= e($cats[$p['category']] ?? 'Blog') ?></p>
      <h1 class="h1"><?= e((string)$p['title']) ?></h1>
      <p class="post__meta">
        <?php if ($date !== ''): ?><time datetime="<?= e($date) ?>"><?= e(formatDate($date)) ?></time><?php endif; ?>
        <span><?= Content::readMinutes((string)$p['body_html']) ?> min read</span>
        <?php if (trim((string)$p['author_name']) !== ''): ?><span>By <?= e((string)$p['author_name']) ?></span><?php endif; ?>
      </p>
    </div>
  </header>

  <div class="sec">
    <div class="wrap post__grid">
      <div class="post__main">
        <?php if ($m): ?><figure class="post__figure"><?= Content::img($m, '', (string)(($p['featured_alt'] ?? '') ?: ($m['alt'] ?? '')), ['eager' => true, 'sizes' => '(max-width: 900px) 100vw, 760px']) ?></figure><?php endif; ?>
        <?php if (($p['excerpt'] ?? '') !== ''): ?><p class="lede post__lede"><?= e((string)$p['excerpt']) ?></p><?php endif; ?>
        <div class="prose post__body"><?= $body ?></div>
        <?php if ($gallery): ?>
        <div class="gal gal--all post__gallery">
          <?php foreach ($gallery as $g): ?><figure class="gal__item"><?= Content::img($g, '', (string)($g['alt'] ?? '')) ?><?php if ($g['gallery_caption']): ?><figcaption><b><?= e($g['gallery_caption']) ?></b></figcaption><?php endif; ?></figure><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (trim((string)($p['tags'] ?? '')) !== ''): ?>
        <p class="post__tags"><?php foreach (array_filter(array_map('trim', explode(',', (string)$p['tags']))) as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></p>
        <?php endif; ?>
      </div>
      <aside class="post__side">
        <?php if (count($toc) >= 2): ?>
        <nav class="post__toc" aria-label="In this article">
          <p class="kicker">In this article</p>
          <ol><?php foreach ($toc as [$id, $text]): ?><li><a href="#<?= e($id) ?>"><?= e($text) ?></a></li><?php endforeach; ?></ol>
        </nav>
        <?php endif; ?>
        <div class="post__box">
          <?php if ($svc): ?>
          <p class="kicker">Our service</p>
          <h2 class="h3"><a href="<?= e($svc['path']) ?>"><?= e($svc['name']) ?></a></h2>
          <p><?= e($svc['tag']) ?></p>
          <p><a class="tlink" href="<?= e($svc['path']) ?>">How we do it <?= Content::ico('arrow') ?></a></p>
          <?php else: ?>
          <p class="kicker">Planning a renovation?</p>
          <h2 class="h3">Get an honest number first.</h2>
          <?php endif; ?>
          <a class="btn btn--brass btn--block" href="/contact/<?= $svc ? '?service=' . e(rawurlencode($svc['slug'])) : '' ?>"><?= e(site('form.heading', 'Book a free estimate')) ?></a>
        </div>
      </aside>
    </div>
  </div>
</article>

<?php if ($related): ?>
<section class="sec sec--tight sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal"><p class="kicker">Keep reading</p><h2 class="h2" style="margin-top:16px">More from the blog.</h2><?= Content::dimline() ?></div>
    <div class="post-grid stagger"><?php foreach ($related as $r) $view->partial('post-card', ['post' => $r]); ?></div>
    <p style="margin-top:26px"><a class="tlink" href="/blog/">All articles <?= Content::ico('arrow') ?></a></p>
  </div>
</section>
<?php endif; ?>
<?php $view->partial('cta', ['ctaImg' => Content::imgUrl($m, 'hero-ending', 1600), 'service' => $svc['slug'] ?? null]); ?>
