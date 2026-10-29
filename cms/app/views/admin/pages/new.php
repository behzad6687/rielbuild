<?php
/**
 * Add a content page. Vars: $values, $errors.
 */
defined('ICM') or exit;

echo AdminUi::head('Add a page', 'A new text page (a policy, a notice, a landing page) or a new service. It starts as a draft; publish it when it is ready.', AdminUi::link('Back to pages', AdminUi::url('pages')));
echo AdminUi::errors($errors);
?>
<form method="post" class="card form" action="<?= e(AdminUi::url('pages/new')) ?>">
  <?= AdminUi::csrf() ?>
  <?= AdminUi::select('template', 'Kind of page', ['content' => 'Text page', 'service' => 'Service (listed under Services, in the menus and on the home page)'], $values['template'] ?? 'content') ?>
  <?= AdminUi::input('title', 'Page name', $values['title'], ['max' => 160, 'required' => true, 'hint' => 'For a service, its name, for example "Deck and Patio".']) ?>
  <?= AdminUi::input('path', 'Web address', $values['path'], ['max' => 191, 'placeholder' => '/kitchen-design-ideas/', 'hint' => 'Leave empty to build it from the name (a service goes under /services/). Lowercase, with hyphens.',
      'attrs' => ['autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
  <button class="btn btn--primary" type="submit">Create the draft</button>
</form>
