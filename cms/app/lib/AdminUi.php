<?php
/**
 * HTML builders for the W7b admin screens, in the shell's own vocabulary
 * (admin.css by W7a: page-head, chips, field__label, field__hint, field__error,
 * btn--primary / --line / --danger / --sm, alert--ok / --bad / --warn / --info,
 * tag--ok / --warn / --bad, lint, pager, serp, checks, focal, ogcrop).
 * Every dynamic value is escaped. Counters use data-count="min,max" like
 * W7a's field partial; content.js adds the counter when admin.js has not.
 *
 * W7b-only components are styled by admin/assets/content.css and driven by
 * content.js; assets() pulls both in once per page.
 */
defined('ICM') or exit;

final class AdminUi
{
    private static bool $assetsDone = false;
    private static int $seq = 0;

    public static function url(string $route, array $query = []): string
    {
        if (function_exists('admin_url')) return admin_url($route, $query);
        $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
        return '/admin/' . trim($route, '/') . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    public static function token(): string
    {
        try {
            return Csrf::token();
        } catch (Throwable) {
            return '';
        }
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    /** content.css and content.js, once per page. */
    public static function assets(): string
    {
        if (self::$assetsDone) return '';
        self::$assetsDone = true;
        $v = static function (string $f): string {
            $file = ROOT_DIR . '/admin/assets/' . $f;
            return '/admin/assets/' . $f . '?v=' . (is_file($file) ? substr((string)filemtime($file), -6) : '1');
        };
        return '<link rel="stylesheet" href="' . e($v('content.css')) . '">'
            . '<script src="' . e($v('content.js')) . '" defer></script>';
    }

    // ------------------------------------------------------------------
    // Page furniture
    // ------------------------------------------------------------------

    public static function head(string $title, string $lede = '', string $actions = ''): string
    {
        return self::assets() . '<div class="page-head page-head--row"><h1>' . e($title) . '</h1>'
            . ($actions !== '' ? '<div class="btn-row ed-head-actions">' . $actions . '</div>' : '') . '</div>'
            . ($lede !== '' ? '<p class="muted ed-lede">' . e($lede) . '</p>' : '');
    }

    /** Section tabs as the shell's chips. $tabs: list of ['label', 'url', 'on' => bool, 'count' => ?int]. */
    public static function tabs(array $tabs, string $label = 'Sections'): string
    {
        $h = '<nav class="chips" aria-label="' . e($label) . '">';
        foreach ($tabs as $t) {
            $h .= '<a class="chip' . (!empty($t['on']) ? ' is-on" aria-current="page' : '') . '" href="' . e($t['url']) . '">' . e($t['label'])
                . (isset($t['count']) && $t['count'] !== null ? '<span class="chip__count">' . (int)$t['count'] . '</span>' : '') . '</a>';
        }
        return $h . '</nav>';
    }

    public static function link(string $label, string $href, string $class = 'btn btn--line', array $attrs = []): string
    {
        return '<a' . attrs(['class' => $class, 'href' => $href] + $attrs) . '>' . e($label) . '</a>';
    }

    /** A one-button POST form. $o: class, confirm (data-confirm text), aria (aria-label), title. */
    public static function postButton(string $route, array $query, array $fields, string $label, array $o = []): string
    {
        $h = '<form method="post" class="inline-form" action="' . e(self::url($route, $query)) . '"'
            . (!empty($o['confirm']) ? ' data-confirm="' . e($o['confirm']) . '"' : '') . '>' . self::csrf();
        foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        return $h . '<button' . attrs([
            'type' => 'submit', 'class' => $o['class'] ?? 'btn btn--line btn--sm', 'aria-label' => $o['aria'] ?? null, 'title' => $o['title'] ?? null,
        ]) . '>' . e($label) . '</button></form>';
    }

    public static function alert(string $text, string $kind = 'info'): string
    {
        $kind = in_array($kind, ['ok', 'bad', 'warn', 'info'], true) ? $kind : 'info';
        return '<div class="alert alert--' . $kind . '" role="' . ($kind === 'bad' ? 'alert' : 'status') . '">' . e($text) . '</div>';
    }

    /** The sticky save bar of edit screens (above the phone tab bar). */
    public static function sticky(string $buttons): string
    {
        return '<div class="actions actions--sticky ed-sticky">' . $buttons . '</div>';
    }

    public static function pager(array $pg, string $route, array $query): string
    {
        if (($pg['pages'] ?? 1) <= 1) return '';
        if (function_exists('admin_partial') && is_file(APP_DIR . '/views/admin/partials/pager.php')) {
            ob_start();
            admin_partial('pager', ['pg' => $pg, 'route' => $route, 'query' => $query]);
            return (string)ob_get_clean();
        }
        $h = '<nav class="pager" aria-label="Pages">';
        if ($pg['page'] > 1) $h .= self::link('Previous', self::url($route, ['page' => $pg['page'] - 1] + $query), 'btn btn--line btn--sm');
        $h .= '<span class="pager__at">Page ' . (int)$pg['page'] . ' of ' . (int)$pg['pages'] . '</span>';
        if ($pg['page'] < $pg['pages']) $h .= self::link('Next', self::url($route, ['page' => $pg['page'] + 1] + $query), 'btn btn--line btn--sm');
        return $h . '</nav>';
    }

    public static function datalist(string $id, array $options): string
    {
        $h = '<datalist id="' . e($id) . '">';
        foreach ($options as $v => $label) $h .= '<option value="' . e($v) . '">' . e($label) . '</option>';
        return $h . '</datalist>';
    }

    // ------------------------------------------------------------------
    // Fields
    // ------------------------------------------------------------------

    public static function fid(string $name): string
    {
        return 'f' . (++self::$seq) . '-' . trim((string)preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), '-');
    }

    private static function describedBy(string $id, array $o): ?string
    {
        $ids = [];
        if (($o['hint'] ?? '') !== '') $ids[] = $id . '-h';
        if (($o['error'] ?? '') !== '') $ids[] = $id . '-e';
        return $ids ? implode(' ', $ids) : null;
    }

    private static function wrap(string $id, string $label, string $control, array $o): string
    {
        $hint = ($o['hint'] ?? '') !== '' ? '<span class="field__hint" id="' . e($id) . '-h">' . e($o['hint']) . '</span>' : '';
        $err = ($o['error'] ?? '') !== '' ? '<span class="field__error" id="' . e($id) . '-e">' . e($o['error']) . '</span>' : '';
        $req = !empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '';
        return '<div class="field' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . ($err !== '' ? ' field--error' : '') . '">'
            . '<label class="field__label" for="' . e($id) . '">' . e($label) . $req . '</label>' . $control . $hint . $err . ($o['after'] ?? '') . '</div>';
    }

    /**
     * <input>. $o: type, id, max (maxlength), maxv (max), min, step, required, readonly, disabled, placeholder,
     * inputmode, pattern, list, autocomplete, accept, hint, error, count ('min,max' live counter), attrs, class, after.
     */
    public static function input(string $name, string $label, mixed $value, array $o = []): string
    {
        $id = $o['id'] ?? self::fid($name);
        $type = $o['type'] ?? 'text';
        $a = ['type' => $type, 'id' => $id, 'name' => $name, 'value' => $type === 'file' ? null : (string)($value ?? '')];
        if (isset($o['max'])) $a['maxlength'] = $o['max'];
        foreach (['placeholder', 'inputmode', 'pattern', 'min', 'step', 'list', 'autocomplete', 'accept'] as $k) {
            if (isset($o[$k])) $a[$k] = $o[$k];
        }
        if (isset($o['maxv'])) $a['max'] = $o['maxv'];
        if ($type === 'number' && !isset($o['inputmode'])) $a['inputmode'] = 'numeric';
        $a['required'] = !empty($o['required']);
        $a['readonly'] = !empty($o['readonly']);
        $a['disabled'] = !empty($o['disabled']);
        if (isset($o['count'])) $a['data-count'] = $o['count'];
        $a['aria-describedby'] = self::describedBy($id, $o);
        if (($o['error'] ?? '') !== '') $a['aria-invalid'] = 'true';
        $a = array_merge($a, $o['attrs'] ?? []);
        return self::wrap($id, $label, '<input' . attrs($a) . '>', $o);
    }

    /** <textarea>. $o: rows, rich (the rte.js editor: data-rte), rte (toolbar preset), max, required, hint, error, count, attrs. */
    public static function textarea(string $name, string $label, mixed $value, array $o = []): string
    {
        $id = $o['id'] ?? self::fid($name);
        $rich = !empty($o['rich']);
        $a = ['id' => $id, 'name' => $name, 'rows' => $o['rows'] ?? ($rich ? 12 : 4), 'maxlength' => $o['max'] ?? null,
            'required' => !empty($o['required']) && !$rich, 'data-rte' => $rich ? ($o['rte'] ?? 'full') : null,
            'data-count' => $o['count'] ?? null, 'data-lint' => $rich ? '1' : null, 'aria-describedby' => self::describedBy($id, $o),
            'aria-invalid' => ($o['error'] ?? '') !== '' ? 'true' : null];
        $a = array_merge($a, $o['attrs'] ?? []);
        return self::wrap($id, $label, '<textarea' . attrs($a) . '>' . e($value ?? '') . '</textarea>', $o);
    }

    /** <select>. $options: [value => label] or [group label => [value => label]]. $o: blank, required, hint, error, attrs, class. */
    public static function select(string $name, string $label, array $options, mixed $value, array $o = []): string
    {
        $id = $o['id'] ?? self::fid($name);
        $a = array_merge(['id' => $id, 'name' => $name, 'required' => !empty($o['required']), 'aria-describedby' => self::describedBy($id, $o)], $o['attrs'] ?? []);
        $opts = isset($o['blank']) ? '<option value="">' . e($o['blank']) . '</option>' : '';
        return self::wrap($id, $label, '<select' . attrs($a) . '>' . $opts . self::options($options, $value) . '</select>', $o);
    }

    public static function options(array $options, mixed $value): string
    {
        $h = '';
        $vals = is_array($value) ? array_map('strval', $value) : [(string)($value ?? '')];
        foreach ($options as $v => $label) {
            if (is_array($label)) {
                $h .= '<optgroup label="' . e($v) . '">' . self::options($label, $value) . '</optgroup>';
                continue;
            }
            $h .= '<option value="' . e($v) . '"' . (in_array((string)$v, $vals, true) ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        return $h;
    }

    /** A checkbox with its label (the shell's field--check). $o: hint, value, attrs, id. */
    public static function check(string $name, string $label, bool $on, array $o = []): string
    {
        $id = $o['id'] ?? self::fid($name);
        $hintId = ($o['hint'] ?? '') !== '' ? $id . '-h' : null;
        $a = array_merge(['type' => 'checkbox', 'id' => $id, 'name' => $name, 'value' => $o['value'] ?? '1', 'checked' => $on, 'aria-describedby' => $hintId], $o['attrs'] ?? []);
        return '<div class="field field--check"><label class="check" for="' . e($id) . '"><input' . attrs($a) . '><span>' . e($label) . '</span></label>'
            . ($hintId ? '<span class="field__hint" id="' . e($hintId) . '">' . e($o['hint']) . '</span>' : '') . '</div>';
    }

    /** A money field with the reminder beside every price (design brief 10.2). */
    public static function money(string $name, string $label, ?int $cents, array $o = []): string
    {
        return self::input($name, $label, AdminKit::moneyInput($cents), $o + [
            'inputmode' => 'decimal', 'max' => 12, 'after' => '<span class="field__hint price-note">Change the JotForm price too.</span>',
        ]);
    }

    /**
     * The image field: preview with a tap-to-set focal point, the 1200 x 630 social crop,
     * a file input (iOS converts HEIC to JPEG with this accept list), alt text, remove,
     * or a media library number. Works without JavaScript: posts $prefix[...] plus $prefix_file.
     * $o: label, hint, alt_label, og (show the social crop), required_alt.
     */
    public static function imageField(string $prefix, ?int $mediaId, ?string $alt, array $o = []): string
    {
        $m = $mediaId ? mediaRow($mediaId) : null;
        $fid = self::fid($prefix);
        $h = '<fieldset class="imgfield ed-imgfield" data-w7b-image><legend class="field__label">' . e($o['label'] ?? 'Image') . '</legend>';
        if ($m && ($m['kind'] ?? '') === 'image') {
            $fx = (float)($m['focal_x'] ?? 0.5);
            $fy = (float)($m['focal_y'] ?? 0.4);
            $h .= '<div class="imgfield__body"><div class="focal" data-w7b-focal>'
                . '<img src="' . e(mediaUrl($m, 800, 'jpg')) . '" alt="" width="400" height="' . (int)round(400 * max(1, (int)$m['height']) / max(1, (int)$m['width'])) . '">'
                . '<span class="focal__dot" aria-hidden="true" style="left:' . round($fx * 100, 1) . '%;top:' . round($fy * 100, 1) . '%"></span></div>';
            if (!empty($o['og'])) {
                $h .= '<div class="ogcrop" role="img" aria-label="The social crop, 1200 by 630" data-w7b-ogcrop'
                    . ' style="background-image:url(\'' . e(mediaUrl($m, 800, 'jpg')) . '\');background-position:' . round($fx * 100, 1) . '% ' . round($fy * 100, 1) . '%"></div>';
            }
            $h .= '</div><p class="field__hint">Tap the photo to set its focal point: crops keep that spot in view. Media number ' . (int)$m['id'] . '.</p>'
                . '<input type="hidden" name="' . e($prefix) . '[focal_x]" value="' . e(number_format($fx, 3, '.', '')) . '" data-focal-x>'
                . '<input type="hidden" name="' . e($prefix) . '[focal_y]" value="' . e(number_format($fy, 3, '.', '')) . '" data-focal-y>'
                . self::check($prefix . '[remove]', 'Remove this image', false);
        }
        $h .= '<input type="hidden" name="' . e($prefix) . '[id]" value="' . e($mediaId ?: '') . '">';
        $h .= self::input($prefix . '_file', $m ? 'Replace with a new photo' : 'Upload a photo', '', [
            'type' => 'file', 'accept' => 'image/jpeg,image/png,image/webp', 'id' => $fid . '-file',
            'hint' => $o['hint'] ?? 'JPEG, PNG or WebP, 12 MB at most. Location data is removed from photos.',
        ]);
        $h .= self::input($prefix . '[alt]', $o['alt_label'] ?? 'Alt text', $alt ?? ($m['alt'] ?? ''), [
            'max' => 300, 'required' => !empty($o['required_alt']) && $m,
            'hint' => 'Describe what the photo shows' . (!empty($o['required_alt']) ? '. Required before publishing' : '') . '. Never name a child.',
        ]);
        $h .= self::input($prefix . '[pick]', 'Or use an image from the media library (its number)', '', [
            'type' => 'number', 'min' => 1, 'class' => 'field--small', 'hint' => 'Leave empty to keep the current image.',
        ]);
        return $h . '</fieldset>';
    }

    /** SEO title (60 counter) and meta description (140 to 158 counter). Ids: {prefix}-title, {prefix}-meta. */
    public static function seoFields(string $titleName, ?string $titleVal, string $descName, ?string $descVal, string $prefix = 'seo'): string
    {
        return self::input($titleName, 'SEO title', $titleVal, ['id' => $prefix . '-title', 'max' => 200, 'count' => '0,60',
                'hint' => 'Leave empty to use the headline. " | ' . siteName() . '" is added for you. Aim for 60 characters or fewer.'])
            . self::textarea($descName, 'Meta description', $descVal, ['id' => $prefix . '-meta', 'rows' => 3, 'max' => 320, 'count' => '140,158',
                'hint' => 'The sentence people read in Google. Aim for 140 to 158 characters.']);
    }

    /** The SEO check panel with the Google preview (W7a's seo-panel partial when present). */
    public static function seoPanel(array $audit, string $absUrl, string $meta, string $prefix = 'seo'): string
    {
        if (function_exists('admin_partial') && is_file(APP_DIR . '/views/admin/partials/seo-panel.php')) {
            ob_start();
            admin_partial('seo-panel', ['audit' => $audit, 'url' => $absUrl, 'meta' => $meta, 'title_field' => $prefix . '-title', 'meta_field' => $prefix . '-meta']);
            return (string)ob_get_clean();
        }
        $score = (int)($audit['score'] ?? 0);
        $h = '<section class="card seo" aria-labelledby="seo-h" data-serp data-serp-title="' . e($prefix) . '-title" data-serp-meta="' . e($prefix) . '-meta">'
            . '<h2 id="seo-h">Search appearance</h2><div class="serp" aria-label="Google result preview"><span class="serp__url">' . e($absUrl) . '</span>'
            . '<span class="serp__title" data-serp-title-out>' . e((string)($audit['title'] ?? '')) . '</span><span class="serp__desc" data-serp-meta-out>' . e($meta) . '</span></div>'
            . '<div class="meter" role="img" aria-label="SEO check ' . $score . ' out of 100"><span class="meter__bar"><i style="width:' . $score . '%"></i></span><span class="meter__v">' . $score . '</span></div><ul class="checks">';
        foreach ((array)($audit['checks'] ?? []) as $c) {
            $h .= '<li class="' . ($c['ok'] ? 'is-ok' : 'is-no') . '"><span class="checks__mark" aria-hidden="true">' . ($c['ok'] ? '&#10003;' : '!') . '</span><span>' . e($c['label'])
                . (!$c['ok'] && ($c['hint'] ?? '') !== '' ? '<small>' . e($c['hint']) . '</small>' : '') . '</span><span class="sr-only">' . ($c['ok'] ? 'passes' : 'needs work') . '</span></li>';
        }
        return $h . '</ul></section>';
    }

    /** Copy linter notes with the Fix dashes button (it submits the surrounding form with fix_dashes=1). */
    public static function lint(array $warnings): string
    {
        if (!$warnings) return '';
        $dash = false;
        $h = '<div class="lint" role="status"><p class="lint__title">House style ' . (count($warnings) === 1 ? 'note' : 'notes') . ' (saving still works):</p><ul>';
        foreach ($warnings as $w) {
            $h .= '<li>' . e($w) . '</li>';
            if (str_contains($w, 'dash')) $dash = true;
        }
        $h .= '</ul>';
        if ($dash) $h .= '<p class="ed-fix"><button type="submit" name="fix_dashes" value="1" class="btn btn--line btn--sm">Fix dashes</button></p>';
        return $h . '</div>';
    }

    /** Errors summary at the top of a form. */
    public static function errors(array $errors): string
    {
        if (!$errors) return '';
        $h = '<div class="alert alert--bad" role="alert"><strong>Please fix ' . (count($errors) === 1 ? 'this' : 'these') . ' first.</strong><ul>';
        foreach ($errors as $e) $h .= '<li>' . e($e) . '</li>';
        return $h . '</ul></div>';
    }

    /** "Yes" / "No" for list cells. */
    public static function yes(mixed $v): string
    {
        return !empty($v) && $v !== '0' ? 'Yes' : 'No';
    }
}
