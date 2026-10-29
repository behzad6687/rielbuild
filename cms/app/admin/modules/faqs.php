<?php
/**
 * Admin > Content > FAQs. List with sort order, add, edit, delete. The category decides where a question
 * shows: General questions on the FAQ page (and the first three on every service page); a service's
 * questions on that service page and under it on the FAQ page. "Also show on" adds pages (/ is home).
 */
defined('ICM') or exit;

$categories = Content::faqCategories();

return [
    'nav' => ['group' => 'Content', 'label' => 'FAQs', 'path' => 'faqs', 'perm' => 'faqs.edit', 'sort' => 30],
    'routes' => SimpleCrud::routes([
        'table'     => 'faqs',
        'route'     => 'faqs',
        'perm'      => 'faqs.edit',
        'label'     => 'Question',
        'plural'    => 'FAQs',
        'add_label' => 'question',
        'title_col' => 'question',
        'lede'      => 'Questions and answers on the FAQ page and the service pages. A question can also show on other pages, such as the home page (/).',
        'order'     => 'sort, id',
        'search'    => ['question', 'answer_html'],
        'filters'   => ['category' => $categories],
        'list'      => ['question' => 'Question', 'category' => 'Category', 'is_visible' => 'Shown'],
        'format'    => [
            'category'   => static fn(array $r): string => e($categories[$r['category']] ?? $r['category']),
            'is_visible' => static fn(array $r): string => (int)$r['is_visible'] === 1 ? 'Yes' : 'Hidden',
        ],
        'defaults'  => ['is_visible' => 1, 'category' => 'general'],
        'fields'    => [
            ['key' => 'question', 'label' => 'Question', 'type' => 'text', 'max' => 300, 'required' => true],
            ['key' => 'answer_html', 'label' => 'Answer', 'type' => 'rich', 'required' => true, 'not_null' => true],
            ['key' => 'category', 'label' => 'Where it belongs', 'type' => 'select', 'options' => $categories, 'default' => 'general',
             'hint' => 'General questions show on the FAQ page; a service\'s questions show on that service page too.'],
            ['key' => 'show_on', 'label' => 'Also show on these pages', 'type' => 'text', 'max' => 255,
             'hint' => 'Page addresses separated by commas, for example /, /areas/. Use / for the home page (the first six show there).'],
            ['key' => 'is_visible', 'label' => 'Show on the site', 'type' => 'check'],
        ],
        'validate'  => static function (array $d): array {
            foreach (array_filter(array_map('trim', explode(',', (string)($d['show_on'] ?? '')))) as $p) {
                if (!preg_match('#^/(?!/)[a-z0-9/_.%-]*$#i', $p)) return ['"' . $p . '" is not a page address. Addresses start with /.'];
            }
            return [];
        },
        'after_save'   => static function (int $id): void { ContentAdmin::reindexFaq($id); Content::flush(); },
        'after_delete' => static fn(int $id) => Search::remove('faq', $id),
    ]),
];
