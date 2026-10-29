<?php
/** Admin > Projects: the gallery on /projects/ and the home page (title, category, filter, photo, order). */
defined('ICM') or exit;

return ['up' => static function (): void {
    Schema::createTable('projects', [
        'cols' => [
            'id pk', 'title str(200) NOT NULL', 'category str(60) NULL', "filter_key str(20) NOT NULL DEFAULT 'home'",
            'media_id uint NULL', 'alt str(300) NULL', 'on_home bool NOT NULL DEFAULT 0', 'sort int NOT NULL DEFAULT 0',
            'is_visible bool NOT NULL DEFAULT 1', 'updated_at dt NULL',
        ],
        'fk'    => [['media_id', 'media', 'id', 'SET NULL']],
        'index' => ['idx_projects_sort' => ['sort']],
    ]);
}];
