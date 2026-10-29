<?php
/**
 * The fresh-install menus (Admin > Menus, "Reset to default" writes these again).
 * Each item: label, path (a page path becomes a page item that follows the page), kind (page | link |
 * heading | auto), auto [source, options] for an automatic list, description (the small line under a
 * label), image (a seed media key from seed/media.php), icon, layout (top items: list | mega), children.
 */
defined('ICM') or exit;

$i = static fn(string $label, ?string $path, ?string $desc = null, ?string $image = null): array
    => ['label' => $label, 'path' => $path] + ($desc !== null ? ['description' => $desc] : []) + ($image !== null ? ['image' => $image] : []);

return [
    'header' => [
        ['label' => 'Services', 'path' => '/services/', 'kind' => 'page', 'layout' => 'mega', 'image' => 'kitchen',
         'description' => 'Every one with a fixed, written quote and one project lead.', 'children' => [
            ['label' => 'Our services (automatic)', 'path' => null, 'kind' => 'auto', 'auto' => ['services', []]],
        ]],
        $i('How we work', '/process/', null, 'step-build'),
        $i('Projects', '/projects/', null, 'basement'),
        $i('Blog', '/blog/', null, 'planning'),
        ['label' => 'About', 'path' => null, 'kind' => 'heading', 'image' => 'planning', 'children' => [
            $i('Who we are', '/about/', 'A GTA builder that works plumb'),
            ['label' => 'The way we work', 'path' => '/process/#promise', 'kind' => 'link', 'description' => 'Fixed quote, one lead, clean site'],
            $i('Where we work', '/areas/', 'Toronto and the whole GTA'),
            $i('Straight answers', '/faq/', 'Deposits, timelines, permits, dust'),
        ]],
        $i('Contact', '/contact/'),
    ],
    'footer_services' => [
        ['label' => 'Our services (automatic)', 'path' => null, 'kind' => 'auto', 'auto' => ['services', []]],
    ],
    'footer_company' => [
        $i('About us', '/about/'),
        $i('How we work', '/process/'),
        $i('Projects', '/projects/'),
        $i('Service areas', '/areas/'),
        $i('Blog', '/blog/'),
        $i('FAQ', '/faq/'),
        $i('Contact', '/contact/'),
    ],
    'footer_legal' => [],
];
