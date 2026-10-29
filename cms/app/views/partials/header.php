<?php
/**
 * The site header: the logo, the main menu (Admin > Menus > Header menu), the phone number and the
 * estimate button. A top item with sub-items opens a panel: "Big panel with photos" (mega) shows each
 * sub-item with its icon, short line and a photo preview; "A plain list" shows labels and short lines.
 *
 * @var View $view
 */
defined('ICM') or exit;

$here = Router::path();
$phone = Content::phone();
$tel = telHref($phone);
$name = siteName();
$items = Content::nav('header');
$k = 0;
?>
<header class="top" id="top">
  <div class="wrap top__in">
    <a class="brand" href="/" aria-label="<?= e($name) ?> home"><?= Content::brand(40) ?></a>

    <nav class="nav" id="nav" aria-label="Main">
      <span class="nav__pill" aria-hidden="true"></span>
      <ul class="nav__list">
        <?php foreach ($items as $i => $it):
            $on = Content::navOn($it, $here) ? ' is-current' : '';
            $mid = 'menu-' . ($it['id'] ?? $i);
            if (!$it['children']): ?>
        <li class="nav__item<?= $on ?>"><a class="nav__link" href="<?= e((string)$it['path']) ?>"<?= $it['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($it['label']) ?></a></li>
            <?php elseif (($it['layout'] ?? '') === 'mega'):
                $previewImg = Content::imgUrl(is_array($it['image']) ? $it['image'] : null, 'stills/kitchen', 800); ?>
        <li class="nav__item has-menu has-mega<?= $on ?>">
          <button class="nav__btn" type="button" aria-expanded="false" aria-controls="<?= e($mid) ?>"><?= e($it['label']) ?><?= Content::ico('chev', 'ico ico--chev') ?></button>
          <div class="menu mega" id="<?= e($mid) ?>">
            <div class="mega__list">
              <p class="mega__kicker"><?= e($it['label']) ?></p>
              <?php $n = 0; foreach ($it['children'] as $c):
                  $cimg = Content::imgUrl(is_array($c['image']) ? $c['image'] : null, (string)($c['still'] ?? ''), 800); ?>
              <a class="mitem" style="--k:<?= $n++ ?>" href="<?= e((string)$c['path']) ?>" data-name="<?= e($c['label']) ?>" data-tag="<?= e((string)$c['description']) ?>"<?= $cimg !== '' ? ' data-img="' . e($cimg) . '"' : '' ?>>
                <span class="mitem__icon"><?= Content::ico($c['icon'] ?: 'house') ?></span>
                <span class="mitem__text"><b><?= e($c['label']) ?></b><?php if ($c['description']): ?><small><?= e($c['description']) ?></small><?php endif; ?></span>
                <?= Content::ico('arrow', 'ico mitem__arrow') ?>
              </a>
              <?php endforeach; ?>
            </div>
            <aside class="mega__preview" aria-hidden="true">
              <span class="mega__photo"><img data-src="<?= e($previewImg) ?>" alt="" width="480" height="300"></span>
              <p class="mp__name"><?= e($it['label']) ?></p>
              <?php if ($it['description']): ?><p class="mp__tag"><?= e($it['description']) ?></p><?php endif; ?>
              <?php if ($it['path']): ?><a class="btn btn--sm btn--brass" href="<?= e((string)$it['path']) ?>" tabindex="-1">All <?= e(mb_strtolower($it['label'])) ?></a><?php endif; ?>
            </aside>
            <div class="mega__foot">
              <a class="mfoot" href="/contact/"><?= Content::ico('doc') ?><b><?= e(site('form.heading', 'Book a free estimate')) ?></b><span>an honest price range on the first visit</span></a>
              <a class="mfoot" href="<?= e($tel) ?>"><?= Content::ico('phone') ?><b><?= e($phone) ?></b><span>talk to a project lead</span></a>
            </div>
          </div>
        </li>
            <?php else: ?>
        <li class="nav__item has-menu<?= $on ?>">
          <button class="nav__btn" type="button" aria-expanded="false" aria-controls="<?= e($mid) ?>"><?= e($it['label']) ?><?= Content::ico('chev', 'ico ico--chev') ?></button>
          <div class="menu menu--list" id="<?= e($mid) ?>">
            <?php if ($it['path']): ?><a class="mlink" href="<?= e((string)$it['path']) ?>"><b><?= e($it['label']) ?></b><?php if ($it['description']): ?><small><?= e($it['description']) ?></small><?php endif; ?></a><?php endif; ?>
            <?php foreach ($it['children'] as $c): ?>
            <a class="mlink" href="<?= e((string)$c['path']) ?>"<?= $c['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><b><?= e($c['label']) ?></b><?php if ($c['description']): ?><small><?= e($c['description']) ?></small><?php endif; ?></a>
            <?php endforeach; ?>
          </div>
        </li>
            <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </nav>

    <div class="top__right">
      <a class="top__tel" href="<?= e($tel) ?>" aria-label="Call <?= e($phone) ?>"><?= Content::ico('phone') ?><span><?= e($phone) ?></span></a>
      <a class="btn btn--brass btn--sm top__cta" href="/contact/"><?= e(site('header.cta_label', 'Free estimate')) ?></a>
      <button class="burger" type="button" data-sheet-open="menu-sheet" aria-haspopup="dialog" aria-label="Open menu"><span></span><span></span><span></span></button>
    </div>
  </div>
</header>
