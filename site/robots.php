<?php
/* robots.txt (rewritten here by .htaccess). On the live domain root this becomes the real robots file. */
require __DIR__ . '/inc/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (NOINDEX) {
    echo "User-agent: *\nDisallow: " . url('') . "\n";
} else {
    echo "User-agent: *\nAllow: /\nDisallow: " . url('inc/') . "\n\nSitemap: " . abs_url('sitemap.xml') . "\n";
}
