<?php
/**
 * The public layout: <head> (title, description, canonical, robots, Open Graph, one JSON-LD @graph),
 * the icon sprite, the header, <main id="main">, the footer, the phone thumb bar and menu sheet,
 * and the scripts. Every template renders inside it.
 *
 * @var View $view
 * @var string $content
 */
defined('ICM') or exit;

$m = $view->finalMeta();
$name = siteName();
?><!doctype html>
<html lang="en-CA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($m['title']) ?></title>
<meta name="description" content="<?= e($m['description']) ?>">
<meta name="robots" content="<?= e($m['robots']) ?>">
<?php if ($m['canonical']): ?>
<link rel="canonical" href="<?= e($m['canonical']) ?>">
<?php endif; ?>
<meta name="theme-color" content="#FFFFFF">
<meta name="format-detection" content="telephone=no">
<meta name="geo.region" content="CA-ON">
<meta name="geo.placename" content="<?= e(site('address.city', 'Toronto')) ?>">
<meta property="og:type" content="<?= e($m['type']) ?>">
<meta property="og:site_name" content="<?= e($name) ?>">
<meta property="og:locale" content="en_CA">
<meta property="og:title" content="<?= e($m['og_title']) ?>">
<meta property="og:description" content="<?= e($m['description']) ?>">
<meta property="og:url" content="<?= e($m['url']) ?>">
<meta property="og:image" content="<?= e($m['image']) ?>">
<meta property="og:image:width" content="<?= (int)$m['image_w'] ?>">
<meta property="og:image:height" content="<?= (int)$m['image_h'] ?>">
<meta property="og:image:alt" content="<?= e($m['image_alt']) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($m['og_title']) ?>">
<meta name="twitter:description" content="<?= e($m['description']) ?>">
<meta name="twitter:image" content="<?= e($m['image']) ?>">
<?php if ($m['published'] !== ''): ?>
<meta property="article:published_time" content="<?= e($m['published']) ?>">
<meta property="article:modified_time" content="<?= e($m['modified'] ?: $m['published']) ?>">
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= e($name) ?> blog" href="<?= e(url('/feed/')) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Crect width='48' height='48' rx='11' fill='%23FFFFFF'/%3E%3Cpath d='M9 22 24 10l15 12' fill='none' stroke='%23FB4500' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M24 15v13' stroke='%23222222' stroke-width='1.6'/%3E%3Cpath d='M24 27c-2.8 0-4.8 2.2-4.8 4.4 0 2 4.8 8.8 4.8 8.8s4.8-6.8 4.8-8.8c0-2.2-2-4.4-4.8-4.4z' fill='%23FB4500'/%3E%3C/svg%3E">
<link rel="apple-touch-icon" href="<?= e(asset('img/brand/apple-touch-icon.png')) ?>">
<link rel="preload" href="/assets/fonts/bricolage-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/public-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<?= $view->headHtml() ?>
<?= Seo::graph($view->graphNodes()) ?>
</head>
<body<?= attrs(['class' => $view->bodyClasses() ?: null]) ?>>
<?php $view->partial('icons'); ?>
<a class="skip" href="#main">Skip to content</a>
<?php if ($view->isPreview()): ?>
<div class="preview-bar" role="status">Draft preview: visitors cannot see this yet.</div>
<?php endif; ?>
<div class="env" aria-hidden="true"><span class="env__glow"></span><span class="env__grain"></span></div>
<?php $view->partial('header'); ?>
<main id="main" tabindex="-1">
<?= $content ?>
</main>
<?php $view->partial('footer'); ?>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
<?php if (!$view->isPreview()): ?><script src="https://esolutify.com/adkit/widget.js" data-client="rielbuild" async></script><?php endif; ?>
<?= $view->footHtml() ?>
</body>
</html>
