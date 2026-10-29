<?php
/**
 * The home page: the scroll film (street, window, kitchen, stairs, basement) with its five captions,
 * then what we build, the way we work, how it works, the rooms gallery, straight answers, the blog,
 * where we work and the estimate band. Wording: Pages > Home; lists come from their own screens.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$f = static fn(string $k, string $def = ''): string => Content::field($d, $k, $def);
$phone = Content::phone();
$tel = telHref($phone);
$faqs = array_slice(Content::faqsOn('/'), 0, 6);
$posts = Content::homePosts(3);
$video = static fn(string $file): int => (int)@filesize(ROOT_DIR . '/assets/video/' . $file);

$view->script(asset('js/home.js'));
$view->bodyClass('page-home');
$view->head('<link rel="preload" as="image" href="/assets/img/hero-poster.jpg" media="(orientation: landscape) and (min-width: 721px) and (prefers-reduced-motion: no-preference)">');
$view->head('<link rel="preload" as="image" href="/assets/img/hero-poster-m.jpg" media="((orientation: portrait) or (max-width: 720px)) and (prefers-reduced-motion: no-preference)">');
if ($faqs) $view->schema(Seo::faqPage($faqs));

$band = static function (string $n) use ($f): array {
    return [$f('film' . $n . '_kicker'), $f('film' . $n . '_title'), $f('film' . $n . '_sub')];
};
[$k1, $t1, $s1] = $band('1');
?>
<h1 class="sr"><?= e(plainText($page['h1'] ?: $page['title'])) ?></h1>

<section class="film" id="film" aria-label="<?= e($t1 ?: plainText($page['title'])) ?>"
  data-video="/assets/video/hero-scrub.mp4" data-bytes="<?= $video('hero-scrub.mp4') ?>" data-poster="/assets/img/hero-poster.jpg"
  data-video-m="/assets/video/hero-scrub-m.mp4" data-bytes-m="<?= $video('hero-scrub-m.mp4') ?>" data-poster-m="/assets/img/hero-poster-m.jpg">
  <div class="film__stage">
    <div class="film__poster" style="--static-img:url('/assets/img/hero-static.jpg')" aria-hidden="true"></div>
    <video class="film__video" preload="none" muted playsinline disablepictureinpicture aria-hidden="true" tabindex="-1"></video>
    <div class="film__scrim" aria-hidden="true"></div>

    <div class="film__bands">
      <div class="band band--l fx-rise" data-a="0" data-b="0.14" data-fx="rise">
        <div class="band__in">
          <?php if ($k1 !== ''): ?><p class="kicker"><?= e($k1) ?></p><?php endif; ?>
          <p class="band__h" data-split><?= e($t1) ?></p>
          <?php if ($s1 !== ''): ?><p class="band__p band__sub"><?= e($s1) ?></p><?php endif; ?>
        </div>
      </div>
      <?php [$k, $t, $s] = $band('2'); ?>
      <div class="band band--l fx-depth" data-a="0.18" data-b="0.36" data-fx="depth" style="--sa:.84">
        <div class="band__in">
          <?php if ($k !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
          <h2 class="band__h" data-split><?= e($t) ?></h2>
          <?php if ($s !== ''): ?><p class="band__p band__sub"><?= e($s) ?></p><?php endif; ?>
        </div>
      </div>
      <?php [$k, $t, $s] = $band('3'); ?>
      <div class="band band--l fx-snap" data-a="0.47" data-b="0.63" data-fx="snap" style="--sa:.86" data-ramp="0.034">
        <div class="band__in">
          <?php if ($k !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
          <h2 class="band__h" data-split><?= e($t) ?></h2>
          <?php if ($s !== ''): ?><p class="band__p band__sub"><?= e($s) ?></p><?php endif; ?>
        </div>
      </div>
      <?php [$k, $t, $s] = $band('4'); ?>
      <div class="band band--r fx-blur" data-a="0.67" data-b="0.82" data-fx="blur">
        <div class="band__in">
          <?php if ($k !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
          <h2 class="band__h" style="position:relative"><span class="band__sharp"><?= e($t) ?></span><span class="band__soft" aria-hidden="true"><?= e($t) ?></span></h2>
          <?php if ($s !== ''): ?><p class="band__p band__sub"><?= e($s) ?></p><?php endif; ?>
        </div>
      </div>
      <div class="band band--c fx-rise" data-a="0.87" data-b="1" data-fx="rise" style="--sa:.94">
        <div class="band__in">
          <h2 class="band__h" data-split><?= e($f('film5_title', 'Let\'s build it right.')) ?></h2>
          <?php if (($s = $f('film5_sub')) !== ''): ?><p class="band__p band__sub"><?= e($s) ?></p><?php endif; ?>
          <div class="btn-row band__cta">
            <a class="btn btn--brass" href="/contact/"><?= e($f('film_cta', 'Book a free estimate')) ?> <?= Content::ico('arrow') ?></a>
            <a class="btn btn--ghost" href="<?= e($tel) ?>"><?= Content::ico('phone') ?><?= e($phone) ?></a>
          </div>
        </div>
      </div>
    </div>

    <svg class="film__ring" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="20" fill="none" stroke-width="2.5"/><circle cx="24" cy="24" r="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="126" style="stroke-dashoffset:var(--ld,126);transform:rotate(-90deg);transform-origin:center"/></svg>
    <div class="film__cue" aria-hidden="true">Scroll<i></i></div>
    <div class="film__progress" aria-hidden="true"><span>01 / Outside</span></div>

    <div class="film__static">
      <?php if ($k1 !== ''): ?><p class="kicker"><?= e($k1) ?></p><?php endif; ?>
      <p class="h1" style="margin-top:14px"><?= e($t1) ?></p>
      <?php if ($s1 !== ''): ?><p class="lede"><?= e($s1) ?></p><?php endif; ?>
      <div class="btn-row">
        <a class="btn btn--brass" href="/contact/"><?= e($f('film_cta', 'Book a free estimate')) ?></a>
        <a class="btn btn--ghost" href="<?= e($tel) ?>"><?= Content::ico('phone') ?>Call <?= e($phone) ?></a>
      </div>
    </div>
  </div>
</section>

<section class="sec" id="services">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <?php if (($k = $f('svc_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
        <h2 class="h2" style="margin-top:16px"><?= Content::accent($f('svc_title', 'What we build')) ?></h2>
        <?= Content::dimline() ?>
      </div>
      <?php if (($l = $f('svc_lede')) !== ''): ?><p class="lede reveal"><?= e($l) ?></p><?php endif; ?>
    </div>
    <?php $view->partial('services-grid', ['services' => Content::services()]); ?>
  </div>
</section>

<?php $view->partial('promise', ['wayTitle' => $f('way_title', 'The way we work'), 'holdLabel' => $f('hold_label')]); ?>

<section class="sec" id="process">
  <div class="wrap">
    <div class="sec__head">
      <div class="reveal">
        <?php if (($k = $f('steps_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
        <h2 class="h2" style="margin-top:16px"><?= Content::accent($f('steps_title', 'How it works')) ?></h2>
        <?= Content::dimline() ?>
      </div>
    </div>
    <?php $view->partial('steps'); ?>
  </div>
</section>

<?php $projects = array_slice(Content::projects(true), 0, 5); if ($projects): ?>
<section class="sec sec--plaster2" id="work">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <h2 class="h2"><?= Content::accent($f('gal_title', 'Our work')) ?></h2>
        <?= Content::dimline() ?>
      </div>
      <p class="lede reveal"><?= e($f('gal_lede')) ?> <a class="tlink" href="/projects/">See all projects <?= Content::ico('arrow') ?></a></p>
    </div>
    <div class="gal gal--home stagger">
      <?php foreach ($projects as $pj): $m = Content::media((int)$pj['media_id']); ?>
      <figure class="gal__item"><?= Content::img($m, '', (string)($pj['alt'] ?: $pj['title']), ['sizes' => '(max-width: 520px) 100vw, (max-width: 860px) 50vw, 60vw']) ?><figcaption><?php if ($pj['category']): ?><small><?= e($pj['category']) ?></small><?php endif; ?><b><?= e($pj['title']) ?></b></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($faqs): ?>
<section class="sec" id="faq">
  <div class="wrap faq-split">
    <div class="sec__head reveal">
      <?php if (($k = $f('faq_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2"><?= Content::accent($f('faq_title', 'Straight answers')) ?></h2>
      <?= Content::dimline() ?>
      <p class="lede"><?= e($f('faq_lede')) ?> <a class="tlink" href="/faq/">All questions <?= Content::ico('arrow') ?></a></p>
    </div>
    <div class="faq stagger"><?php $view->partial('faq-list', ['faqs' => $faqs, 'openFirst' => true]); ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($posts): ?>
<section class="sec sec--plaster2" id="blog">
  <div class="wrap">
    <div class="sec__head sec__head--split">
      <div class="reveal">
        <?php if (($k = $f('blog_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
        <h2 class="h2" style="margin-top:16px"><?= Content::accent($f('blog_title', 'From the blog')) ?></h2>
        <?= Content::dimline() ?>
      </div>
      <p class="lede reveal"><a class="tlink" href="/blog/">All articles <?= Content::ico('arrow') ?></a></p>
    </div>
    <div class="post-grid stagger"><?php foreach ($posts as $p) $view->partial('post-card', ['post' => $p]); ?></div>
  </div>
</section>
<?php endif; ?>

<?php $view->partial('areas-band', ['kicker' => $f('areas_kicker'), 'title' => $f('areas_title', 'Where we work'), 'lede' => $f('areas_lede')]); ?>

<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('cta'); ?>
