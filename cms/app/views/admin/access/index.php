<?php
/**
 * Access control (v3, master only). Vars: $perms (permission => name), $roles (role => label), $hidden (role => [perm]).
 */
defined('ICM') or exit;

echo AdminUi::head('Access control', 'Choose what each role can use. Untick a box to hide that section from the role; tick it again to bring it back. You, the master admin, always see everything, and other people never see your account.');
?>
<form method="post" action="<?= e(AdminUi::url('access/save')) ?>">
  <?= AdminUi::csrf() ?>
  <div class="table-wrap">
    <table class="table access-grid">
      <thead>
        <tr><th scope="col">Section</th><?php foreach ($roles as $r => $label): ?><th scope="col"><?= e($r === 'owner' ? 'Main admin (Owner)' : $label) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
<?php foreach ($perms as $perm => $name): ?>
        <tr>
          <th scope="row"><?= e($name) ?><span class="ed-sub"><?= e($perm) ?></span></th>
<?php foreach ($roles as $r => $label): $has = in_array($r, Auth::MATRIX[$perm], true); ?>
          <td data-label="<?= e($label) ?>">
<?php if ($has): ?>
            <label class="access-tick"><input type="checkbox" name="show[<?= e($r) ?>][<?= e($perm) ?>]" value="1"<?= in_array($perm, $hidden[$r] ?? [], true) ? '' : ' checked' ?>><span class="sr-only"><?= e($label . ': ' . $name) ?></span></label>
<?php else: ?>
            <span class="muted" aria-label="Not part of this role">·</span>
<?php endif; ?>
          </td>
<?php endforeach; ?>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p><button class="btn btn--primary" type="submit">Save access</button></p>
</form>
