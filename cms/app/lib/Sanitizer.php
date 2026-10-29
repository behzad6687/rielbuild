<?php
/**
 * DOM allowlist sanitiser for rich text (spec 7.5). Every rich-text save goes
 * through Sanitizer::html(), and legacy seed HTML is sanitised again at render.
 *
 * Allowed: h2 h3 h4 p br ul ol li strong em b i a[href,title] blockquote
 * figure figcaption img[src,alt,width,height] (src under /uploads/ or
 * /assets/) table thead tbody tr th[scope] td[colspan,rowspan].
 * script, style, iframe, object, form (and similar) are removed with their
 * content; any other element is unwrapped (its text is kept). Every on*,
 * style and class attribute is dropped. External links get rel="noopener"
 * and open in a new tab.
 */
defined('ICM') or exit;

final class Sanitizer
{
    private const ALLOWED = [
        'h2' => [], 'h3' => [], 'h4' => [], 'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'a' => ['href', 'title'], 'blockquote' => [],
        'figure' => [], 'figcaption' => [], 'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['scope'], 'td' => ['colspan', 'rowspan'],
    ];

    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'noscript', 'template', 'svg', 'math', 'head',
        'title', 'meta', 'link', 'base', 'button', 'input', 'select', 'textarea', 'option', 'frame', 'frameset',
        'applet', 'audio', 'video', 'canvas', 'dialog',
    ];

    public static function html(?string $html): string
    {
        $html = (string)$html;
        if (trim($html) === '') return '';

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body><div id="icm-sanitize-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = (new DOMXPath($doc))->query('//div[@id="icm-sanitize-root"]')->item(0);
        if (!$root) return '';
        self::clean($root);

        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    /** Plain text from HTML: tags stripped, entities decoded, whitespace collapsed. */
    public static function plain(?string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/li|\/h[1-6]|\/td|\/th|\/tr|\/div|\/blockquote)\b[^>]*>/i', ' ', (string)$html) ?? '';
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    }

    /**
     * Clean one line or block of user text: control characters stripped
     * (newlines kept when $multiline), CRLF normalised, trimmed, cut to $max.
     */
    public static function text(mixed $value, bool $multiline = false, int $max = 0): string
    {
        $s = is_scalar($value) ? (string)$value : '';
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $s) ?? '';
        $s = trim($s);
        return $max > 0 ? mb_substr($s, 0, $max) : $s;
    }

    /** Site-relative link targets in a body (the SEO panel reports unknown ones). */
    public static function internalLinks(?string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref\s*=\s*"([^"]*)"/i', (string)$html, $m);
        $out = [];
        foreach ($m[1] ?? [] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^/(?!/)#', $href)) $out[] = (string)parse_url($href, PHP_URL_PATH);
        }
        return array_values(array_unique($out));
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP, true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::clean($child);
                if (!isset(self::ALLOWED[$tag])) {
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                if (!self::attributes($child, $tag)) $node->removeChild($child);
            } elseif ($child instanceof DOMCdataSection) {
                $node->replaceChild($node->ownerDocument->createTextNode($child->data), $child);
            } elseif (!($child instanceof DOMText)) {
                $node->removeChild($child);   // comments, processing instructions
            }
        }
    }

    /** Filter attributes in place. Returns false when the element must go (an img without a safe src). */
    private static function attributes(DOMElement $el, string $tag): bool
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            if (!in_array(strtolower($attr->name), self::ALLOWED[$tag], true)) $el->removeAttribute($attr->name);
        }

        if ($tag === 'a') {
            $href = self::href($el->getAttribute('href'));
            if ($href === null) {
                $el->removeAttribute('href');
            } else {
                $el->setAttribute('href', $href);
                if (self::isExternal($href)) {
                    $el->setAttribute('rel', 'noopener');
                    $el->setAttribute('target', '_blank');
                }
            }
            if ($el->hasAttribute('title')) $el->setAttribute('title', mb_substr($el->getAttribute('title'), 0, 200));
        } elseif ($tag === 'img') {
            $src = self::imgSrc($el->getAttribute('src'));
            if ($src === null) return false;
            $el->setAttribute('src', $src);
            foreach (['width', 'height'] as $d) {
                if ($el->hasAttribute($d) && !preg_match('/^\d{1,4}$/', $el->getAttribute($d))) $el->removeAttribute($d);
            }
        } elseif ($tag === 'th') {
            if ($el->hasAttribute('scope') && !in_array($el->getAttribute('scope'), ['row', 'col', 'rowgroup', 'colgroup'], true)) {
                $el->removeAttribute('scope');
            }
        } elseif ($tag === 'td') {
            foreach (['colspan', 'rowspan'] as $s) {
                if ($el->hasAttribute($s) && !preg_match('/^([1-9]|[1-4]\d|50)$/', $el->getAttribute($s))) $el->removeAttribute($s);
            }
        }
        return true;
    }

    /** http(s), mailto:, tel:, sms:, #fragment or a site-relative path; anything else is refused. */
    private static function href(string $h): ?string
    {
        $h = trim($h);
        $check = preg_replace('/[\x00-\x20]+/', '', $h) ?? '';
        if ($check === '') return null;
        if ($check[0] === '#') return $h;
        if (preg_match('#^/(?!/)#', $check)) return $h;
        if (preg_match('#^(https?:|mailto:|tel:|sms:)#i', $check)) return $h;
        return null;
    }

    private static function isExternal(string $href): bool
    {
        if (!preg_match('#^https?://#i', $href)) return false;
        $host = strtolower((string)parse_url($href, PHP_URL_HOST));
        $base = strtolower((string)parse_url((string)cfg('base_url'), PHP_URL_HOST));
        return $host !== '' && $host !== $base;
    }

    /** Images must live under /uploads/ or /assets/ (same-site absolute URLs are made relative). */
    private static function imgSrc(string $src): ?string
    {
        $src = trim($src);
        if (preg_match('#^https?://#i', $src)) {
            $host = strtolower((string)parse_url($src, PHP_URL_HOST));
            $base = strtolower((string)parse_url((string)cfg('base_url'), PHP_URL_HOST));
            if ($host !== $base) return null;
            $src = (string)parse_url($src, PHP_URL_PATH);
        }
        if (str_contains($src, '..')) return null;
        return preg_match('#^/(uploads|assets)/[A-Za-z0-9._/-]+$#', $src) ? $src : null;
    }
}
