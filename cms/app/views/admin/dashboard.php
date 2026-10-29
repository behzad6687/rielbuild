<?php
/**
 * Dashboard. A tile or card is left out when the role cannot use it (its value is null).
 */
defined('ICM') or exit;
?>
<div class="page-head">
  <h1><?= e($greeting) ?></h1>
  <p class="muted"><?= e(Time::nowLocal()->format('l, F j, Y')) ?></p>
</div>

<p class="btn-row">
  <?php if ($posts !== null): ?><a class="btn btn--primary" href="<?= e(admin_url('posts/edit')) ?>">Write a blog post</a><?php endif; ?>
  <?php if ($homeId && admin_can('pages.edit')): ?><a class="btn btn--line" href="<?= e(admin_url('pages/edit', ['id' => $homeId])) ?>">Edit the home page</a><?php endif; ?>
  <?php if (admin_can('pages.edit')): ?><a class="btn btn--line" href="<?= e(admin_url('pages')) ?>">All pages</a><?php endif; ?>
  <?php if (admin_can('media.edit')): ?><a class="btn btn--line" href="<?= e(admin_url('media')) ?>">Photos</a><?php endif; ?>
  <a class="btn btn--line" href="/" target="_blank" rel="noopener">View the website</a>
</p>

<div class="grid grid--tiles">
<?php if ($inboxNew !== null): ?>
  <a class="stat" href="<?= e(admin_url('inbox')) ?>"><span class="stat__n"><?= (int)$inboxNew ?></span><span class="stat__l">new estimate requests</span><span class="stat__sub"><?= (int)$inbox30 ?> in the last 30 days</span></a>
<?php endif; ?>
<?php if ($postsLive !== null): ?>
  <a class="stat" href="<?= e(admin_url('posts')) ?>"><span class="stat__n"><?= (int)$postsLive ?></span><span class="stat__l">blog posts live</span><?php if ($postsDraft): ?><span class="stat__sub"><?= (int)$postsDraft ?> draft or scheduled</span><?php endif; ?></a>
<?php endif; ?>
<?php if ($pagesLive !== null): ?>
  <a class="stat" href="<?= e(admin_url('pages')) ?>"><span class="stat__n"><?= (int)$pagesLive ?></span><span class="stat__l">pages live</span></a>
<?php endif; ?>
</div>

<section class="card" aria-labelledby="attention-h">
  <h2 id="attention-h">Needs attention</h2>
<?php if (!$items && !$top404): ?>
  <p class="muted">Nothing needs attention right now.</p>
<?php else: ?>
  <ul class="attention">
<?php foreach ($items as $it): ?>
    <li class="attention__item attention__item--<?= e($it['kind']) ?>"><?php if ($it['link'] !== null): ?><a href="<?= e(admin_url((string)$it['link'])) ?>"><?= e($it['text']) ?></a><?php else: ?><?= e($it['text']) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php if ($top404): ?>
  <h3>Addresses visitors could not find this week</h3>
  <ul class="plain-list">
<?php foreach ($top404 as $r): ?>
    <li><a class="mono" href="<?= e(admin_url('redirects', ['tab' => '404'])) ?>"><?= e($r['path']) ?></a> <span class="muted"><?= (int)$r['hits'] ?> visits</span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php endif; ?>
</section>

<div class="grid grid--2">
<?php if ($inbox !== null): ?>
  <section class="card" aria-labelledby="inbox-h">
    <h2 id="inbox-h">Recent estimate requests</h2>
<?php if (!$inbox): ?>
    <p class="muted">No requests yet. They arrive here from every "Book a free estimate" form.</p>
<?php else: ?>
    <ul class="rows">
<?php foreach ($inbox as $s): ?>
      <li>
        <a href="<?= e(admin_url('inbox/view', ['id' => (int)$s['id']])) ?>"><?= e($s['name'] ?: 'No name') ?></a>
        <?php if ($s['subject']): ?><span class="muted"><?= e(mb_strimwidth((string)$s['subject'], 0, 60, '...')) ?></span><?php endif; ?>
        <span class="tag<?= $s['status'] === 'new' ? ' tag--new' : '' ?>"><?= e(ucfirst((string)$s['status'])) ?></span>
        <span class="muted small"><?= e(Time::long((string)$s['created_at'], true)) ?></span>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <p><a href="<?= e(admin_url('inbox')) ?>">Open the inbox</a></p>
  </section>
<?php endif; ?>

<?php if ($posts !== null): ?>
  <section class="card" aria-labelledby="posts-h">
    <h2 id="posts-h">Recent blog posts</h2>
<?php if (!$posts): ?>
    <p class="muted">No posts yet.</p>
<?php else: ?>
    <ul class="rows">
<?php foreach ($posts as $p): ?>
      <li>
        <a href="<?= e(admin_url('posts/edit', ['id' => (int)$p['id']])) ?>"><?= e($p['title']) ?></a>
        <span class="tag"><?= e(ucfirst((string)$p['status'])) ?></span>
        <span class="muted small"><?= e(Time::long((string)($p['published_at'] ?: $p['updated_at']))) ?></span>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <p><a href="<?= e(admin_url('posts/edit')) ?>">Write a post</a></p>
  </section>
<?php endif; ?>
</div>
