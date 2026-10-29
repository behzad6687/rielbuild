<?php
/**
 * Contact: talk to a project lead (phone and email from Settings), what happens next, the estimate
 * form and the service areas.
 *
 * @var View $view
 * @var array $page
 * @var array $pageData
 */
defined('ICM') or exit;

$d = $pageData;
$phone = Content::phone();
$email = site('email.main', 'contact@rielbuild.ca');
$view->schema(['@type' => 'ContactPage', '@id' => url('/contact/') . '#page', 'name' => plainText($page['title']), 'url' => url('/contact/'), 'about' => ['@id' => Seo::orgId()]]);
$view->partial('page-hero', ['heroStill' => 'stills/step-talk']);
?>
<section class="sec">
  <div class="wrap contact-grid">
    <div class="reveal">
      <?php if (($k = Content::field($d, 'talk_kicker')) !== ''): ?><p class="kicker"><?= e($k) ?></p><?php endif; ?>
      <h2 class="h2" style="margin-top:16px"><?= Content::accent(Content::field($d, 'talk_title', 'Talk to a project lead')) ?></h2>
      <?= Content::dimline() ?>
      <div class="contact-list">
        <a href="<?= e(telHref($phone)) ?>"><?= Content::ico('phone') ?><span><b><?= e($phone) ?></b><small><?= e(Content::field($d, 'phone_note', site('hours.text'))) ?></small></span></a>
        <a href="mailto:<?= e($email) ?>"><?= Content::ico('mail') ?><span><b><?= e($email) ?></b><small><?= e(Content::field($d, 'email_note')) ?></small></span></a>
        <div><?= Content::ico('pin') ?><span><b><?= e(Content::field($d, 'area_line', 'Toronto and the GTA')) ?></b><small><?= e(Content::field($d, 'area_note')) ?></small></span></div>
      </div>
      <?php if (($next = Content::field($d, 'next_text')) !== ''): ?>
      <div class="note" style="margin-top:24px"><?= Content::ico('level') ?><p><b>What happens next.</b> <?= e($next) ?></p></div>
      <?php endif; ?>
      <?php if ($page['body_html']): ?><div class="prose" style="margin-top:24px"><?= Content::prose((string)$page['body_html']) ?></div><?php endif; ?>
    </div>
    <div class="reveal"><?php $view->partial('estimate-form', ['formId' => 'contact-form']); ?></div>
  </div>
</section>
<?php $view->partial('blocks', ['page' => $page]); ?>
<?php $view->partial('areas-band', ['title' => 'Toronto and the whole GTA.', 'kicker' => 'Where we work']); ?>
