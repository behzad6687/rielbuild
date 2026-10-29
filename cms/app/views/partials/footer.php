<?php
/**
 * The footer (Admin > Menus > Footer columns; the business line, contact details and social links from
 * Settings), the phone thumb bar and the phone menu sheet (Admin > Menus > Phone menu, the header
 * menu by default).
 *
 * @var View $view
 */
defined('ICM') or exit;

$phone = Content::phone();
$tel = telHref($phone);
$email = site('email.main', 'contact@rielbuild.ca');
$cols = [];
foreach (['footer_services', 'footer_company'] as $key) {
    $menu = Menus::menu($key);
    if ($menu !== null && (int)$menu['is_visible'] !== 1) continue;
    $tree = Content::nav($key);
    if ($tree) $cols[] = [(int)($menu['show_heading'] ?? 1) === 1 ? (string)($menu['heading'] ?? '') : '', $tree];
}
$legal = Content::nav('footer_legal');
$social = array_filter([
    'Instagram' => site('social.instagram'), 'Facebook' => site('social.facebook'), 'Houzz' => site('social.houzz'),
    'YouTube' => site('social.youtube'), 'Google' => site('social.google'),
], static fn($u) => str_starts_with((string)$u, 'https://'));
$sheet = Menus::phoneTree();
$chip = static function (array $it, string $fallback): string {
    $u = Content::imgUrl(is_array($it['image'] ?? null) ? $it['image'] : null, $fallback, 480);
    return $u !== '' ? '<span class="sgroup__chip"><img data-src="' . e($u) . '" alt="" width="48" height="48"></span>'
        : '<span class="sgroup__chip sgroup__chip--ico">' . Content::ico($it['icon'] ?? 'arrow') . '</span>';
};
?>
<footer class="foot">
  <div class="wrap">
    <div class="foot__top">
      <div class="foot__brand">
        <a class="brand brand--foot" href="/" aria-label="<?= e(siteName()) ?> home"><?= Content::brand(40) ?></a>
        <?php if (($line = site('footer.line')) !== ''): ?><p class="foot__line"><?= e($line) ?></p><?php endif; ?>
        <a class="btn btn--brass" href="/contact/"><?= e(site('form.heading', 'Book a free estimate')) ?></a>
      </div>
      <?php foreach ($cols as [$heading, $tree]): ?>
      <div class="foot__col">
        <?php if ($heading !== ''): ?><p class="foot__h"><?= e($heading) ?></p><?php endif; ?>
        <?php foreach ($tree as $it): if (!$it['path']) continue; ?><a href="<?= e((string)$it['path']) ?>"<?= $it['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($it['label']) ?></a><?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <div class="foot__col foot__contact">
        <p class="foot__h">Talk to us</p>
        <a href="<?= e($tel) ?>"><?= Content::ico('phone') ?><?= e($phone) ?></a>
        <a href="mailto:<?= e($email) ?>"><?= Content::ico('mail') ?><?= e($email) ?></a>
        <?php if (($area = site('area.line', 'Serving Toronto and the GTA')) !== ''): ?><span><?= Content::ico('pin') ?><?= e($area) ?></span><?php endif; ?>
        <?php if (($hours = site('hours.text', 'Mon to Sat, 8am to 6pm')) !== ''): ?><span><?= Content::ico('clock') ?><?= e($hours) ?></span><?php endif; ?>
        <?php foreach ($social as $label => $u): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= Content::ico('arrow') ?><?= e($label) ?></a><?php endforeach; ?>
      </div>
    </div>
    <div class="foot__plumb" aria-hidden="true"><span></span></div>
    <div class="foot__bottom">
      <p>&copy; <?= e(Time::nowLocal()->format('Y')) ?> <?= e(site('org.legal_name') ?: siteName()) ?>. All rights reserved.<?php foreach ($legal as $it): if (!$it['path']) continue; ?> <a href="<?= e((string)$it['path']) ?>"><?= e($it['label']) ?></a><?php endforeach; ?></p>
      <?php if (($note = site('footer.note')) !== ''): ?><p class="foot__note"><?= e($note) ?></p><?php endif; ?>
      <p class="foot__credit">Website designed and developed by <a href="https://esolutify.com/" target="_blank" rel="nofollow noopener">eSolutify</a></p>
    </div>
  </div>
</footer>

<nav class="thumbbar" aria-label="Quick actions">
  <a class="thumbbar__item" href="<?= e($tel) ?>"><?= Content::ico('phone') ?><span>Call</span></a>
  <a class="thumbbar__item" href="/services/"><?= Content::ico('house') ?><span>Services</span></a>
  <a class="thumbbar__item thumbbar__item--cta" href="/contact/"><?= Content::ico('doc') ?><span><?= e(site('header.cta_label', 'Free estimate')) ?></span></a>
  <button class="thumbbar__item" type="button" data-sheet-open="menu-sheet" aria-haspopup="dialog"><?= Content::ico('menu') ?><span>Menu</span></button>
</nav>

<dialog class="sheet" id="menu-sheet" aria-label="Menu">
  <div class="sheet__in">
    <div class="sheet__top">
      <a class="brand" href="/"><?= Content::brand(36) ?></a>
      <button class="icon-btn sheet__x" type="button" data-sheet-close aria-label="Close menu"><?= Content::ico('close') ?></button>
    </div>
    <div class="sheet__groups">
      <?php foreach ($sheet as $i => $it): ?>
        <?php if ($it['children']): ?>
      <details class="sgroup"<?= $i === 0 ? ' open' : '' ?>>
        <summary><?= $chip($it, 'stills/kitchen') ?><span><?= e($it['label']) ?></span><?= Content::ico('chev', 'ico ico--chev') ?></summary>
        <div class="sgroup__body">
          <?php foreach ($it['children'] as $c): if (!$c['path']) continue; ?><a href="<?= e((string)$c['path']) ?>"><?= $c['icon'] ? Content::ico($c['icon']) : '' ?><?= e($c['label']) ?></a><?php endforeach; ?>
          <?php if ($it['path']): ?><a class="sgroup__all" href="<?= e((string)$it['path']) ?>">All <?= e(mb_strtolower($it['label'])) ?> <span aria-hidden="true">&rarr;</span></a><?php endif; ?>
        </div>
      </details>
        <?php elseif ($it['path']): ?>
      <a class="sgroup sgroup--link" href="<?= e((string)$it['path']) ?>"><?= $chip($it, '') ?><span><?= e($it['label']) ?></span><?= Content::ico('arrow') ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="sheet__foot">
      <a class="btn btn--brass btn--block" href="/contact/"><?= e(site('form.heading', 'Book a free estimate')) ?></a>
      <a class="btn btn--ghost btn--block" href="<?= e($tel) ?>"><?= Content::ico('phone') ?><?= e($phone) ?></a>
    </div>
  </div>
</dialog>
