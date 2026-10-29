<?php
/**
 * The house style in code (spec 4.1.3, 11.6), adapted from NHF.
 *
 * problems() is the admin copy linter: it warns on save and in the SEO panel,
 * and saving still works. text()/fixDashes() replace em and en dashes. They
 * never run silently on staff content: only on seed files (W9) and behind the
 * explicit "Fix dashes" button. NHF's clinic word swaps are gone.
 */
defined('ICM') or exit;

final class Plain
{
    /** Spec 11.6. Matched as word starts, so "leveraging" and "unlocked" count too. */
    public const BANNED = [
        'leverage', 'seamless', 'empower', 'unlock', 'robust', 'actionable', 'data-driven', 'solutions',
        'testament', 'landscape', 'delve', 'elevate',
    ];

    /** Words that start like a banned word but are fine. */
    private const BANNED_OK = ['elevator', 'elevators'];

    /** US spelling => the Canadian form ICM uses. */
    public const SPELLING = [
        'color' => 'colour', 'colors' => 'colours', 'colored' => 'coloured',
        'center' => 'centre', 'centers' => 'centres', 'centered' => 'centred',
        'programme' => 'program', 'programmes' => 'programs',
        'behavior' => 'behaviour', 'behaviors' => 'behaviours',
        'favorite' => 'favourite', 'favorites' => 'favourites',
        'organize' => 'organise', 'organized' => 'organised', 'organizing' => 'organising',
    ];

    /** Proper names that may contain a flagged spelling (spec 11.6 allowlist). */
    public const SPELLING_ALLOW = ['Canada Christian College'];

    /**
     * What the copy gate would flag in a piece of copy (HTML or text).
     * @return list<string> plain-language warnings, empty when clean
     */
    public static function problems(?string $html): array
    {
        $text = self::visible((string)$html);
        if ($text === '') return [];
        $found = [];

        if (preg_match('/[\x{2014}\x{2013}]/u', $text)) {
            $found[] = 'Has an em dash or en dash. Use a comma or a hyphen instead ("Fix dashes" does it for you).';
        }
        foreach (self::BANNED as $w) {
            if (!preg_match_all('/\b' . preg_quote($w, '/') . '[a-z]*/iu', $text, $m)) continue;
            $hits = array_filter($m[0], fn($x) => !in_array(strtolower($x), self::BANNED_OK, true));
            if ($hits) $found[] = 'Uses "' . strtolower((string)reset($hits)) . '", which is on the house style list. Try a plainer word.';
        }
        if (preg_match('/\bit(?:\'|\x{2019})?s not just .{1,40}?, it(?:\'|\x{2019})?s\b/iu', $text)
            || preg_match('/\bit is not just .{1,40}?, it is\b/iu', $text)) {
            $found[] = 'Uses the "it is not just X, it is Y" pattern. Say the point directly.';
        }
        if (preg_match('/\bmany (experts|studies|people) (say|believe|agree)\b/iu', $text)) {
            $found[] = 'Has a vague attribution such as "many experts say". Name the source or drop it.';
        }
        if (preg_match('/\bthe future (looks|is) bright\b/iu', $text)) {
            $found[] = 'Ends on a generic line ("the future is bright"). End on a fact instead.';
        }
        $spell = $text;
        foreach (self::SPELLING_ALLOW as $name) $spell = str_ireplace($name, ' ', $spell);
        foreach (self::SPELLING as $us => $ca) {
            if (preg_match('/\b' . preg_quote($us, '/') . '\b/iu', $spell)) {
                $found[] = 'US spelling "' . $us . '". ICM uses Canadian spelling: "' . $ca . '".';
            }
        }
        return $found;
    }

    /** True when the copy has an em or en dash. */
    public static function hasDashes(?string $s): bool
    {
        return (bool)preg_match('/[\x{2014}\x{2013}]/u', (string)$s);
    }

    /** Seed text clean-up (spec 3.4): dashes only. Safe on HTML. */
    public static function text(string $text): string
    {
        return self::fixDashes($text);
    }

    /**
     * Replace em and en dashes: a number range (6 en-dash 8, 2024 en-dash 25) gets a
     * hyphen, everything else a comma. Also " -- " between words. Safe on HTML.
     */
    public static function fixDashes(string $s): string
    {
        $s = (string)preg_replace('/(\d)\s*[\x{2013}\x{2014}]\s*(?=[\d$])/u', '$1-', $s);
        $s = (string)preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', $s);
        $s = (string)preg_replace('/(\w)\s+--\s+(\w)/u', '$1, $2', $s);
        $s = (string)preg_replace('/,\s*([.!?;:])/u', '$1', $s);
        $s = (string)preg_replace('/,\s*,/u', ',', $s);
        $s = (string)preg_replace('/,\s*(<\/[a-z0-9]+>)/iu', '$1', $s);      // "word, </p>"
        $s = (string)preg_replace('/(^|\n|<[a-z0-9]+(?:\s[^>]*)?>)\s*,\s*/iu', '$1', $s);   // a dash that opened a line
        return $s;
    }

    /** Visible text of a fragment: tags and entities gone, whitespace collapsed. */
    private static function visible(string $html): string
    {
        $html = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string)preg_replace('/<(br|\/p|\/li|\/h[1-6]|\/td|\/th|\/tr|\/blockquote|\/figcaption)\b[^>]*>/i', ' ', $html);
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }
}
