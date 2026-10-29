<?php
/**
 * News posts list (spec 4.2.2). Vars: $pg, $q, $status, $sent, $counts.
 */
defined('ICM') or exit;

$tabs = [['label' => 'All', 'url' => AdminUi::url('posts', ['q' => $q]), 'on' => $status === '', 'count' => array_sum(array_map('intval', $counts))]];
foreach (ContentAdmin::POST_STATUSES as $k => $label) {
    $tabs[] = ['label' => $label, 'url' => AdminUi::url('posts', ['status' => $k, 'q' => $q]), 'on' => $status === $k, 'count' => (int)($counts[$k] ?? 0)];
}
echo AdminUi::head('Blog posts', 'Posts appear on /blog/ and at /blog/{web address}/, and the newest show on the home page and their service pages.', AdminUi::link('Write a post', AdminUi::url('posts/edit'), 'btn btn--primary'));
echo AdminUi::tabs($tabs, 'Post status');
?>
<form class="form form--inline ed-filters" method="get" action="<?= e(AdminUi::url('posts')) ?>" role="search">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <?= AdminUi::input('q', 'Search headlines, addresses and tags', $q, ['type' => 'search', 'max' => 100]) ?>
  <div class="field field--button"><button class="btn btn--line" type="submit">Search</button></div>
</form>

<?php if (!$pg['rows']): ?>
  <p class="ed-empty">No posts<?= $q !== '' ? ' match that search' : ' yet' ?>.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table table--cards">
      <thead>
        <tr><th scope="col">Headline</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">SEO</th><th scope="col">Newsletter sent</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php foreach ($pg['rows'] as $p):
            $score = ContentAdmin::seo($p, 'post')['score'];
            $live = ContentAdmin::postLive($p); ?>
          <tr>
            <td data-label="Headline"><a href="<?= e(AdminUi::url('posts/edit', ['id' => $p['id']])) ?>"><?= e(mb_strimwidth((string)$p['title'], 0, 100, '...')) ?></a>
              <?php if ((int)$p['is_featured'] === 1): ?><span class="tag">Featured</span><?php endif; ?></td>
            <td data-label="Status"><?= AdminKit::chip((string)$p['status']) ?></td>
            <td data-label="Date" class="ed-nowrap"><?= e($p['published_at'] ? Time::long((string)$p['published_at'], $p['status'] === 'scheduled') : 'Not set') ?></td>
            <td data-label="SEO"><span class="score score--<?= $score >= 80 ? 'good' : ($score >= 50 ? 'fair' : 'poor') ?>"><?= (int)$score ?></span></td>
            <td data-label="Newsletter sent"><?= isset($sent[(int)$p['id']]) ? '<span aria-hidden="true">&#10003;</span><span class="sr-only">Yes</span>' : '<span class="muted">No</span>' ?></td>
            <td class="actions">
              <?php if ($p['status'] === 'draft'): ?>
                <?= AdminUi::postButton('posts/publish', [], ['id' => $p['id']], 'Publish', ['class' => 'btn btn--primary btn--sm', 'confirm' => 'Publish "' . mb_strimwidth((string)$p['title'], 0, 60, '...') . '" now?']) ?>
              <?php elseif ($live): ?>
                <a class="btn btn--line btn--sm" href="<?= e(ContentAdmin::postPath((string)$p['slug'])) ?>" target="_blank" rel="noopener">View</a>
              <?php endif; ?>
              <a class="btn btn--line btn--sm" href="<?= e(AdminUi::url('posts/edit', ['id' => $p['id']])) ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= AdminUi::pager($pg, 'posts', array_filter(['q' => $q, 'status' => $status])) ?>
<?php endif; ?>
