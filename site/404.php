<?php
require __DIR__ . '/inc/boot.php';
http_response_code(404);
$page = array(
    'title' => 'Page not found | RIELBUILD',
    'desc'  => 'This page is not here. Find renovation services, projects and contact details for RIELBUILD.',
    'path'  => '404.php',
);
include __DIR__ . '/inc/header.php';
?>
<main id="main" tabindex="-1">
  <section class="sec nf">
    <div class="wrap">
      <p class="kicker">Error 404</p>
      <h1 class="h1">This wall isn't <span class="brass">plumb.</span></h1>
      <p class="lede">The page you were looking for has moved or never existed. Let's get you back on a straight line.</p>
      <div class="btn-row">
        <a class="btn btn--brass" href="<?= e(url('')) ?>">Back to home</a>
        <a class="btn btn--ghost" href="<?= e(url('services/')) ?>">See our services</a>
      </div>
    </div>
  </section>
</main>
<?php include __DIR__ . '/inc/footer.php'; ?>
