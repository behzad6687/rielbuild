<?php
/* Small reusable pieces. */

/* responsive image: webp + jpg fallback, lazy by default */
function pic($base, $alt, $w = 1200, $h = 900, $lazy = true, $sizes = '(max-width: 760px) 100vw, 50vw')
{
    $b = url('assets/img/' . $base);
    $load = $lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"';
    return '<picture><source type="image/webp" srcset="' . e($b) . '-sm.webp 800w, ' . e($b) . '.webp 1600w" sizes="' . e($sizes) . '">'
        . '<img src="' . e($b) . '.jpg" alt="' . e($alt) . '" width="' . (int) $w . '" height="' . (int) $h . '"' . $load . '></picture>';
}

/* blueprint dimension line under headings, drawn on scroll */
function dimline()
{
    return '<svg class="dim" viewBox="0 0 260 14" preserveAspectRatio="none" aria-hidden="true" focusable="false" data-draw>'
        . '<path d="M1 2v10M259 2v10M1 7h258" fill="none" stroke="currentColor" stroke-width="1.4" pathLength="1"/></svg>';
}

function crumbs($items)
{
    $h = '<nav aria-label="Breadcrumb"><ol class="crumbs"><li><a href="' . e(url('')) . '">Home</a></li>';
    $n = count($items);
    foreach ($items as $i => $c) {
        $h .= ($i === $n - 1) ? '<li aria-current="page">' . e($c[0]) . '</li>' : '<li><a href="' . e(url($c[1])) . '">' . e($c[0]) . '</a></li>';
    }
    return $h . '</ol></nav>';
}

function page_hero($kicker, $title, $lede, $img, $crumbs = array(), $extra = '')
{
    ob_start(); ?>
<section class="page-hero">
  <div class="page-hero__bg" style="background-image:url('<?= e(url('assets/img/' . $img . '.webp')) ?>')" aria-hidden="true"></div>
  <div class="wrap">
    <?php if ($crumbs) echo crumbs($crumbs); ?>
    <p class="kicker kicker--light" style="margin-top:26px"><?= e($kicker) ?></p>
    <h1 class="h1"><?= $title ?></h1>
    <p class="lede"><?= e($lede) ?></p>
    <?= $extra ?>
  </div>
</section>
<?php return ob_get_clean();
}

function consult_form($id = 'quote', $heading = 'Book a free consultation', $sub = 'Tell us a little about the job. A project lead calls you back within one business day.')
{
    global $SERVICES;
    ob_start(); ?>
<div class="form-wrap" id="<?= e($id) ?>">
  <div class="form-head">
    <h3 class="h3"><?= e($heading) ?></h3>
    <p><?= e($sub) ?></p>
  </div>
  <form class="form" action="<?= e(url('contact/send.php')) ?>" method="post" data-ajax novalidate>
    <div class="field"><label for="<?= e($id) ?>-name">Your name</label><input id="<?= e($id) ?>-name" name="name" type="text" autocomplete="name" required maxlength="80"><span class="err" aria-live="polite"></span></div>
    <div class="field"><label for="<?= e($id) ?>-phone">Phone</label><input id="<?= e($id) ?>-phone" name="phone" type="tel" autocomplete="tel" required pattern="[0-9+()\-\s.]{7,20}"><span class="err" aria-live="polite"></span></div>
    <div class="field"><label for="<?= e($id) ?>-email">Email</label><input id="<?= e($id) ?>-email" name="email" type="email" autocomplete="email" required maxlength="120"><span class="err" aria-live="polite"></span></div>
    <div class="field"><label for="<?= e($id) ?>-service">Project</label>
      <select id="<?= e($id) ?>-service" name="service" required>
        <option value="">Choose one</option>
        <?php foreach ($SERVICES as $slug => $s): ?><option value="<?= e($slug) ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        <option value="other">Something else</option>
      </select><span class="err" aria-live="polite"></span></div>
    <div class="field field--full"><label for="<?= e($id) ?>-msg">About the project <small>(optional)</small></label><textarea id="<?= e($id) ?>-msg" name="message" maxlength="2000" placeholder="Which rooms, rough timing, anything we should know."></textarea></div>
    <div class="hp" aria-hidden="true"><label>Leave this empty<input name="company_site" type="text" tabindex="-1" autocomplete="off"></label></div>
    <input type="hidden" name="ts" value="<?= time() ?>">
    <button class="btn btn--brass" type="submit">Book my free consultation <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg></button>
    <p class="form__note">No pressure, no obligation. We never share your details.</p>
  </form>
  <div class="sent" role="status">
    <span class="sent__mark"><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-check"/></svg></span>
    <h3 class="h3">Thanks. We've got it.</h3>
    <p>A project lead will call you within one business day to book your visit. Need us sooner? Call <a class="tlink" href="tel:<?= e(PHONE_TEL) ?>"><?= e(PHONE) ?></a>.</p>
  </div>
</div>
<?php return ob_get_clean();
}

function cta_band($img = 'hero-ending')
{
    ob_start(); ?>
<section class="cta on-dark sec" id="book">
  <div class="cta__bg" style="background-image:url('<?= e(url('assets/img/' . $img . '.webp')) ?>')" aria-hidden="true"></div>
  <div class="wrap cta__in">
    <div class="reveal">
      <p class="kicker">Let's build it right</p>
      <h2 class="h2" style="margin-top:16px">Start with a free visit and an honest number.</h2>
      <?= dimline() ?>
      <ul class="cta__points">
        <li><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-check"/></svg>A price range on the first visit</li>
        <li><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-check"/></svg>A fixed, written quote after that</li>
        <li><svg class="ico" aria-hidden="true" focusable="false"><use href="#i-check"/></svg>No pressure to sign anything</li>
      </ul>
      <p style="margin-top:26px;color:var(--on-dark-2)">Rather talk now? <a class="tlink" href="tel:<?= e(PHONE_TEL) ?>"><?= e(PHONE) ?></a></p>
    </div>
    <div class="reveal"><?= consult_form('quote') ?></div>
  </div>
</section>
<?php return ob_get_clean();
}

function plumb_line()
{
    return '<div class="plumb" aria-hidden="true"><span class="plumb__line"><svg class="plumb__bob" viewBox="0 0 16 26"><path d="M8 0v6" stroke="currentColor" stroke-width="1.2"/><path d="M8 6c-3.4 0-6 2.7-6 5.5C2 14.2 8 26 8 26s6-11.8 6-14.5C14 8.7 11.4 6 8 6z" fill="currentColor"/></svg></span></div>';
}
