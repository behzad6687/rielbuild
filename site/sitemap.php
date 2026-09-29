<?php
/* sitemap.xml (rewritten here by .htaccess). Absolute URLs come from the request. */
require __DIR__ . '/inc/config.php';
require __DIR__ . '/inc/data.php';
header('Content-Type: application/xml; charset=utf-8');
if (NOINDEX) header('X-Robots-Tag: noindex');
$pages = array('' => '1.0', 'services/' => '0.9');
foreach ($SERVICES as $slug => $s) $pages['services/' . $slug . '/'] = '0.9';
$pages += array('process/' => '0.7', 'projects/' => '0.7', 'about/' => '0.6', 'faq/' => '0.6', 'areas/' => '0.7', 'contact/' => '0.8');
$mod = date('Y-m-d', filemtime(__DIR__ . '/inc/data.php'));
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $p => $prio) {
    echo '  <url><loc>' . e(abs_url($p)) . '</loc><lastmod>' . $mod . '</lastmod><priority>' . $prio . "</priority></url>\n";
}
echo "</urlset>\n";
