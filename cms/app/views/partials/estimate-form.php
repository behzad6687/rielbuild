<?php
/**
 * The estimate form (posts to /forms/contact/, kept in Admin > Inbox). Its wording is Settings >
 * Site-wide wording. Vars: $formId (the element id, default "quote"), $service (a service slug to preselect).
 */
defined('ICM') or exit;

$id = (string)($formId ?? 'quote');
$pre = (string)($service ?? ($_GET['service'] ?? ''));
$sent = ($_GET['sent'] ?? '') === 'contact';
$phone = Content::phone();
try {
    $token = Csrf::publicToken('contact');
} catch (Throwable) {
    $token = '';
}
?>
<div class="form-wrap<?= $sent ? ' is-sent' : '' ?>" id="<?= e($id) ?>">
  <div class="form-head">
    <h3 class="h3"><?= e(site('form.heading', 'Book a free estimate')) ?></h3>
    <?php if (($sub = site('form.sub')) !== ''): ?><p><?= e($sub) ?></p><?php endif; ?>
  </div>
  <form class="form" action="/forms/contact/" method="post" data-ajax novalidate>
    <div class="field"><label for="<?= e($id) ?>-name">Your name</label><input id="<?= e($id) ?>-name" name="name" type="text" autocomplete="name" required maxlength="80"><span class="err" aria-live="polite"></span></div>
    <div class="field"><label for="<?= e($id) ?>-phone">Phone</label><input id="<?= e($id) ?>-phone" name="phone" type="tel" autocomplete="tel" required pattern="[0-9+()\-\s.]{10,20}"><span class="err" aria-live="polite"></span></div>
    <div class="field"><label for="<?= e($id) ?>-email">Email</label><input id="<?= e($id) ?>-email" name="email" type="email" autocomplete="email" required maxlength="120"><span class="err" aria-live="polite"></span></div>
    <div class="field"><label for="<?= e($id) ?>-service">Project</label>
      <select id="<?= e($id) ?>-service" name="service" required>
        <option value="">Choose one</option>
        <?php foreach (Content::services() as $s): ?><option value="<?= e($s['slug']) ?>"<?= $pre === $s['slug'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        <option value="other"<?= $pre === 'other' ? ' selected' : '' ?>>Something else</option>
      </select><span class="err" aria-live="polite"></span></div>
    <div class="field field--full"><label for="<?= e($id) ?>-msg">About the project <small>(optional)</small></label><textarea id="<?= e($id) ?>-msg" name="message" maxlength="2000" placeholder="Which rooms, rough timing, anything we should know."></textarea></div>
    <div class="hp" aria-hidden="true"><label>Leave this empty<input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>
    <input type="hidden" name="_t" value="<?= e($token) ?>">
    <input type="hidden" name="_src" value="<?= e(Router::path()) ?>">
    <button class="btn btn--brass" type="submit"><?= e(site('form.button', 'Book my free estimate')) ?> <?= Content::ico('arrow') ?></button>
    <?php if (($note = site('form.note')) !== ''): ?><p class="form__note"><?= e($note) ?></p><?php endif; ?>
  </form>
  <div class="sent" role="status">
    <span class="sent__mark"><?= Content::ico('check') ?></span>
    <h3 class="h3"><?= e(site('form.thanks_title', 'Thanks. We\'ve got it.')) ?></h3>
    <p><?= e(site('form.thanks_text', 'A project lead will call you within one business day to book your visit.')) ?> Need us sooner? Call <a class="tlink" href="<?= e(telHref($phone)) ?>"><?= e($phone) ?></a>.</p>
  </div>
</div>
