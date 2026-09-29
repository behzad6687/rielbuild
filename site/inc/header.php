<?php
/* Shared <head> + header. Expects $page = array(title, desc, path, [og], [schema], [body], [crumbs]). */
if (!isset($page) || !is_array($page)) $page = array();
$p_title = isset($page['title']) ? $page['title'] : SITE_NAME . ' | Renovation & Construction in the GTA';
$p_desc  = isset($page['desc']) ? $page['desc'] : '';
$p_path  = isset($page['path']) ? $page['path'] : '';
$p_og    = isset($page['og']) ? $page['og'] : 'img/og.jpg';
$p_body  = isset($page['body']) ? $page['body'] : '';
$p_crumb = isset($page['crumbs']) ? $page['crumbs'] : array();
$canonical = abs_url($p_path);
$here = trim($p_path, '/');
function nav_on($prefix, $here) { return ($prefix === '' ? $here === '' : strpos($here, $prefix) === 0) ? ' is-current' : ''; }

/* Structured data: the business on every page, plus page-specific items */
$ld = array();
$ld[] = array(
    '@context' => 'https://schema.org',
    '@type' => 'GeneralContractor',
    '@id' => abs_url('') . '#business',
    'name' => SITE_NAME,
    'legalName' => SITE_LEGAL,
    'url' => abs_url(''),
    'logo' => abs_url('assets/img/brand/logo.png'),
    'image' => abs_url('assets/img/og.jpg'),
    'telephone' => PHONE_TEL,
    'email' => EMAIL,
    'priceRange' => '$$',
    'address' => array('@type' => 'PostalAddress', 'addressLocality' => 'Toronto', 'addressRegion' => 'ON', 'addressCountry' => 'CA'),
    'areaServed' => array_map(function ($a) { return array('@type' => 'City', 'name' => $a); }, $GLOBALS['AREAS']),
    'openingHoursSpecification' => array(array('@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), 'opens' => '08:00', 'closes' => '18:00')),
    'hasOfferCatalog' => array('@type' => 'OfferCatalog', 'name' => 'Renovation and construction services', 'itemListElement' => array_values(array_map(function ($s, $k) {
        return array('@type' => 'Offer', 'itemOffered' => array('@type' => 'Service', 'name' => $s['name'], 'url' => abs_url('services/' . $k . '/')));
    }, $GLOBALS['SERVICES'], array_keys($GLOBALS['SERVICES'])))),
);
if ($p_crumb) {
    $items = array(array('@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => abs_url('')));
    $i = 2;
    foreach ($p_crumb as $c) { $items[] = array('@type' => 'ListItem', 'position' => $i++, 'name' => $c[0], 'item' => abs_url($c[1])); }
    $ld[] = array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items);
}
if (!empty($page['schema'])) foreach ($page["schema"] as $sch) $ld[] = $sch;
?><!doctype html>
<html lang="en-CA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($p_title) ?></title>
<meta name="description" content="<?= e($p_desc) ?>">
<?php if (NOINDEX): ?><meta name="robots" content="noindex, nofollow">
<?php else: ?><meta name="robots" content="index, follow, max-image-preview:large">
<?php endif; ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="theme-color" content="#141D19">
<meta name="format-detection" content="telephone=no">
<meta name="geo.region" content="CA-ON">
<meta name="geo.placename" content="Toronto">
<!-- Open Graph: absolute URLs are built from the request, so they are correct on any host -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
<meta property="og:locale" content="en_CA">
<meta property="og:title" content="<?= e($p_title) ?>">
<meta property="og:description" content="<?= e($p_desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e(abs_url('assets/' . $p_og)) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($p_title) ?>">
<meta name="twitter:description" content="<?= e($p_desc) ?>">
<meta name="twitter:image" content="<?= e(abs_url('assets/' . $p_og)) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Crect width='48' height='48' rx='11' fill='%23141D19'/%3E%3Cpath d='M9 22 24 10l15 12' fill='none' stroke='%23C4913F' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M24 15v13' stroke='%23F2EDE4' stroke-width='1.6'/%3E%3Cpath d='M24 27c-2.8 0-4.8 2.2-4.8 4.4 0 2 4.8 8.8 4.8 8.8s4.8-6.8 4.8-8.8c0-2.2-2-4.4-4.8-4.4z' fill='%23C4913F'/%3E%3C/svg%3E">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/brand/apple-touch-icon.png')) ?>">
<link rel="preload" href="<?= e(url('assets/fonts/bricolage-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(url('assets/fonts/public-sans-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<?php if (!empty($page['preload'])) echo $page['preload']; ?>
<?php foreach ($ld as $block): ?>
<script type="application/ld+json"><?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endforeach; ?>
</head>
<body class="<?= e($p_body) ?>">
<?php include __DIR__ . '/icons.php'; ?>
<a class="skip" href="#main">Skip to content</a>
<div class="env" aria-hidden="true"><span class="env__glow"></span><span class="env__grain"></span></div>

<header class="top" id="top">
  <div class="wrap top__in">
    <a class="brand" href="<?= e(url('')) ?>" aria-label="RIELBUILD home">
      <svg class="brand__mark" width="40" height="40" aria-hidden="true" focusable="false"><use href="#i-mark"/></svg>
      <span class="brand__text"><b>RIEL<span>BUILD</span></b><small>Construction &amp; Renovation</small></span>
    </a>

    <nav class="nav" id="nav" aria-label="Main">
      <span class="nav__pill" aria-hidden="true"></span>
      <ul class="nav__list">
        <li class="nav__item has-menu has-mega<?= nav_on('services', $here) ?>">
          <button class="nav__btn" type="button" aria-expanded="false" aria-controls="menu-services">Services<svg class="ico ico--chev" aria-hidden="true" focusable="false"><use href="#i-chev"/></svg></button>
          <div class="menu mega" id="menu-services">
            <div class="mega__list">
              <p class="mega__kicker">What we build</p>
              <?php $k = 0; foreach ($SERVICES as $mslug => $ms): ?>
              <a class="mitem" style="--k:<?= $k++ ?>" href="<?= e(url('services/' . $mslug . '/')) ?>" data-name="<?= e($ms['name']) ?>" data-tag="<?= e($ms['tag']) ?>" data-img="<?= e(url('assets/img/' . $ms['img'] . '-sm.webp')) ?>">
                <span class="mitem__icon"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-<?= e($ms['icon']) ?>"/></svg></span>
                <span class="mitem__text"><b><?= e($ms['name']) ?></b><small><?= e($ms['tag']) ?></small></span>
                <svg class="ico mitem__arrow" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg>
              </a>
              <?php endforeach; ?>
            </div>
            <aside class="mega__preview" aria-hidden="true">
              <span class="mega__photo"><img data-src="<?= e(url('assets/img/stills/kitchen-sm.webp')) ?>" alt="" width="480" height="300"></span>
              <p class="mp__name">Six ways we build</p>
              <p class="mp__tag">Every one with a fixed, written quote and one project lead.</p>
              <a class="btn btn--sm btn--brass" href="<?= e(url('services/')) ?>" tabindex="-1">All services</a>
            </aside>
            <div class="mega__foot">
              <a class="mfoot" href="<?= e(url('contact/')) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-doc"/></svg><b>Book a free consultation</b><span>an honest price range on the first visit</span></a>
              <a class="mfoot" href="tel:<?= e(PHONE_TEL) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg><b><?= e(PHONE) ?></b><span>talk to a project lead</span></a>
            </div>
          </div>
        </li>
        <li class="nav__item<?= nav_on('process', $here) ?>"><a class="nav__link" href="<?= e(url('process/')) ?>">How we work</a></li>
        <li class="nav__item<?= nav_on('projects', $here) ?>"><a class="nav__link" href="<?= e(url('projects/')) ?>">Projects</a></li>
        <li class="nav__item has-menu<?= (nav_on('about', $here) || nav_on('faq', $here) || nav_on('areas', $here)) ? ' is-current' : '' ?>">
          <button class="nav__btn" type="button" aria-expanded="false" aria-controls="menu-about">About<svg class="ico ico--chev" aria-hidden="true" focusable="false"><use href="#i-chev"/></svg></button>
          <div class="menu menu--list" id="menu-about">
            <a class="mlink" href="<?= e(url('about/')) ?>"><b>Who we are</b><small>A GTA builder that works plumb</small></a>
            <a class="mlink" href="<?= e(url('process/')) ?>#promise"><b>The plumb promise</b><small>Four things we put in writing</small></a>
            <a class="mlink" href="<?= e(url('areas/')) ?>"><b>Where we work</b><small>Toronto and the whole GTA</small></a>
            <a class="mlink" href="<?= e(url('faq/')) ?>"><b>Straight answers</b><small>Deposits, timelines, permits, dust</small></a>
          </div>
        </li>
        <li class="nav__item<?= nav_on('contact', $here) ?>"><a class="nav__link" href="<?= e(url('contact/')) ?>">Contact</a></li>
      </ul>
    </nav>

    <div class="top__right">
      <a class="top__tel" href="tel:<?= e(PHONE_TEL) ?>" aria-label="Call <?= e(PHONE) ?>"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-phone"/></svg><span><?= e(PHONE) ?></span></a>
      <a class="btn btn--brass btn--sm top__cta" href="<?= e(url('contact/')) ?>">Free consultation</a>
      <button class="burger" type="button" data-sheet-open="menu-sheet" aria-haspopup="dialog" aria-label="Open menu"><span></span><span></span><span></span></button>
    </div>
  </div>
</header>
