<?php
/**
 * One service page: the hero with its facts, how we do it (what is included, how long it takes),
 * the way we work, the questions (this service's FAQs, then three general ones), related articles,
 * the other services and the estimate band.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$svc = Content::service((int)$page['id']) ?? ['slug' => trim(str_replace('/services/', '', (string)$page['path']), '/'), 'name' => plainText($page['title']), 'short' => plainText($page['title']), 'tag' => '', 'icon' => 'house', 'media' => null, 'still' => 'stills/home-renovation', 'lead' => ''];
$phone = Content::phone();
$photo = $svc['media'];
$faqs = array_merge(Content::faqs($svc['slug']), array_slice(Content::faqs('general'), 0, 3));
$posts = Content::relatedPosts(['category' => $svc['slug'], 'tags' => $svc['name'] . ',' . $svc['short'], 'limit' => 3]);

$view->schema(Seo::service($page, $d));
if ($faqs) $view->schema(Seo::faqPage($faqs));
if ($photo) $view->seo(['image' => (int)$photo['id']]);

$facts = '<div class="page-hero__facts"><span class="fact">' . Content::ico('doc') . 'Fixed, written quote</span><span class="fact">' . Content::ico('clock') . 'Written timeline</span><span class="fact">' . Content::ico('phone') . 'One project lead</span></div>'
    . '<div class="btn-row"><a class="btn btn--brass" href="/contact/?service=' . e(rawurlencode($svc['slug'])) . '">' . e(site('form.heading', 'Book a free estimate')) . '</a><a class="btn btn--ghost" href="' . e(telHref($phone)) . '">' . Content::ico('phone') . e($phone) . '</a></div>';
$view->partial('page-hero', [
    'heroLede'  => $svc['tag'] !== '' ? '<p>' . e($svc['tag']) . '</p>' : Content::intro($page),
    'heroImg'   => Content::imgUrl($photo, $svc['still'], 1600),
    'heroExtra' => $facts,
    'heroDot'   => true,
    'pageData'  => ['kicker' => Content::field($d, 'kicker', $svc['short'] . ' in the GTA')],
]);
$includes = Content::lines($d, 'includes');
$others = array_values(array_filter(Content::services(), static fn($o) => $o['slug'] !== $svc['slug']));
?>
<section class="sec">
  <div class="wrap split">
    <div class="prose reveal">
      <?php if (($k = Content::field($d, 'how_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2"><?= Content::accent(Content::field($d, 'how_title', 'Done right, the first time.')) ?></h2>
      <?= Content::dimline() ?>
      <?php if ($svc['lead'] !== ''): ?><p class="lede"><?= e($svc['lead']) ?></p><?php endif; ?>
      <?php if ($page['body_html']): ?><?= Content::prose((string)$page['body_html']) ?><?php endif; ?>
      <?php if ($includes): ?>
      <ul class="checks">
        <?php foreach ($includes as $inc): ?><li><?= Content::ico('check') ?><?= e($inc) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if (($time = Content::field($d, 'time')) !== ''): ?>
      <div class="note" style="margin-top:10px"><?= Content::ico('clock') ?><p><b>How long it takes.</b> <?= e($time) ?></p></div>
      <?php endif; ?>
    </div>
    <div class="split__media reveal"><?= Content::img($photo, $svc['still'], $svc['name'] . ' project, finished', ['w' => 1200, 'h' => 990, 'sizes' => '(max-width: 900px) 100vw, 50vw']) ?><span class="split__tag"><?= e($svc['short']) ?></span></div>
  </div>
</section>

<?php $promises = Content::promises(); if ($promises): ?>
<section class="sec on-dark">
  <div class="wrap">
    <div class="sec__head reveal">
      <?php if (($k = Content::field($d, 'way_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'way_title', 'What you get on every job.')) ?></h2>
      <?= Content::dimline() ?>
    </div>
    <div class="cards cards--2 stagger">
      <?php $icons = ['doc', 'check', 'phone', 'shield', 'clock', 'house']; foreach ($promises as $i => [$t, $x]): ?>
      <div class="card"><span class="card__ico"><?= Content::ico($icons[$i % 6]) ?></span><h3 class="h3"><?= e($t) ?></h3><p><?= e($x) ?></p></div>
      <?php endforeach; ?>
    </div>
    <p style="margin-top:28px"><a class="tlink" href="/process/">How a renovation with us works <?= Content::ico('arrow') ?></a></p>
  </div>
</section>
<?php endif; ?>

<?php if ($faqs): ?>
<section class="sec">
  <div class="wrap faq-split">
    <div class="sec__head reveal">
      <p class="kicker">Straight answers</p>
      <h2 class="h2"><?= e($svc['short']) ?> questions.</h2>
      <?= Content::dimline() ?>
      <p class="lede"><a class="tlink" href="/faq/">All questions <?= Content::ico('arrow') ?></a></p>
    </div>
    <div class="faq stagger"><?php $view->partial('faq-list', ['faqs' => $faqs, 'openFirst' => true]); ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($posts): ?>
<section class="sec sec--tight">
  <div class="wrap">
    <div class="sec__head reveal"><p class="kicker">From the blog</p><h2 class="h2" style="margin-top:16px">Read before you renovate.</h2><?= Content::dimline() ?></div>
    <div class="post-grid stagger"><?php foreach ($posts as $p) $view->partial('post-card', ['post' => $p]); ?></div>
  </div>
</section>
<?php endif; ?>

<?php $view->partial('blocks', ['page' => $page]); ?>

<?php if ($others): ?>
<section class="sec sec--tight sec--plaster2">
  <div class="wrap">
    <div class="sec__head reveal"><p class="kicker">More ways we build</p></div>
    <div class="related stagger">
      <?php foreach (array_slice($others, 0, 3) as $o): ?>
      <a class="svc" href="<?= e($o['path']) ?>">
        <div class="svc__media"><?= Content::img($o['media'], $o['still'], $o['name'], ['w' => 800, 'h' => 600, 'sizes' => '(max-width: 860px) 100vw, 33vw']) ?><span class="svc__icon"><?= Content::ico($o['icon']) ?></span></div>
        <div class="svc__body"><h3 class="h3"><?= e($o['name']) ?></h3><p><?= e($o['tag']) ?></p><span class="svc__go">Learn more <?= Content::ico('arrow') ?></span></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php $view->partial('cta', ['ctaImg' => Content::imgUrl($photo, $svc['still'], 1600)]); ?>
