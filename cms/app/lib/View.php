<?php
/**
 * Template rendering (spec 1.5.4). A template is captured into a buffer
 * first, so it can set its SEO, JSON-LD, status code and extra scripts before
 * the layout writes <head>. Every template receives:
 *   $view      this View (seo(), schema(), partial(), nonce() ...)
 *   $page      the pages row, or null
 *   $pageData  pages.data_json decoded ([] when empty)
 *   $site      every non-secret setting as [dotted key => value]
 *   $route     the template key ('about', 'post', '404' ...)
 * plus whatever the router passes ($post, $snapshot, $pageNum, $params).
 *
 * SEO merge order: pages.seo_title / pages.meta_description, then $view->seo(),
 * then the site defaults. The title always ends in " | {site name}".
 */
defined('ICM') or exit;

final class View
{
    public const DEFAULT_DESCRIPTION = 'Kitchen, bathroom and basement renovations and full home remodels across the GTA. Fixed written quotes, one project lead, payments tied to finished work.';
    public const DEFAULT_OG_IMAGE = '/assets/img/og.jpg';

    private array $seo = [];
    private array $nodes = [];
    private array $data = [];
    private array $head = [];
    private array $scripts = [];
    private array $inline = [];
    private array $frameAncestors = [];
    private array $bodyClasses = [];
    private ?string $layout = 'layout';
    private string $mode = 'public';
    private bool $preview = false;
    private ?array $crumbs = null;
    private ?array $final = null;

    private static ?array $siteCache = null;
    private static ?string $requestPath = null;

    // -----------------------------------------------------------------
    // Entry points
    // -----------------------------------------------------------------

    /** Render a pages row with its template (app/views/pages/{template}.php). */
    public static function page(array $page, array $extra = []): void
    {
        $tpl = (string)$page['template'];
        if (!preg_match('/^[a-z0-9_]+$/', $tpl)) throw new InvalidArgumentException('Bad template key');
        (new self())->render('pages/' . $tpl, ['page' => $page, 'route' => $tpl] + $extra);
    }

    /** Set by the router: the canonical path of this request (canonical link default). */
    public static function setRequestPath(string $path): void
    {
        self::$requestPath = $path;
    }

    /** Every non-secret setting, memoised per request. */
    public static function site(): array
    {
        if (self::$siteCache === null) {
            try {
                self::$siteCache = Db::settingsAll();
            } catch (Throwable) {
                self::$siteCache = [];
            }
        }
        return self::$siteCache;
    }

    /**
     * Render views/{$template}.php inside the layout and send it.
     * $template is relative to app/views, for example 'pages/about'.
     */
    public function render(string $template, array $data = []): void
    {
        if (!preg_match('#^[a-z0-9_/-]+$#', $template)) throw new InvalidArgumentException('Bad template name');
        $file = APP_DIR . '/views/' . $template . '.php';
        if (!is_file($file)) throw new RuntimeException('Missing template: ' . $template);

        $page = $data['page'] ?? null;
        $this->data = $data + [
            'page'     => null,
            'site'     => self::site(),
            'route'    => basename($template),
            'pageData' => is_array($page) ? (json_decode((string)($page['data_json'] ?? ''), true) ?: []) : [],
        ];
        $this->data['view'] = $this;

        $content = $this->capture($file, $this->data);
        $meta = $this->finalMeta();

        if ($this->mode === 'admin') {
            Headers::admin();
        } elseif ($this->mode === 'public') {
            Headers::public(['frame_ancestors' => $this->frameAncestors, 'noindex' => $meta['noindex']]);
        }
        Headers::html();
        if ($this->preview) Headers::noStore();

        $html = $this->layout === null
            ? $content
            : $this->capture(APP_DIR . '/views/' . $this->layout . '.php', $this->data + ['content' => $content]);

        if (cfg('debug') && $this->mode === 'public' && $this->layout === 'layout') $this->lint($html, $meta);
        echo $html;
    }

    // -----------------------------------------------------------------
    // API for templates
    // -----------------------------------------------------------------

    /**
     * Page metadata. Keys: title, description, canonical (path or URL),
     * robots ('noindex, follow' ...), image (URL, path or media id), image_alt,
     * image_w, image_h, type ('website' | 'article'), published, modified (UTC),
     * breadcrumbs (list of [name, path], or false for none), schema (false: no JSON-LD).
     */
    public function seo(array $meta): void
    {
        $this->seo = array_replace($this->seo, $meta);
        $this->final = null;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->seo[$key] ?? $default;
    }

    /** Add one JSON-LD node to this page's @graph (nodes with the same @id are merged). */
    public function schema(?array $node): void
    {
        if ($node) $this->nodes[] = $node;
    }

    /** Include app/views/partials/{name}.php with the view's data. Returns false when missing. */
    public function partial(string $name, array $vars = []): bool
    {
        if (!preg_match('#^[a-z0-9_/-]+$#', $name)) return false;
        $file = APP_DIR . '/views/partials/' . $name . '.php';
        if (!is_file($file)) return false;
        echo $this->capture($file, array_merge($this->data, $vars, ['view' => $this]));
        return true;
    }

    /** A partial as a string. */
    public function fetch(string $name, array $vars = []): string
    {
        ob_start();
        $this->partial($name, $vars);
        return (string)ob_get_clean();
    }

    public function data(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** The CSP nonce for inline <script> tags. */
    public function nonce(): string
    {
        return Headers::nonce();
    }

    /** Raw markup for <head>. Trusted, code-built strings only (never user input). */
    public function head(string $html): void
    {
        $this->head[] = $html;
    }

    /** <link rel="preload">, for example the homepage hero poster. */
    public function preload(string $href, string $as, array $attrs = []): void
    {
        $this->head[] = '<link' . attrs(['rel' => 'preload', 'href' => $href, 'as' => $as] + $attrs) . '>';
    }

    /** An external script at the end of <body>, deferred unless $attrs says otherwise. */
    public function script(string $src, array $attrs = []): void
    {
        $this->scripts[$src] = $attrs + ['defer' => true];
    }

    /** An inline script at the end of <body>, carrying the CSP nonce. */
    public function inlineScript(string $js): void
    {
        $this->inline[] = $js;
    }

    /** Choose the layout (views/{name}.php) or null for none. Default 'layout'. */
    public function layout(?string $name): void
    {
        $this->layout = $name;
    }

    public function bodyClass(string $class): void
    {
        if (preg_match('/^[a-z0-9_ -]+$/i', $class)) $this->bodyClasses[] = $class;
    }

    /** Allow framing by these https origins (only /thank-you/ uses it, spec 7.4). */
    public function allowFraming(array $origins): void
    {
        $this->frameAncestors = array_values(array_unique(array_merge($this->frameAncestors, $origins)));
    }

    /** Admin rendering: Headers::admin(), no public SEO. Use with layout('admin/layout'). */
    public function admin(): void
    {
        $this->mode = 'admin';
    }

    /** Draft preview (admin 4.1.3): noindex, no-store and a "Draft preview" bar. */
    public function preview(bool $on = true): void
    {
        $this->preview = $on;
        $this->final = null;
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    public function isAdmin(): bool
    {
        return $this->mode === 'admin';
    }

    /**
     * The breadcrumb trail as [name, path] pairs, Home first. Built from
     * pages.parent_id unless the template set seo(['breadcrumbs' => ...]).
     * Used for the BreadcrumbList node and the visible breadcrumbs.
     */
    public function breadcrumbs(): array
    {
        if (array_key_exists('breadcrumbs', $this->seo)) return is_array($this->seo['breadcrumbs']) ? $this->seo['breadcrumbs'] : [];
        if ($this->crumbs !== null) return $this->crumbs;
        $page = $this->data['page'] ?? null;
        if (!is_array($page) || ($page['path'] ?? '/') === '/') return $this->crumbs = [];
        $trail = [[plainText((string)$page['title']), (string)$page['path']]];
        $seen = [(int)$page['id']];
        $parent = (int)($page['parent_id'] ?? 0);
        try {
            while ($parent > 0 && count($trail) < 8 && !in_array($parent, $seen, true)) {
                $p = Db::one('SELECT id, title, path, parent_id FROM pages WHERE id = ?', [$parent]);
                if (!$p) break;
                $seen[] = (int)$p['id'];
                if ($p['path'] !== '/') array_unshift($trail, [plainText((string)$p['title']), (string)$p['path']]);
                $parent = (int)($p['parent_id'] ?? 0);
            }
        } catch (Throwable) {
        }
        array_unshift($trail, ['Home', '/']);
        return $this->crumbs = $trail;
    }

    // -----------------------------------------------------------------
    // Used by the layout
    // -----------------------------------------------------------------

    /** The merged, final metadata the layout prints. */
    public function finalMeta(): array
    {
        if ($this->final !== null) return $this->final;
        $page = is_array($this->data['page'] ?? null) ? $this->data['page'] : [];
        $s = $this->seo;

        // 'title_override': a title that must beat pages.seo_title, e.g. "Blog, page 2" on /blog/page/2/.
        $name = siteName();
        $title = plainText((string)(($s['title_override'] ?? '') ?: ($page['seo_title'] ?? '') ?: ($s['title'] ?? '') ?: (($page['h1'] ?? '') ?: ($page['title'] ?? '')) ?: $name));
        if ($title !== $name && !str_ends_with($title, $name)) $title .= ' | ' . $name;

        $desc = trim((string)(($page['meta_description'] ?? '') ?: ($s['description'] ?? '')));
        if ($desc === '') $desc = site('seo.default_description', self::DEFAULT_DESCRIPTION);

        $robotsSrc = strtolower(($page['robots'] ?? '') . ',' . ($s['robots'] ?? ''));
        $noindex = str_contains($robotsSrc, 'noindex') || $this->preview || isStaging();
        $nofollow = str_contains($robotsSrc, 'nofollow') || isStaging();
        $robots = ($noindex ? 'noindex' : 'index') . ', ' . ($nofollow ? 'nofollow' : 'follow') . ($noindex ? '' : ', max-image-preview:large');

        $canonPath = (string)($s['canonical'] ?? (self::$requestPath ?? ($page['path'] ?? '/')));
        $canonical = $noindex ? null : url($canonPath);

        [$image, $imageAlt, $w, $h] = $this->ogImage($page, $s);

        return $this->final = [
            'title'       => $title,
            'og_title'    => (string)preg_replace('/\s*\|\s*' . preg_quote($name, '/') . '$/', '', $title),
            'description' => $desc,
            'canonical'   => $canonical,
            'url'         => url($canonPath),
            'robots'      => $robots,
            'noindex'     => $noindex,
            'image'       => $image,
            'image_alt'   => $imageAlt,
            'image_w'     => $w,
            'image_h'     => $h,
            'type'        => (string)($s['type'] ?? 'website'),
            'published'   => isset($s['published']) ? Time::iso((string)$s['published']) : '',
            'modified'    => isset($s['modified']) ? Time::iso((string)$s['modified']) : '',
        ];
    }

    /** The page's JSON-LD nodes: Organization, WebSite, people, breadcrumbs, then the template's. */
    public function graphNodes(): array
    {
        if (($this->seo['schema'] ?? true) === false) return [];
        try {
            $nodes = array_merge([Seo::organisation(), Seo::website()], Seo::staffPeople());
            $crumbs = $this->breadcrumbs();
            if (count($crumbs) >= 2) $nodes[] = Seo::breadcrumbs($crumbs);
        } catch (Throwable $e) {
            Log::exception($e, 'schema base');
            $nodes = [];
        }
        return array_merge($nodes, $this->nodes);
    }

    public function headHtml(): string
    {
        return implode("\n", $this->head);
    }

    public function footHtml(): string
    {
        $out = [];
        foreach ($this->scripts as $src => $attrs) $out[] = '<script' . attrs(['src' => $src] + $attrs) . '></script>';
        foreach ($this->inline as $js) $out[] = '<script nonce="' . e($this->nonce()) . '">' . $js . '</script>';
        return implode("\n", $out);
    }

    public function bodyClasses(): string
    {
        $route = (string)($this->data['route'] ?? '');
        return trim(implode(' ', array_merge($route !== '' ? ['t-' . str_replace('_', '-', $route)] : [], $this->bodyClasses)));
    }

    // -----------------------------------------------------------------

    private function capture(string $file, array $vars): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                include $__file;
            })($file, $vars);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    /** @return array{0:string,1:string,2:int,3:int} absolute image URL, alt, width, height */
    private function ogImage(array $page, array $s): array
    {
        $img = $s['image'] ?? null;
        $alt = (string)($s['image_alt'] ?? '');
        $w = (int)($s['image_w'] ?? 1200);
        $h = (int)($s['image_h'] ?? 630);
        try {
            if (is_int($img) || (is_string($img) && ctype_digit($img))) {
                $m = mediaRow((int)$img);
                $img = $m ? mediaUrl($m, 'og', 'jpg', true) : null;
                $alt = $alt ?: (string)($m['alt'] ?? '');
            } elseif (!$img && !empty($page['og_media_id'])) {
                $m = mediaRow((int)$page['og_media_id']);
                $img = $m ? mediaUrl($m, 'og', 'jpg', true) : null;
                $alt = $alt ?: (string)($m['alt'] ?? '');
            }
        } catch (Throwable) {
            $img = null;
        }
        if (!$img) {
            $img = site('seo.default_og_image', self::DEFAULT_OG_IMAGE);
            $w = 1200;
            $h = 630;
        }
        return [url((string)$img), $alt !== '' ? $alt : siteName(), $w, $h];
    }

    /** Debug only: flag a page without a title, a description or exactly one <h1>. */
    private function lint(string $html, array $meta): void
    {
        $issues = [];
        $h1 = preg_match_all('/<h1[\s>]/i', $html);
        if ($h1 !== 1) $issues[] = 'h1=' . $h1;
        if (trim($meta['title']) === '') $issues[] = 'no-title';
        if (trim($meta['description']) === '') $issues[] = 'no-description';
        if ($issues) {
            if (!headers_sent()) header('X-ICM-Lint: ' . implode(',', $issues));
            Log::write('lint', (self::$requestPath ?? '?') . ' ' . implode(',', $issues));
        }
    }
}
