<?php
/**
 * One schema definition, emitted as MySQL/MariaDB or SQLite DDL (spec 3.1, 3.2).
 * Keeping it in PHP means the two drivers can never drift.
 *
 * Column definitions are "name alias [constraints]" where alias is one of:
 *   pk, pk64, uint (INT UNSIGNED), int, big, bool, tiny, small,
 *   dec(p,s), str(n) (VARCHAR), char(n), text, long (LONGTEXT), dt (DATETIME), date
 * Unique keys and indexes are created after the tables as CREATE [UNIQUE] INDEX
 * on both drivers. Foreign keys are inline table constraints (SQLite enforces
 * them through PRAGMA foreign_keys = ON in Db::connect).
 *
 * Later migrations add tables with Schema::createTable() and columns with
 * Schema::addColumn(); both are safe to re-run. Never edit this list for a
 * change after launch: write a new app/migrations file instead.
 */
defined('ICM') or exit;

final class Schema
{
    /** Resolve a column type alias for the current driver. */
    public static function type(string $t): string
    {
        $my = Db::isMysql();
        if (preg_match('/^str\((\d+)\)$/', $t, $m)) return $my ? "VARCHAR({$m[1]})" : 'TEXT';
        if (preg_match('/^char\((\d+)\)$/', $t, $m)) return $my ? "CHAR({$m[1]})" : 'TEXT';
        if (preg_match('/^dec\((\d+),(\d+)\)$/', $t, $m)) return $my ? "DECIMAL({$m[1]},{$m[2]})" : 'REAL';
        return match ($t) {
            'pk'    => $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'pk64'  => $my ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'uint', 'fk' => $my ? 'INT UNSIGNED' : 'INTEGER',
            'int'   => $my ? 'INT' : 'INTEGER',
            'big'   => $my ? 'BIGINT' : 'INTEGER',
            'bool'  => $my ? 'TINYINT(1)' : 'INTEGER',
            'tiny'  => $my ? 'TINYINT' : 'INTEGER',
            'small' => $my ? 'SMALLINT' : 'INTEGER',
            'text'  => 'TEXT',
            'long'  => $my ? 'LONGTEXT' : 'TEXT',
            'dt'    => $my ? 'DATETIME' : 'TEXT',
            'date'  => $my ? 'DATE' : 'TEXT',
            default => throw new InvalidArgumentException('Unknown column type alias: ' . $t),
        };
    }

    /**
     * The 51 tables of spec 3.2, in creation order.
     * Each: cols (list), pk (table-level primary key), fk ([col, table, refCol, onDelete|null]),
     * unique ([name => cols]), index ([name => cols]).
     *
     * @return array<string,array{cols:list<string>,pk?:list<string>,fk?:list<array>,unique?:array,index?:array}>
     */
    public static function tables(): array
    {
        return [
            // ---------------- platform ----------------
            'schema_migrations' => [
                'cols' => ['version str(32) NOT NULL', 'applied_at dt NOT NULL'],
                'pk'   => ['version'],
            ],
            'settings' => [
                'cols' => [
                    'skey str(191) NOT NULL', 'svalue long NULL', 'is_secret bool NOT NULL DEFAULT 0',
                    'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'pk' => ['skey'],
            ],
            'admin_users' => [
                'cols' => [
                    'id pk', 'email str(191) NOT NULL', 'pass_hash str(255) NOT NULL', 'name str(120) NOT NULL',
                    "role str(16) NOT NULL DEFAULT 'editor'", 'phone str(40) NULL', 'is_active bool NOT NULL DEFAULT 1',
                    'must_change_password bool NOT NULL DEFAULT 0', 'locked_until dt NULL', 'totp_secret_enc str(255) NULL',
                    'totp_enabled bool NOT NULL DEFAULT 0', 'totp_last_step uint NULL', 'last_login_at dt NULL',
                    'created_at dt NOT NULL', 'updated_at dt NULL',
                ],
                'unique' => ['uq_admin_users_email' => ['email']],
            ],
            'admin_remember_tokens' => [
                'cols' => [
                    'id pk', 'user_id uint NOT NULL', 'selector char(24) NOT NULL', 'validator_hash char(64) NOT NULL',
                    'user_agent str(255) NULL', 'expires_at dt NOT NULL', 'created_at dt NOT NULL', 'last_used_at dt NULL',
                ],
                'fk'     => [['user_id', 'admin_users', 'id', 'CASCADE']],
                'unique' => ['uq_remember_selector' => ['selector']],
            ],
            'admin_login_attempts' => [
                'cols' => [
                    'id pk64', 'email_hash char(64) NOT NULL', 'ip_hash char(64) NOT NULL', 'success bool NOT NULL',
                    'created_at dt NOT NULL',
                ],
                'index' => ['idx_login_ip' => ['ip_hash', 'created_at'], 'idx_login_email' => ['email_hash', 'created_at']],
            ],
            'audit_log' => [
                'cols' => [
                    'id pk64', 'user_id uint NULL', 'action str(24) NOT NULL', 'entity str(40) NOT NULL',
                    'entity_id str(64) NULL', 'summary str(255) NULL', 'detail_json long NULL', 'ip_hash char(64) NULL',
                    'created_at dt NOT NULL',
                ],
                'index' => ['idx_audit_entity' => ['entity', 'entity_id'], 'idx_audit_created' => ['created_at']],
            ],
            'rate_limits' => [
                'cols' => [
                    'bucket str(32) NOT NULL', 'key_hash char(64) NOT NULL', 'window_start uint NOT NULL',
                    'hits int NOT NULL DEFAULT 0',
                ],
                'pk' => ['bucket', 'key_hash', 'window_start'],
            ],
            'media' => [
                'cols' => [
                    'id pk', "kind str(10) NOT NULL DEFAULT 'image'", 'path str(255) NOT NULL', 'original_name str(255) NULL',
                    'mime str(80) NOT NULL', 'bytes uint NOT NULL DEFAULT 0', 'width uint NULL', 'height uint NULL',
                    'variants_json text NULL', 'alt str(300) NULL', 'caption str(300) NULL', 'credit str(160) NULL',
                    'is_real_photo bool NOT NULL DEFAULT 0', 'is_ai_generated bool NOT NULL DEFAULT 0',
                    'focal_x dec(4,3) NOT NULL DEFAULT 0.500', 'focal_y dec(4,3) NOT NULL DEFAULT 0.400',
                    'is_private bool NOT NULL DEFAULT 0', 'access_token char(64) NULL', 'legacy_url str(191) NULL',
                    'uploaded_by uint NULL', 'created_at dt NOT NULL', 'updated_at dt NULL',
                ],
                'unique' => ['uq_media_token' => ['access_token']],
                'index'  => ['idx_media_legacy' => ['legacy_url']],
            ],

            // ---------------- places, programs, courses ----------------
            'locations' => [
                'cols' => [
                    'id pk', 'slug str(60) NOT NULL', 'name str(80) NOT NULL', 'region str(60) NULL',
                    'hub_page_path str(191) NULL', "status str(16) NOT NULL DEFAULT 'open'", 'status_note str(255) NULL',
                    'opens_on date NULL', 'waitlist_enabled bool NOT NULL DEFAULT 1', 'show_in_menu bool NOT NULL DEFAULT 1',
                    'show_in_footer bool NOT NULL DEFAULT 1', 'show_in_finder bool NOT NULL DEFAULT 1',
                    'sort int NOT NULL DEFAULT 0', 'updated_at dt NULL',
                ],
                'unique' => ['uq_locations_slug' => ['slug']],
            ],
            'venues' => [
                'cols' => [
                    'id pk', 'location_id uint NULL', 'slug str(80) NOT NULL', 'name str(120) NOT NULL',
                    'street str(160) NULL', 'city str(80) NOT NULL', "province char(2) NOT NULL DEFAULT 'ON'",
                    'postal str(10) NULL', "country char(2) NOT NULL DEFAULT 'CA'", 'lat dec(9,6) NULL', 'lng dec(9,6) NULL',
                    "setting str(8) NOT NULL DEFAULT 'outdoor'", 'season_summer_spring bool NOT NULL DEFAULT 0',
                    'season_fall_winter bool NOT NULL DEFAULT 0', 'notes str(255) NULL', 'is_public bool NOT NULL DEFAULT 1',
                    'sort int NOT NULL DEFAULT 0', 'updated_at dt NULL',
                ],
                'fk'     => [['location_id', 'locations', 'id', 'SET NULL']],
                'unique' => ['uq_venues_slug' => ['slug']],
            ],
            'programs' => [
                'cols' => [
                    'id pk', 'slug str(60) NOT NULL', 'name str(120) NOT NULL', 'audience str(16) NOT NULL',
                    'kind str(16) NOT NULL', 'summary str(300) NULL', 'description_html long NULL', 'page_path str(191) NULL',
                    'sort int NOT NULL DEFAULT 0', 'is_active bool NOT NULL DEFAULT 1',
                ],
                'unique' => ['uq_programs_slug' => ['slug']],
            ],
            'terms' => [
                'cols' => [
                    'id pk', 'slug str(60) NOT NULL', 'label str(120) NOT NULL', 'season str(16) NOT NULL',
                    'starts_on date NULL', 'ends_on date NULL', 'registration_opens_on date NULL',
                    'is_current bool NOT NULL DEFAULT 0', 'archived_at dt NULL', 'sort int NOT NULL DEFAULT 0',
                ],
                'unique' => ['uq_terms_slug' => ['slug']],
            ],
            'courses' => [
                'cols' => [
                    'id pk', 'code str(40) NULL', 'term_id uint NULL', 'program_id uint NOT NULL', 'location_id uint NULL',
                    'venue_id uint NULL', 'title str(160) NOT NULL', 'jotform_title str(200) NULL', 'level str(20) NULL',
                    'prerequisite str(300) NULL', 'description_html long NULL', 'start_text str(300) NULL',
                    'start_date date NULL', 'session_length str(60) NULL', "billing str(14) NOT NULL DEFAULT 'monthly'",
                    'regular_price_cents int NULL', 'promo_price_cents int NULL', 'promo_label str(80) NULL',
                    'promo_ends_on date NULL', 'price_suffix str(40) NULL', 'tax_rate_bp int NOT NULL DEFAULT 1300',
                    'price_note str(200) NULL', 'includes_html text NULL', 'jotform_id str(20) NULL',
                    "jotform_host str(4) NOT NULL DEFAULT 'form'", 'jotform_checked_at date NULL', 'external_url str(255) NULL',
                    "embed_mode str(6) NOT NULL DEFAULT 'link'", 'embed_page_path str(191) NULL',
                    "status str(16) NOT NULL DEFAULT 'open'", "status_mode str(8) NOT NULL DEFAULT 'manual'",
                    'status_note str(255) NULL', 'opens_on date NULL', 'auto_open bool NOT NULL DEFAULT 1',
                    'waitlist_enabled bool NOT NULL DEFAULT 1', 'finder_enabled bool NOT NULL DEFAULT 1',
                    'image_media_id uint NULL', 'visible_from date NULL', 'visible_until date NULL',
                    'sort int NOT NULL DEFAULT 0', 'created_at dt NOT NULL', 'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'fk' => [
                    ['term_id', 'terms', 'id', 'SET NULL'],
                    ['program_id', 'programs', 'id', 'RESTRICT'],
                    ['location_id', 'locations', 'id', 'SET NULL'],
                    ['venue_id', 'venues', 'id', 'SET NULL'],
                    ['image_media_id', 'media', 'id', 'SET NULL'],
                ],
                'unique' => ['uq_courses_code' => ['code']],
            ],
            'course_age_groups' => [
                'cols' => [
                    'id pk', 'course_id uint NOT NULL', 'group_key str(8) NOT NULL', 'label str(80) NOT NULL',
                    'min_age tiny NULL', 'max_age tiny NULL', 'birth_years str(80) NULL',
                    "status str(16) NOT NULL DEFAULT 'open'", 'sort int NOT NULL DEFAULT 0',
                ],
                'fk'     => [['course_id', 'courses', 'id', 'CASCADE']],
                'unique' => ['uq_cag' => ['course_id', 'group_key']],
            ],
            'course_slots' => [
                'cols' => [
                    'id pk', 'course_id uint NOT NULL', 'age_group_id uint NULL', 'weekday tiny NOT NULL',
                    'start_time char(5) NOT NULL', 'end_time char(5) NOT NULL', 'label str(160) NULL', 'capacity small NULL',
                    'spots_left small NULL', "status str(12) NOT NULL DEFAULT 'available'", 'sort int NOT NULL DEFAULT 0',
                    'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'fk' => [
                    ['course_id', 'courses', 'id', 'CASCADE'],
                    ['age_group_id', 'course_age_groups', 'id', 'SET NULL'],
                ],
                'index' => ['idx_slots_course' => ['course_id', 'sort']],
            ],
            'camp_weeks' => [
                'cols' => [
                    'id pk', 'course_id uint NOT NULL', 'week_start date NOT NULL', 'week_end date NOT NULL',
                    'day_option str(4) NOT NULL', 'hours_label str(40) NOT NULL', 'price_cents int NOT NULL',
                    "status str(12) NOT NULL DEFAULT 'available'", 'sort int NOT NULL DEFAULT 0',
                ],
                'fk'     => [['course_id', 'courses', 'id', 'CASCADE']],
                'unique' => ['uq_camp_week' => ['course_id', 'week_start', 'day_option']],
            ],
            'finder_overrides' => [
                'cols' => [
                    'id pk', 'age_key str(8) NOT NULL', 'experience_key str(12) NOT NULL', 'location_slug str(60) NOT NULL',
                    'course_id uint NULL', 'target_path str(255) NULL', 'message str(300) NULL',
                ],
                'fk'     => [['course_id', 'courses', 'id', 'CASCADE']],
                'unique' => ['uq_finder' => ['age_key', 'experience_key', 'location_slug']],
            ],

            // ---------------- pages and navigation ----------------
            'pages' => [
                'cols' => [
                    'id pk', 'path str(191) NOT NULL', 'template str(24) NOT NULL', 'title str(160) NOT NULL',
                    'h1 str(200) NULL', 'seo_title str(200) NULL', 'meta_description str(320) NULL', 'og_media_id uint NULL',
                    'intro_html long NULL', 'body_html long NULL', 'data_json long NULL', 'location_id uint NULL',
                    'program_id uint NULL', 'course_id uint NULL', 'parent_id uint NULL',
                    "robots str(24) NOT NULL DEFAULT 'index,follow'", 'in_sitemap bool NOT NULL DEFAULT 1',
                    "status str(12) NOT NULL DEFAULT 'published'", 'legacy_wp_id uint NULL', 'sort int NOT NULL DEFAULT 0',
                    'created_at dt NOT NULL', 'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'fk' => [
                    ['og_media_id', 'media', 'id', 'SET NULL'],
                    ['location_id', 'locations', 'id', 'SET NULL'],
                    ['program_id', 'programs', 'id', 'SET NULL'],
                    ['course_id', 'courses', 'id', 'SET NULL'],
                    ['parent_id', 'pages', 'id', 'SET NULL'],
                ],
                'unique' => ['uq_pages_path' => ['path']],
                'index'  => ['idx_pages_legacy' => ['legacy_wp_id']],
            ],
            'blocks' => [
                'cols' => [
                    'id pk', 'page_id uint NULL', 'block_key str(80) NULL', 'type str(20) NOT NULL', 'title str(200) NULL',
                    'body_html long NULL', 'data_json long NULL', 'max_chars int NULL', 'sort int NOT NULL DEFAULT 0',
                    'is_visible bool NOT NULL DEFAULT 1', 'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'fk'     => [['page_id', 'pages', 'id', 'CASCADE']],
                'unique' => ['uq_blocks_key' => ['block_key']],
                'index'  => ['idx_blocks_page' => ['page_id', 'sort']],
            ],
            'menu_items' => [
                'cols' => [
                    'id pk', 'menu str(20) NOT NULL', 'parent_id uint NULL', 'label str(120) NOT NULL', 'path str(255) NULL',
                    'location_id uint NULL', 'opens_new_tab bool NOT NULL DEFAULT 0', 'sort int NOT NULL DEFAULT 0',
                    'is_visible bool NOT NULL DEFAULT 1',
                ],
                'fk' => [
                    ['parent_id', 'menu_items', 'id', 'CASCADE'],
                    ['location_id', 'locations', 'id', 'SET NULL'],
                ],
                'index' => ['idx_menu' => ['menu', 'parent_id', 'sort']],
            ],

            // ---------------- rankings ----------------
            'ranking_series' => [
                'cols' => [
                    'id pk', 'slug str(80) NOT NULL', 'name str(160) NOT NULL', 'subtitle str(200) NULL',
                    'program_type str(16) NOT NULL', 'level_label str(80) NULL', "format str(10) NOT NULL DEFAULT 'singles'",
                    'weekday tiny NULL', 'start_time char(5) NULL', 'end_time char(5) NULL', 'venue_id uint NULL',
                    'location_id uint NULL', 'start_date date NULL', 'end_date date NULL', 'fee_text str(120) NULL',
                    'fee_cents int NULL', 'capacity small NULL', "signup_mode str(8) NOT NULL DEFAULT 'both'",
                    'signup_deadline_text str(160) NULL', 'contact_text str(160) NULL', 'badge str(20) NULL',
                    "status str(10) NOT NULL DEFAULT 'upcoming'", "tie_method str(12) NOT NULL DEFAULT 'dense'",
                    "name_format str(14) NOT NULL DEFAULT 'first_initial'", "point_presets str(60) NOT NULL DEFAULT '0,20,40,60'",
                    'notes_html text NULL', 'sort int NOT NULL DEFAULT 0', 'created_at dt NOT NULL', 'updated_at dt NULL',
                ],
                'fk' => [
                    ['venue_id', 'venues', 'id', 'SET NULL'],
                    ['location_id', 'locations', 'id', 'SET NULL'],
                ],
                'unique' => ['uq_series_slug' => ['slug']],
            ],
            'ranking_categories' => [
                'cols' => [
                    'id pk', 'series_id uint NOT NULL', 'label str(60) NOT NULL', 'age_min tiny NULL', 'age_max tiny NULL',
                    'ball_colour str(10) NULL', 'show_medals bool NOT NULL DEFAULT 1', 'sort int NOT NULL DEFAULT 0',
                ],
                'fk' => [['series_id', 'ranking_series', 'id', 'CASCADE']],
            ],
            'players' => [
                'cols' => [
                    'id pk', 'first_name str(80) NOT NULL', 'last_name str(80) NULL', 'display_override str(120) NULL',
                    'birth_year small NULL', 'is_minor bool NOT NULL DEFAULT 1', 'name_consent str(14) NULL',
                    'guardian_phone str(40) NULL', 'guardian_email str(191) NULL', 'notes str(255) NULL',
                    'merged_into_id uint NULL', 'is_active bool NOT NULL DEFAULT 1', 'created_at dt NOT NULL',
                    'updated_at dt NULL',
                ],
                'index' => ['idx_players_name' => ['last_name', 'first_name']],
            ],
            'ranking_events' => [
                'cols' => [
                    'id pk', 'series_id uint NOT NULL', 'event_date date NOT NULL', 'venue_id uint NULL',
                    'is_championship bool NOT NULL DEFAULT 0', 'is_cancelled bool NOT NULL DEFAULT 0', 'notes str(255) NULL',
                ],
                'fk' => [
                    ['series_id', 'ranking_series', 'id', 'CASCADE'],
                    ['venue_id', 'venues', 'id', 'SET NULL'],
                ],
                'unique' => ['uq_event' => ['series_id', 'event_date']],
            ],
            'ranking_results' => [
                'cols' => [
                    'id pk', 'event_id uint NOT NULL', 'category_id uint NOT NULL', 'player_id uint NOT NULL',
                    'points int NOT NULL DEFAULT 0', 'placement small NULL', 'client_uuid char(36) NULL', 'client_ts big NULL',
                    'entered_by uint NULL', 'updated_at dt NOT NULL',
                ],
                'fk' => [
                    ['event_id', 'ranking_events', 'id', 'CASCADE'],
                    ['category_id', 'ranking_categories', 'id', 'CASCADE'],
                    ['player_id', 'players', 'id', 'CASCADE'],
                ],
                'unique' => ['uq_result' => ['event_id', 'category_id', 'player_id']],
            ],
            'ranking_snapshots' => [
                'cols' => [
                    'id pk', 'series_id uint NOT NULL', 'category_id uint NOT NULL', 'slug str(191) NOT NULL',
                    'as_of_date date NOT NULL', 'title str(200) NOT NULL', 'is_final bool NOT NULL DEFAULT 0',
                    'tie_method str(12) NOT NULL', "source str(10) NOT NULL DEFAULT 'computed'", 'source_note str(255) NULL',
                    "status str(10) NOT NULL DEFAULT 'published'", 'published_at dt NULL', 'published_by uint NULL',
                ],
                'fk' => [
                    ['series_id', 'ranking_series', 'id', 'CASCADE'],
                    ['category_id', 'ranking_categories', 'id', 'CASCADE'],
                ],
                'unique' => [
                    'uq_snapshot_slug' => ['slug'],
                    'uq_snapshot_day'  => ['series_id', 'category_id', 'as_of_date'],
                ],
                'index' => ['idx_snapshot_cat' => ['category_id', 'as_of_date']],
            ],
            'ranking_snapshot_rows' => [
                'cols' => [
                    'id pk', 'snapshot_id uint NOT NULL', 'row_order small NOT NULL', 'rank_label str(8) NOT NULL',
                    'player_id uint NULL', 'name_as_published str(160) NOT NULL', 'points int NOT NULL', 'delta int NULL',
                ],
                'fk' => [
                    ['snapshot_id', 'ranking_snapshots', 'id', 'CASCADE'],
                    ['player_id', 'players', 'id', 'SET NULL'],
                ],
                'unique' => ['uq_snap_row' => ['snapshot_id', 'row_order']],
            ],

            // ---------------- calendar ----------------
            'calendar_events' => [
                'cols' => [
                    'id pk', 'uid str(191) NOT NULL', 'title str(200) NOT NULL', 'type str(20) NOT NULL',
                    'starts_on date NOT NULL', 'ends_on date NOT NULL', 'all_day bool NOT NULL DEFAULT 1',
                    'start_time char(5) NULL', 'end_time char(5) NULL', 'location_id uint NULL', 'venue_id uint NULL',
                    'applies_all_locations bool NOT NULL DEFAULT 1', 'description text NULL', 'url str(255) NULL',
                    'rrule str(255) NULL', 'exdates text NULL', "source str(10) NOT NULL DEFAULT 'manual'",
                    'is_public bool NOT NULL DEFAULT 1', 'created_at dt NOT NULL', 'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'fk' => [
                    ['location_id', 'locations', 'id', 'SET NULL'],
                    ['venue_id', 'venues', 'id', 'SET NULL'],
                ],
                'unique' => ['uq_cal_uid' => ['uid']],
                'index'  => ['idx_cal_dates' => ['starts_on', 'ends_on']],
            ],

            // ---------------- news ----------------
            'posts' => [
                'cols' => [
                    'id pk', 'slug str(191) NOT NULL', "category str(40) NOT NULL DEFAULT 'tips'",
                    'title str(250) NOT NULL', 'seo_title str(200) NULL', 'meta_description str(320) NULL', 'excerpt text NULL',
                    'body_html long NULL', 'featured_media_id uint NULL', 'featured_alt str(300) NULL', 'tags str(255) NULL',
                    "author_name str(80) NOT NULL DEFAULT ''", "status str(10) NOT NULL DEFAULT 'draft'",
                    'published_at dt NULL', 'legacy_wp_id uint NULL', 'legacy_path str(191) NULL',
                    'is_featured bool NOT NULL DEFAULT 0', 'views int NOT NULL DEFAULT 0', 'created_at dt NOT NULL',
                    'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'fk'     => [['featured_media_id', 'media', 'id', 'SET NULL']],
                'unique' => ['uq_posts_slug' => ['slug']],
                'index'  => ['idx_posts_pub' => ['status', 'published_at'], 'idx_posts_legacy' => ['legacy_wp_id']],
            ],
            'post_media' => [
                'cols' => ['post_id uint NOT NULL', 'media_id uint NOT NULL', 'sort int NOT NULL DEFAULT 0', 'caption str(300) NULL'],
                'pk'   => ['post_id', 'media_id'],
                'fk'   => [
                    ['post_id', 'posts', 'id', 'CASCADE'],
                    ['media_id', 'media', 'id', 'CASCADE'],
                ],
            ],

            // ---------------- newsletter ----------------
            'consent_texts' => [
                'cols' => [
                    'id pk', 'version str(40) NOT NULL', 'wording text NOT NULL', 'form_url str(255) NULL',
                    'created_at dt NOT NULL',
                ],
                'unique' => ['uq_consent_version' => ['version']],
            ],
            'newsletter_subscribers' => [
                'cols' => [
                    'id pk', 'email str(191) NOT NULL', 'email_norm str(191) NOT NULL', 'first_name str(100) NULL',
                    "status str(12) NOT NULL DEFAULT 'pending'", "consent_type str(16) NOT NULL DEFAULT 'unknown'",
                    'consent_at dt NULL', 'consent_expires_at dt NULL', 'consent_source str(40) NULL',
                    'consent_text_id uint NULL', 'consent_ip str(45) NULL', 'consent_user_agent str(255) NULL',
                    'consent_page str(191) NULL', 'confirm_token_hash char(64) NULL', 'confirm_sent_at dt NULL',
                    'confirmed_at dt NULL', 'confirmed_ip str(45) NULL', 'unsub_token char(64) NOT NULL',
                    'unsubscribed_at dt NULL', 'unsub_method str(10) NULL', 'soft_bounce_count tiny NOT NULL DEFAULT 0',
                    'tags str(255) NULL', 'location_pref str(60) NULL', 'mc_leid str(32) NULL', 'mc_euid str(32) NULL',
                    'mc_member_rating tiny NULL', 'created_at dt NOT NULL', 'updated_at dt NULL',
                ],
                'fk'     => [['consent_text_id', 'consent_texts', 'id', 'SET NULL']],
                'unique' => ['uq_sub_email' => ['email_norm'], 'uq_sub_token' => ['unsub_token']],
                'index'  => ['idx_sub_status' => ['status'], 'idx_sub_expiry' => ['consent_expires_at']],
            ],
            'consent_log' => [   // append-only; no FK on purpose (evidence outlives the subscriber row)
                'cols' => [
                    'id pk64', 'subscriber_id uint NULL', 'email_norm str(191) NOT NULL', 'event str(16) NOT NULL',
                    'at dt NOT NULL', 'ip str(45) NULL', 'user_agent str(255) NULL', 'source str(40) NULL',
                    'detail_json long NULL',
                ],
                'index' => ['idx_consent_email' => ['email_norm', 'at']],
            ],
            'suppressions' => [
                'cols' => [
                    'email_norm str(191) NOT NULL', 'reason str(20) NOT NULL', 'note str(255) NULL', 'created_at dt NOT NULL',
                ],
                'pk' => ['email_norm'],
            ],
            'campaigns' => [
                'cols' => [
                    'id pk', 'post_id uint NULL', 'subject str(200) NOT NULL', 'preheader str(200) NULL',
                    'body_html long NOT NULL', 'body_text long NOT NULL', 'from_name str(100) NOT NULL',
                    'from_email str(191) NOT NULL', 'reply_to str(191) NOT NULL', 'audience_json text NULL',
                    "status str(10) NOT NULL DEFAULT 'draft'", 'pause_reason str(255) NULL', 'scheduled_at dt NULL',
                    'queued_at dt NULL', 'started_at dt NULL', 'finished_at dt NULL',
                    'total_recipients int NOT NULL DEFAULT 0', 'sent_count int NOT NULL DEFAULT 0',
                    'failed_count int NOT NULL DEFAULT 0', 'skipped_count int NOT NULL DEFAULT 0',
                    'bounce_count int NOT NULL DEFAULT 0', 'complaint_count int NOT NULL DEFAULT 0',
                    'unsub_count int NOT NULL DEFAULT 0', 'created_by uint NULL', 'created_at dt NOT NULL', 'updated_at dt NULL',
                ],
                'fk' => [['post_id', 'posts', 'id', 'SET NULL']],
            ],
            'send_queue' => [
                'cols' => [
                    'id pk64', 'campaign_id uint NOT NULL', 'subscriber_id uint NOT NULL', 'email str(191) NOT NULL',
                    "status str(8) NOT NULL DEFAULT 'queued'", 'attempts tiny NOT NULL DEFAULT 0',
                    'next_attempt_at dt NOT NULL', 'locked_by char(16) NULL', 'locked_at dt NULL', 'sent_at dt NULL',
                    'provider_message_id str(191) NULL', 'last_error str(500) NULL',
                ],
                'fk'     => [['campaign_id', 'campaigns', 'id', 'CASCADE']],
                'unique' => ['uq_queue' => ['campaign_id', 'subscriber_id']],
                'index'  => [
                    'idx_queue_pick' => ['status', 'next_attempt_at'],
                    'idx_queue_msg'  => ['provider_message_id'],
                    'idx_queue_sent' => ['sent_at'],
                ],
            ],
            'email_events' => [
                'cols' => [
                    'id pk64', 'provider str(12) NOT NULL', 'provider_event_id str(191) NULL',
                    'provider_message_id str(191) NULL', 'email_norm str(191) NULL', 'campaign_id uint NULL',
                    'type str(12) NOT NULL', 'subtype str(32) NULL', 'raw_json long NOT NULL', 'received_at dt NOT NULL',
                ],
                'unique' => ['uq_event_provider' => ['provider_event_id']],
                'index'  => ['idx_ev_email' => ['email_norm']],
            ],
            'mail_log' => [
                'cols' => [
                    'id pk64', 'stream str(14) NOT NULL', 'mailbox str(191) NOT NULL', 'to_hash char(64) NOT NULL',
                    'subject str(200) NULL', 'ok bool NOT NULL', 'info str(500) NULL', 'created_at dt NOT NULL',
                ],
                'index' => ['idx_mail_box' => ['mailbox', 'created_at']],
            ],

            // ---------------- forms ----------------
            'form_types' => [
                'cols' => [
                    'type_key str(20) NOT NULL', 'label str(80) NOT NULL', 'is_enabled bool NOT NULL DEFAULT 1',
                    'recipients str(500) NULL', 'send_autoreply bool NOT NULL DEFAULT 0', 'autoreply_subject str(200) NULL',
                    'autoreply_body text NULL', 'retention_days int NOT NULL DEFAULT 730', 'updated_at dt NULL',
                ],
                'pk' => ['type_key'],
            ],
            'form_submissions' => [
                'cols' => [
                    'id pk', 'form_type str(20) NOT NULL', "status str(12) NOT NULL DEFAULT 'new'", 'name str(160) NULL',
                    'email str(191) NULL', 'phone str(40) NULL', 'subject str(200) NULL', 'location_id uint NULL',
                    'course_id uint NULL', 'ranking_series_id uint NULL', 'event_date date NULL', 'payload_json long NOT NULL',
                    'spam_score tiny NOT NULL DEFAULT 0', 'spam_reason str(120) NULL', 'source_path str(191) NULL',
                    'ip_hash char(64) NULL', 'user_agent str(255) NULL', 'assigned_to uint NULL', 'notified_ok bool NULL',
                    'created_at dt NOT NULL', 'handled_at dt NULL', 'updated_at dt NULL',
                ],
                'fk' => [
                    ['form_type', 'form_types', 'type_key', null],
                    ['location_id', 'locations', 'id', 'SET NULL'],
                    ['course_id', 'courses', 'id', 'SET NULL'],
                ],
                'index' => [
                    'idx_fs_type'    => ['form_type', 'status', 'created_at'],
                    'idx_fs_course'  => ['course_id', 'created_at'],
                    'idx_fs_session' => ['ranking_series_id', 'event_date', 'status'],
                ],
            ],
            'form_files' => [
                'cols' => [
                    'id pk', 'submission_id uint NOT NULL', 'stored_path str(255) NOT NULL', 'original_name str(200) NOT NULL',
                    'mime str(80) NOT NULL', 'bytes uint NOT NULL', 'sha256 char(64) NOT NULL', 'created_at dt NOT NULL',
                ],
                'fk' => [['submission_id', 'form_submissions', 'id', 'CASCADE']],
            ],
            'form_notes' => [
                'cols' => [
                    'id pk', 'submission_id uint NOT NULL', 'user_id uint NULL', 'note text NOT NULL', 'created_at dt NOT NULL',
                ],
                'fk' => [['submission_id', 'form_submissions', 'id', 'CASCADE']],
            ],

            // ---------------- content lists ----------------
            'certificates' => [
                'cols' => [
                    'id pk', 'certificate_number str(40) NOT NULL', 'number_norm str(40) NOT NULL',
                    'player_name str(160) NOT NULL', 'pathway str(16) NOT NULL', 'level_code str(20) NULL',
                    'level_name str(120) NOT NULL', 'issue_date date NOT NULL', 'issuer str(120) NULL',
                    'location_id uint NULL', 'file_media_id uint NULL', "status str(8) NOT NULL DEFAULT 'valid'",
                    'notes str(255) NULL', 'created_at dt NOT NULL', 'updated_at dt NULL', 'updated_by uint NULL',
                ],
                'fk' => [
                    ['location_id', 'locations', 'id', 'SET NULL'],
                    ['file_media_id', 'media', 'id', 'SET NULL'],
                ],
                'unique' => ['uq_cert_number' => ['number_norm']],
            ],
            'faqs' => [
                'cols' => [
                    'id pk', 'question str(300) NOT NULL', 'answer_html text NOT NULL',
                    "category str(40) NOT NULL DEFAULT 'general'", 'show_on str(255) NULL', 'sort int NOT NULL DEFAULT 0',
                    'is_visible bool NOT NULL DEFAULT 1', 'updated_at dt NULL',
                ],
            ],
            'jobs' => [
                'cols' => [
                    'id pk', 'slug str(80) NOT NULL', 'title str(160) NOT NULL', 'location_text str(120) NULL',
                    'employment_type str(16) NULL', 'summary str(300) NULL', 'description_html text NOT NULL',
                    'contact_email str(191) NULL', "status str(8) NOT NULL DEFAULT 'open'", 'posted_on date NOT NULL',
                    'closes_on date NULL', 'sort int NOT NULL DEFAULT 0', 'updated_at dt NULL',
                ],
                'unique' => ['uq_jobs_slug' => ['slug']],
            ],
            'staff' => [
                'cols' => [
                    'id pk', 'slug str(80) NOT NULL', 'name str(120) NOT NULL', 'known_as str(80) NULL',
                    'role str(120) NOT NULL', 'email str(191) NULL', 'phone str(40) NULL', 'photo_media_id uint NULL',
                    'bio_html text NULL', 'credentials_json text NULL', 'show_on_about bool NOT NULL DEFAULT 1',
                    'show_on_contact bool NOT NULL DEFAULT 1', 'sort int NOT NULL DEFAULT 0', 'updated_at dt NULL',
                ],
                'fk'     => [['photo_media_id', 'media', 'id', 'SET NULL']],
                'unique' => ['uq_staff_slug' => ['slug']],
            ],
            'testimonials' => [
                'cols' => [
                    'id pk', 'author_display str(80) NOT NULL', 'context str(120) NULL', 'quote text NOT NULL',
                    'source str(12) NOT NULL', 'source_url str(255) NULL', 'consent_recorded bool NOT NULL DEFAULT 0',
                    'rating tiny NULL', 'is_visible bool NOT NULL DEFAULT 0', 'sort int NOT NULL DEFAULT 0',
                    'created_at dt NOT NULL',
                ],
            ],
            'pathway_levels' => [
                'cols' => [
                    'id pk', 'pathway str(16) NOT NULL', 'code str(12) NOT NULL', 'name str(120) NOT NULL',
                    'stage_group str(40) NULL', 'ages str(20) NULL', 'duration_label str(40) NULL', 'focus_json text NULL',
                    'price_text str(120) NULL', 'sort int NOT NULL DEFAULT 0',
                ],
                'unique' => ['uq_pathway' => ['pathway', 'code']],
            ],

            // ---------------- routing and search ----------------
            'redirects' => [
                'cols' => [
                    // source is VARCHAR(12), not the spec's 10: 'slug_change' is 11 characters.
                    'id pk', 'from_path str(191) NOT NULL', 'to_path str(255) NOT NULL', 'code small NOT NULL DEFAULT 301',
                    "source str(12) NOT NULL DEFAULT 'seed'", 'note str(255) NULL', 'hits int NOT NULL DEFAULT 0',
                    'last_hit_at dt NULL', 'created_at dt NOT NULL',
                ],
                'unique' => ['uq_redirect_from' => ['from_path']],
            ],
            'not_found_log' => [
                'cols' => [
                    'path_hash char(64) NOT NULL', 'path str(500) NOT NULL', 'hits int NOT NULL DEFAULT 1',
                    'first_seen dt NOT NULL', 'last_seen dt NOT NULL', 'last_referrer str(255) NULL',
                ],
                'pk'    => ['path_hash'],
                'index' => ['idx_404_hits' => ['hits']],
            ],
            'search_index' => [
                'cols' => [
                    'entity str(12) NOT NULL', 'entity_id uint NOT NULL', 'path str(191) NOT NULL', 'title str(250) NOT NULL',
                    'body_text long NOT NULL', 'updated_at dt NOT NULL',
                ],
                'pk' => ['entity', 'entity_id'],
            ],
        ];
    }

    /** @return list<string> table names in creation order */
    public static function tableNames(): array
    {
        return array_keys(self::tables());
    }

    /** CREATE TABLE statement for the current driver. */
    public static function ddl(string $name, ?array $def = null): string
    {
        $def ??= self::tables()[$name] ?? throw new InvalidArgumentException('Unknown table ' . $name);
        $parts = array_map([self::class, 'column'], $def['cols']);
        if (!empty($def['pk'])) $parts[] = 'PRIMARY KEY (' . implode(', ', $def['pk']) . ')';
        foreach ($def['fk'] ?? [] as [$col, $refTable, $refCol, $onDelete]) {
            $parts[] = 'FOREIGN KEY (' . $col . ') REFERENCES ' . $refTable . '(' . $refCol . ')' . ($onDelete ? ' ON DELETE ' . $onDelete : '');
        }
        $suffix = Db::isMysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        return 'CREATE TABLE ' . $name . " (\n  " . implode(",\n  ", $parts) . "\n)" . $suffix;
    }

    /**
     * Create every missing table, then every missing index. Safe to run again.
     * @return list<string> the tables created by this call
     */
    public static function install(): array
    {
        $made = [];
        foreach (self::tables() as $name => $def) {
            if (self::createTable($name, $def)) $made[] = $name;
        }
        foreach (self::tables() as $name => $def) {
            self::createIndexes($name, $def);
        }
        Db::forgetColumns();
        return $made;
    }

    /** Create one table if it is missing (with its indexes). Returns true when created. */
    public static function createTable(string $name, array $def): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) throw new InvalidArgumentException('Bad table name');
        if (Db::tableExists($name)) return false;
        Db::run(self::ddl($name, $def));
        self::createIndexes($name, $def);
        return true;
    }

    public static function createIndexes(string $table, array $def): void
    {
        foreach ($def['unique'] ?? [] as $idx => $cols) self::addIndex($idx, $table, $cols, true);
        foreach ($def['index'] ?? [] as $idx => $cols) self::addIndex($idx, $table, $cols, false);
    }

    /** Create an index if missing. Returns true when created. */
    public static function addIndex(string $name, string $table, array $cols, bool $unique = false): bool
    {
        foreach (array_merge([$name, $table], $cols) as $id) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $id)) throw new InvalidArgumentException('Bad identifier ' . $id);
        }
        if (Db::indexExists($table, $name)) return false;
        Db::run('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $cols) . ')');
        return true;
    }

    /** Add a column (alias syntax: "notes str(255) NULL") if it is missing. Returns true when added. */
    public static function addColumn(string $table, string $colDef): bool
    {
        $name = strtok(trim($colDef), " \t");
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table) || !preg_match('/^[a-z_][a-z0-9_]*$/', (string)$name)) {
            throw new InvalidArgumentException('Bad identifier');
        }
        if (Db::hasColumn($table, (string)$name)) return false;
        Db::run('ALTER TABLE ' . $table . ' ADD COLUMN ' . self::column($colDef));
        Db::forgetColumns();
        return true;
    }

    /** Drop every table in tables() (installer "Start fresh", dev --fresh). Nothing else is touched. */
    public static function dropAll(): void
    {
        $names = array_reverse(self::tableNames());
        // Tables created by later migrations (Schema::createTable('...') in app/migrations/*.php)
        // go first, so a fresh start never keeps their stale rows (W5 roster and tombstones,
        // W7 recovery codes). The installer's "Start fresh" uses the same rule.
        foreach (glob(APP_DIR . '/migrations/*.php') ?: [] as $f) {
            if (preg_match_all("/Schema::createTable\\(\\s*'([a-z0-9_]+)'/", (string)file_get_contents($f), $m)) {
                foreach ($m[1] as $t) {
                    if (!in_array($t, $names, true)) array_unshift($names, $t);
                }
            }
        }
        if (Db::isMysql()) Db::run('SET FOREIGN_KEY_CHECKS = 0');
        else Db::pdo()->exec('PRAGMA foreign_keys = OFF');
        try {
            foreach ($names as $t) Db::run('DROP TABLE IF EXISTS ' . $t);
        } finally {
            if (Db::isMysql()) Db::run('SET FOREIGN_KEY_CHECKS = 1');
            else Db::pdo()->exec('PRAGMA foreign_keys = ON');
        }
        Db::forgetColumns();
        Db::flushSettings();
    }

    private static function column(string $def): string
    {
        $parts = preg_split('/\s+/', trim($def), 3) ?: [];
        [$name, $type, $rest] = array_pad($parts, 3, '');
        return $name . ' ' . self::type($type) . ($rest !== '' ? ' ' . $rest : '');
    }
}
