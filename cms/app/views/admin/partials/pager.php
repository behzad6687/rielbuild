<?php
/**
 * Page links for a Db::paginate() result. Owner: W7.
 *   admin_partial('pager', ['pg' => $pg, 'route' => 'media', 'query' => ['q' => $q]]);
 */
defined('ICM') or exit;
if (empty($pg) || (int)$pg['pages'] <= 1) return;
$page = (int)$pg['page'];
$pages = (int)$pg['pages'];
$query = array_filter((array)($query ?? []), fn($v) => $v !== null && $v !== '');
$link = static fn(int $p): string => admin_url((string)$route, $query + ['page' => $p]);
?>
<nav class="pager" aria-label="Pages">
<?php if ($page > 1): ?>
  <a class="btn btn--line btn--sm" href="<?= e($link($page - 1)) ?>" rel="prev">Previous</a>
<?php endif; ?>
  <span class="pager__at">Page <?= $page ?> of <?= $pages ?></span>
<?php if ($page < $pages): ?>
  <a class="btn btn--line btn--sm" href="<?= e($link($page + 1)) ?>" rel="next">Next</a>
<?php endif; ?>
</nav>
