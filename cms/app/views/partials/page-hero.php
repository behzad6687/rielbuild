<?php
/**
 * The inner-page hero: breadcrumbs, the small line, the page's one H1 (with the *accent* words in orange),
 * the introduction and a background photo. Vars: $page, $pageData, plus optional $heroTitle (an H1
 * override), $heroLede (trusted HTML), $heroImg (a URL), $heroStill (fallback still), $heroExtra (trusted HTML).
 *
 * @var View $view
 */
defined('ICM') or exit;

$d = $pageData ?? [];
$title = (string)($heroTitle ?? (($page['h1'] ?? '') ?: ($page['title'] ?? '')));
$lede = $heroLede ?? Content::intro($page ?? null);
$bg = $heroImg ?? Content::imgUrl(Content::pageMedia($d, 'hero'), (string)($heroStill ?? 'stills/home-renovation'), 1600);
?>
<section class="page-hero">
  <?php if ($bg !== ''): ?><div class="page-hero__bg" style="background-image:url('<?= e($bg) ?>')" aria-hidden="true"></div><?php endif; ?>
  <div class="wrap">
    <?= Content::crumbs($view) ?>
    <?php if (($k = Content::field($d, 'kicker')) !== ''): ?><p class="kicker kicker--light" style="margin-top:26px"><?= e($k) ?></p><?php endif; ?>
    <h1 class="h1"><?= Content::accent($title) ?><?= !empty($heroDot) && !str_contains($title, '*') && !preg_match('/[.!?]$/', $title) ? '<span class="brass">.</span>' : '' ?></h1>
    <?php if ($lede !== ''): ?><div class="lede"><?= $lede ?></div><?php endif; ?>
    <?= $heroExtra ?? '' ?>
  </div>
</section>
