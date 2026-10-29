<?php
/**
 * Pages and SEO list (spec 4.2.13). Vars: $rows, $tpl, $q, $attention, $templates.
 */
defined('ICM') or exit;

echo AdminUi::head('Pages and SEO', 'Every page of the site with its search appearance. A title must be unique, 30 to 60 characters; a meta description 70 to 160.',
    AdminUi::link('Add a page', AdminUi::url('pages/new'), 'btn btn--primary'));
echo AdminUi::tabs([
    ['label' => 'Pages', 'url' => AdminUi::url('pages'), 'on' => true, 'count' => count($rows)],
    ['label' => 'Shared blocks', 'url' => AdminUi::url('pages/shared')],
    ['label' => 'Notification bar', 'url' => AdminUi::url('settings', ['section' => 'notice'])],
], 'Pages');
?>
<form class="form form--inline ed-filters" method="get" action="<?= e(AdminUi::url('pages')) ?>" role="search">
  <?= AdminUi::input('q', 'Address or name', $q, ['type' => 'search', 'max' => 100]) ?>
  <?= AdminUi::select('template', 'Template', array_combine($templates, array_map(static fn($t) => ucfirst(str_replace('_', ' ', (string)$t)), $templates)) ?: [], $tpl, ['blank' => 'Every template', 'attrs' => ['data-autosubmit' => true]]) ?>
  <?= AdminUi::check('attention', 'Needs attention', (bool)$attention, ['attrs' => ['data-autosubmit' => true]]) ?>
  <div class="field"><button class="btn btn--line" type="submit">Show</button></div>
</form>

<?php if (!$rows): ?>
  <p class="ed-empty">No page matches.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="table table--cards">
      <thead><tr><th scope="col">Page</th><th scope="col">Template</th><th scope="col">Status</th><th scope="col">SEO health</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $p): ?>
          <tr>
            <td data-label="Page"><a href="<?= e(AdminUi::url('pages/edit', ['id' => $p['id']])) ?>"><strong><?= e($p['title']) ?></strong></a><span class="ed-sub mono wrap-any"><?= e($p['path']) ?></span></td>
            <td data-label="Template"><?= e(ucfirst(str_replace('_', ' ', (string)$p['template']))) ?></td>
            <td data-label="Status"><?= AdminKit::chip((string)$p['status']) ?>
              <?php if (str_contains((string)$p['robots'], 'noindex')): ?><span class="tag">noindex</span><?php endif; ?></td>
            <td data-label="SEO health">
              <ul class="health">
                <?php if (!$p['issues']): ?><li class="is-ok">Healthy</li><?php endif; ?>
                <?php foreach ($p['issues'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?>
                <?php if (empty($p['og_media_id'])): ?><li class="is-ok muted">Default social image</li><?php endif; ?>
              </ul>
            </td>
            <td class="actions" data-label="Actions">
              <a class="btn btn--line btn--sm" href="<?= e(AdminUi::url('pages/edit', ['id' => $p['id']])) ?>">Edit</a>
              <?php if ($p['status'] === 'published'): ?><a class="btn btn--line btn--sm" href="<?= e($p['path']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
