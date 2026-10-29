<?php
/**
 * The blocks added to a page in the admin (Pages > a page > Blocks), in order: text sections, cards
 * (side by side), questions and answers, buttons, images and tables. Consecutive cards share one grid
 * and consecutive questions one list. Vars: $page.
 */
defined('ICM') or exit;

$blocks = isset($page['id']) ? Content::blocks((int)$page['id']) : [];
if (!$blocks) return;

$groups = [];
foreach ($blocks as $b) {
    $t = (string)$b['type'];
    $last = count($groups) - 1;
    if (in_array($t, ['card', 'accordion_item'], true) && $last >= 0 && $groups[$last][0] === $t) $groups[$last][1][] = $b;
    else $groups[] = [$t, [$b]];
}
?>
<section class="sec sec--blocks">
  <div class="wrap blocks-flow">
    <?php foreach ($groups as [$type, $items]): ?>
      <?php if ($type === 'card'): ?>
    <div class="cards stagger">
      <?php foreach ($items as $b): ?><div class="card"><?php if ($b['title']): ?><h3 class="h3"><?= e($b['title']) ?></h3><?php endif; ?><?= Content::prose((string)$b['body_html']) ?></div><?php endforeach; ?>
    </div>
      <?php elseif ($type === 'accordion_item'): ?>
    <div class="faq">
      <?php foreach ($items as $b): ?><details><summary><?= e((string)$b['title']) ?></summary><div class="faq__a"><?= Content::prose((string)$b['body_html']) ?></div></details><?php endforeach; ?>
    </div>
      <?php else: $b = $items[0]; $d = json_decode((string)($b['data_json'] ?? ''), true) ?: []; ?>
        <?php if ($type === 'cta'): ?>
    <div class="btn-row"><a class="btn btn--brass" href="<?= e((string)($d['path'] ?? '/contact/')) ?>"><?= e((string)($b['title'] ?: 'Book a free estimate')) ?> <?= Content::ico('arrow') ?></a></div>
        <?php elseif ($type === 'image'): $m = !empty($d['media_id']) ? Content::media((int)$d['media_id']) : null; if ($m): ?>
    <figure class="blocks-figure"><?= Content::img($m, '', (string)($m['alt'] ?? ''), ['sizes' => '(max-width: 900px) 100vw, 900px']) ?><?php if ($b['title']): ?><figcaption><?= e($b['title']) ?></figcaption><?php endif; ?></figure>
        <?php endif; ?>
        <?php else: ?>
    <div class="prose reveal">
      <?php if ($b['title']): ?><h2 class="h2"><?= e($b['title']) ?></h2><?= Content::dimline() ?><?php endif; ?>
      <?= Content::prose((string)$b['body_html']) ?>
    </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</section>
