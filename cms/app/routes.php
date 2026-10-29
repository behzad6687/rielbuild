<?php
/**
 * The route table. Pages (/, /services/, /services/{slug}/, /process/, /projects/, /about/, /faq/,
 * /areas/, /contact/, /blog/ and any page added in the admin) are served from the pages table by
 * Router::handle() before this table is read, so only the pattern and functional routes live here.
 *
 * Each row: [methods, regex on the canonical path, handler, flags]. A handler is [Class, method],
 * or a special handler: '@post' (a published blog post by slug), '@newspage' (/blog/page/{n}/),
 * '@file' (a PHP entry point such as the admin). Flags: noindex, no-redirect (informational).
 */
defined('ICM') or exit;

return [
    ['GET',      '#^/blog/page/(\d+)/$#',                         ['@newspage', 'blog_index'],   ['index']],
    ['GET',      '#^/blog/([a-z0-9%][a-z0-9%_.-]*)/$#',           ['@post', 'post'],             ['index', 'sitemap']],
    ['GET',      '#^/sitemap\.xml$#',                             [Sitemap::class, 'xml'],       ['public']],
    ['GET',      '#^/robots\.txt$#',                              [Seo::class, 'robots'],        ['public']],
    ['GET',      '#^/feed/$#',                                    [Content::class, 'rss'],       ['public']],
    ['GET',      '#^/forms/token/([a-z_]+)/$#',                   [Forms::class, 'token'],       ['noindex']],
    ['POST',     '#^/forms/([a-z_]+)/$#',                         [Forms::class, 'handle'],      ['noindex']],
    ['POST',     '#^/csp-report/$#',                              [Headers::class, 'cspReport'], ['noindex']],
    ['GET|POST', '#^/admin(/.*)?$#',                              ['@file', 'admin/index.php'],  ['noindex']],
    ['GET|POST', '#^/install(/.*)?$#',                            ['@file', 'install/index.php'], ['noindex']],
];
