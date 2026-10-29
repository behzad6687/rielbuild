<?php
/**
 * Site search over search_index (spec 3.2, 5.2.7). LIKE on a normalised
 * body (tags stripped, lowercased, no accents), title matches ranked first.
 * Works the same on MySQL and SQLite; no FULLTEXT.
 *
 * Saving any indexed entity calls Search::index() (or Search::markDirty(),
 * and the nightly task rebuilds). Entities: page, post, faq, course, location.
 */
defined('ICM') or exit;

final class Search
{
    public const GROUPS = ['page' => 'pages', 'course' => 'pages', 'location' => 'pages', 'post' => 'news', 'faq' => 'questions'];

    /** Lowercase, accent-free, tag-free, single-spaced text. */
    public static function normalize(string $text): string
    {
        $t = Sanitizer::plain($text);
        $t = mb_strtolower($t, 'UTF-8');
        static $tr = false;
        if ($tr === false) $tr = class_exists('Transliterator') ? Transliterator::create('Any-Latin; Latin-ASCII') : null;
        if ($tr) {
            $t = (string)$tr->transliterate($t);
        } else {
            $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            if ($x !== false) $t = $x;
        }
        return trim(preg_replace('/\s+/', ' ', $t) ?? '');
    }

    /** Upsert one entity's row. $body may be HTML. */
    public static function index(string $entity, int $id, string $path, string $title, string $body): void
    {
        if (!isset(self::GROUPS[$entity])) throw new InvalidArgumentException('Unknown search entity ' . $entity);
        Db::upsert('search_index', ['entity', 'entity_id'], [
            'entity'     => $entity,
            'entity_id'  => $id,
            'path'       => mb_substr($path, 0, 191),
            'title'      => mb_substr(Sanitizer::plain($title), 0, 250),
            'body_text'  => self::normalize($title . ' ' . $body),
            'updated_at' => Time::nowUtc(),
        ]);
    }

    public static function remove(string $entity, int $id): void
    {
        Db::delete('search_index', 'entity = ? AND entity_id = ?', [$entity, $id]);
    }

    public static function markDirty(): void
    {
        Db::setSetting('search.dirty', '1');
    }

    public static function rebuildIfDirty(): bool
    {
        if (!Db::settingBool('search.dirty')) return false;
        self::rebuild();
        return true;
    }

    /**
     * Rebuild the whole index from the published content. Returns the row count.
     * FAQ rows link to /faqs/#faq-{id}: the faqs template gives each question that id.
     */
    public static function rebuild(): int
    {
        $now = Time::nowUtc();
        $rows = [];

        $blocks = [];
        foreach (Db::all('SELECT page_id, title, body_html FROM blocks WHERE page_id IS NOT NULL AND is_visible = 1 ORDER BY sort') as $b) {
            $blocks[(int)$b['page_id']][] = ($b['title'] ?? '') . ' ' . ($b['body_html'] ?? '');
        }
        foreach (Db::all("SELECT id, path, title, h1, intro_html, body_html, robots FROM pages WHERE status = 'published'") as $p) {
            if (str_contains((string)$p['robots'], 'noindex')) continue;
            $rows[] = ['page', (int)$p['id'], $p['path'], $p['h1'] ?: $p['title'],
                ($p['intro_html'] ?? '') . ' ' . ($p['body_html'] ?? '') . ' ' . implode(' ', $blocks[(int)$p['id']] ?? [])];
        }
        foreach (Db::all("SELECT id, slug, title, excerpt, body_html FROM posts WHERE status = 'published' AND published_at <= ?", [$now]) as $p) {
            $rows[] = ['post', (int)$p['id'], '/blog/' . $p['slug'] . '/', $p['title'], ($p['excerpt'] ?? '') . ' ' . ($p['body_html'] ?? '')];
        }
        foreach (Db::all('SELECT id, question, answer_html FROM faqs WHERE is_visible = 1') as $f) {
            $rows[] = ['faq', (int)$f['id'], '/faqs/#faq-' . $f['id'], $f['question'], $f['answer_html']];
        }
        foreach (Db::all(
            "SELECT c.id, c.title, c.description_html, c.start_text, c.embed_page_path, l.name AS loc, l.hub_page_path
               FROM courses c
               LEFT JOIN locations l ON l.id = c.location_id
               LEFT JOIN terms t ON t.id = c.term_id
              WHERE c.status <> 'hidden' AND (l.id IS NULL OR l.status <> 'hidden') AND t.archived_at IS NULL"
        ) as $c) {
            $path = $c['embed_page_path'] ?: ($c['hub_page_path'] ?: '/all-online-registrations/');
            $title = $c['title'] . ($c['loc'] ? ', ' . $c['loc'] : '');
            $rows[] = ['course', (int)$c['id'], $path, $title, ($c['description_html'] ?? '') . ' ' . ($c['start_text'] ?? '')];
        }
        foreach (Db::all("SELECT id, name, region, status_note, hub_page_path FROM locations WHERE status <> 'hidden' AND hub_page_path IS NOT NULL") as $l) {
            $rows[] = ['location', (int)$l['id'], $l['hub_page_path'], $l['name'], ($l['region'] ?? '') . ' ' . ($l['status_note'] ?? '')];
        }

        Db::tx(function () use ($rows) {
            Db::run('DELETE FROM search_index');
            foreach ($rows as [$entity, $id, $path, $title, $body]) {
                self::index($entity, $id, (string)$path, (string)$title, (string)$body);
            }
        });
        Db::setSetting('search.dirty', '0');
        return count($rows);
    }

    /**
     * Search. Every word (2+ characters, at most 6) must match. Title matches
     * rank first. Each result: entity, entity_id, path, title, snippet, group
     * ('pages' | 'news' | 'questions').
     */
    public static function query(string $q, int $limit = 30): array
    {
        $norm = self::normalize($q);
        $words = array_slice(array_values(array_filter(explode(' ', $norm), fn($w) => mb_strlen($w) >= 2)), 0, 6);
        if (!$words) return [];

        $where = [];
        $args = ['%' . Db::like($norm) . '%'];
        foreach ($words as $w) {
            $where[] = 'body_text LIKE ?' . Db::LIKE_ESCAPE;
            $args[] = '%' . Db::like($w) . '%';
        }
        $sql = 'SELECT entity, entity_id, path, title, body_text,
                       CASE WHEN LOWER(title) LIKE ?' . Db::LIKE_ESCAPE . ' THEN 0 ELSE 1 END AS title_rank
                  FROM search_index
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY title_rank, title
                 LIMIT ' . max(1, min(100, $limit));

        return array_map(static fn(array $r): array => [
            'entity'    => $r['entity'],
            'entity_id' => (int)$r['entity_id'],
            'path'      => $r['path'],
            'title'     => $r['title'],
            'snippet'   => self::snippet((string)$r['body_text'], $words[0]),
            'group'     => self::GROUPS[$r['entity']] ?? 'pages',
        ], Db::all($sql, $args));
    }

    /** About $len characters of normalised body around the first match. */
    public static function snippet(string $text, string $word, int $len = 160): string
    {
        $pos = mb_strpos($text, $word);
        $start = $pos === false ? 0 : max(0, $pos - (int)($len / 3));
        $cut = mb_substr($text, $start, $len);
        return ($start > 0 ? '...' : '') . trim($cut) . (mb_strlen($text) > $start + $len ? '...' : '');
    }
}
