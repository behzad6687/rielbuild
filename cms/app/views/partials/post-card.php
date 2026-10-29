<?php
/** A blog post card (blog index, related posts, home and service pages). Vars: $post, $h ('h2' | 'h3'), $eager. */
defined('ICM') or exit;

$p = $post;
$href = Content::postPath($p);
$date = Content::postDate($p);
$h = in_array($h ?? 'h3', ['h2', 'h3'], true) ? ($h ?? 'h3') : 'h3';
$m = !empty($p['featured_media_id']) ? Content::media((int)$p['featured_media_id']) : null;
$cats = Content::postCategories();
$text = ($p['excerpt'] ?? '') !== '' ? (string)$p['excerpt'] : (string)($p['body_html'] ?? '');
?>
<article class="post-card">
  <a class="post-card__media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true"><?= Content::img($m, 'stills/planning', (string)(($p['featured_alt'] ?? '') ?: ($m['alt'] ?? '')), ['sizes' => '(max-width: 700px) 100vw, (max-width: 1100px) 50vw, 33vw', 'eager' => !empty($eager), 'w' => 800, 'h' => 600]) ?></a>
  <div class="post-card__body">
    <p class="post-card__meta"><?php if (isset($cats[$p['category']])): ?><span><?= e($cats[$p['category']]) ?></span><?php endif; ?><?php if ($date !== ''): ?><time datetime="<?= e($date) ?>"><?= e(formatDate($date)) ?></time><?php endif; ?></p>
    <<?= $h ?> class="h3 post-card__title"><a href="<?= e($href) ?>"><?= e((string)$p['title']) ?></a></<?= $h ?>>
    <p><?= e(Content::words($text, 26)) ?></p>
    <a class="svc__go" href="<?= e($href) ?>" aria-label="<?= e('Read: ' . $p['title']) ?>">Read the article <?= Content::ico('arrow') ?></a>
  </div>
</article>
