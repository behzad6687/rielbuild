<?php
/**
 * Page not found (also every draft page for visitors).
 *
 * @var View $view
 */
defined('ICM') or exit;

$view->seo(['title' => 'Page not found', 'description' => 'This page is not here. Find renovation services, projects and contact details for ' . siteName() . '.', 'robots' => 'noindex, follow', 'breadcrumbs' => false]);
?>
<section class="sec nf">
  <div class="wrap">
    <p class="kicker">Error 404</p>
    <h1 class="h1">This wall isn't <span class="brass">plumb.</span></h1>
    <p class="lede">The page you were looking for has moved or never existed. Let's get you back on a straight line.</p>
    <div class="btn-row">
      <a class="btn btn--brass" href="/">Back to home</a>
      <a class="btn btn--ghost" href="/services/">See our services</a>
      <a class="btn btn--ghost" href="/blog/">Read the blog</a>
    </div>
  </div>
</section>
