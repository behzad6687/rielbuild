<?php
/**
 * Admin > Content > Projects: the photo gallery on /projects/ (with its filter buttons) and the
 * "Rooms people actually live in" strip on the home page. Drag to reorder.
 */
defined('ICM') or exit;

$filters = Content::projectFilters();

return [
    'nav' => ['group' => 'Content', 'label' => 'Projects', 'path' => 'projects', 'perm' => 'pages.edit', 'sort' => 35],
    'routes' => SimpleCrud::routes([
        'table'     => 'projects',
        'route'     => 'projects',
        'perm'      => 'pages.edit',
        'label'     => 'Project',
        'plural'    => 'Projects',
        'add_label' => 'project',
        'title_col' => 'title',
        'lede'      => 'The photos on the Projects page, in this order. Tick "Show on the home page" for the strip on the home page (the first five show).',
        'order'     => 'sort, id',
        'search'    => ['title', 'category'],
        'filters'   => ['filter_key' => $filters],
        'list'      => ['title' => 'Project', 'category' => 'Label', 'filter_key' => 'Filter', 'on_home' => 'Home page', 'is_visible' => 'Shown'],
        'format'    => [
            'filter_key' => static fn(array $r): string => e($filters[$r['filter_key']] ?? $r['filter_key']),
            'on_home'    => static fn(array $r): string => (int)$r['on_home'] === 1 ? 'Yes' : '',
            'is_visible' => static fn(array $r): string => (int)$r['is_visible'] === 1 ? 'Yes' : 'Hidden',
        ],
        'defaults'  => ['is_visible' => 1, 'filter_key' => 'kitchen'],
        'fields'    => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'max' => 200, 'required' => true, 'hint' => 'For example "White oak kitchen with stone island".'],
            ['key' => 'category', 'label' => 'Small label', 'type' => 'text', 'max' => 60, 'hint' => 'Shown above the title, for example "Kitchen".'],
            ['key' => 'filter_key', 'label' => 'Filter button', 'type' => 'select', 'options' => $filters, 'default' => 'kitchen'],
            ['key' => 'media_id', 'label' => 'Photo', 'type' => 'image', 'required' => true],
            ['key' => 'alt', 'label' => 'Photo description for screen readers and Google', 'type' => 'text', 'max' => 300, 'hint' => 'Empty uses the title.'],
            ['key' => 'on_home', 'label' => 'Show on the home page', 'type' => 'check'],
            ['key' => 'is_visible', 'label' => 'Show on the site', 'type' => 'check'],
        ],
        'after_save'   => static fn(int $id) => Content::flush(),
        'after_delete' => static fn(int $id) => Content::flush(),
    ]),
];
