<?php
/** Sign out, confirmed with a button (a plain link cannot sign anyone out). Owner: W7. */
defined('ICM') or exit;
?>
<div class="login">
  <div class="login__box">
    <h1>Sign out</h1>
    <p>Sign out of the <?= e(siteName()) ?> admin on this device? A remembered device is forgotten too.</p>
    <form method="post" action="/admin/logout" class="btn-row">
      <?= csrfField() ?>
      <button class="btn btn--primary" type="submit">Sign out</button>
      <a class="btn btn--line" href="/admin/">Stay signed in</a>
    </form>
  </div>
</div>
