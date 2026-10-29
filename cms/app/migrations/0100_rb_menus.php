<?php
/**
 * The menu engine's tables and columns (Menus, MenusAdmin): the menus rows (one per header, phone,
 * footer column, lower row and thumb bar) and the round 2 item columns (kind + ref_id, automatic
 * lists, description, photo, icon, layout, show from / until, who saved it).
 */
defined('ICM') or exit;

return ['up' => static function (): void {
    Schema::createTable('menus', [
        'cols' => [
            'mkey str(40) NOT NULL', 'label str(120) NOT NULL', "area str(12) NOT NULL DEFAULT 'footer'", 'heading str(120) NULL',
            'show_heading bool NOT NULL DEFAULT 1', 'col tiny NOT NULL DEFAULT 1', "layout str(12) NOT NULL DEFAULT 'list'",
            'same_as str(40) NULL', 'media_id uint NULL', 'sort int NOT NULL DEFAULT 0', 'is_visible bool NOT NULL DEFAULT 1',
            'is_system bool NOT NULL DEFAULT 0', 'updated_at dt NULL', 'updated_by uint NULL',
        ],
        'pk' => ['mkey'],
    ]);
    foreach ([
        "kind str(12) NOT NULL DEFAULT 'link'", 'ref_id uint NULL', 'auto_source str(32) NULL', 'auto_opts text NULL',
        'description str(300) NULL', 'media_id uint NULL', 'icon str(24) NULL', 'layout str(12) NULL',
        'visible_from date NULL', 'visible_until date NULL', 'updated_at dt NULL', 'updated_by uint NULL',
    ] as $col) {
        Schema::addColumn('menu_items', $col);
    }
    Schema::addColumn('not_found_log', 'ignored bool NOT NULL DEFAULT 0');
}];
