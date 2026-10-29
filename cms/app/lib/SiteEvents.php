<?php
/**
 * One call after every save: SiteEvents::changed('menus'), SiteEvents::changed('pages').
 * Pages are never cached (no-cache headers), so a change shows on the next request once the
 * per-request memos and the file caches are cleared:
 *
 *   menus, layout, home     Content::flush()
 *   pages                   Sitemap::invalidate(), Search::markDirty(), Content::flush()
 *
 * Unknown events are ignored, so screens copied from the ICM admin keep working.
 * Returns the clears it ran, for tests.
 */
defined('ICM') or exit;

final class SiteEvents
{
    /** Event => the clears it needs. */
    public const EVENTS = [
        'menus'  => ['content'],
        'layout' => ['content'],
        'home'   => ['content'],
        'pages'  => ['sitemap', 'search', 'content'],
    ];

    /** @return list<string> the clears that ran (sitemap, search, content) */
    public static function changed(string ...$what): array
    {
        $need = [];
        foreach ($what as $w) foreach (self::EVENTS[$w] ?? ['content'] as $c) $need[$c] = true;
        $ran = [];
        foreach (['sitemap', 'search', 'content'] as $c) {
            if (!isset($need[$c])) continue;
            match ($c) {
                'sitemap' => Sitemap::invalidate(),
                'search'  => Search::markDirty(),
                'content' => Content::flush(),
            };
            $ran[] = $c;
        }
        return $ran;
    }
}
