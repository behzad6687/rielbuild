<?php
/**
 * JSON-LD builders (spec 8.4) and robots.txt (8.3). One @graph per page through
 * Seo::graph(); nodes sharing an @id are merged, and every @id referenced must
 * exist in the graph (the crawler checks). Build nodes only from text that is
 * visible on the page. Never Review or AggregateRating.
 *
 * Builders return null when a node must not be emitted; View::schema(null) ignores it.
 */
defined('ICM') or exit;

final class Seo
{
    private static ?array $org = null;
    private static ?array $people = null;

    public static function orgId(): string
    {
        return url('/#org');
    }

    public static function websiteId(): string
    {
        return url('/#website');
    }

    /**
     * The business (GeneralContractor, a LocalBusiness), on every page: name, contact, hours, the areas
     * served (Settings > Areas list) and the services offered (the published service pages).
     */
    public static function organisation(): array
    {
        if (self::$org !== null) return self::$org;
        $sameAs = array_values(array_filter(
            [site('social.facebook'), site('social.instagram'), site('social.houzz'), site('social.youtube'), site('social.google')],
            fn($u) => (bool)preg_match('#^https://#', $u)
        ));
        $offers = [];
        try {
            foreach (Content::services() as $svc) {
                $offers[] = ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $svc['name'], 'url' => url($svc['path'])]];
            }
        } catch (Throwable) {
        }
        $areas = Content::areas();
        $days = array_values(array_filter(array_map('trim', explode(',', site('hours.days', 'Monday,Tuesday,Wednesday,Thursday,Friday,Saturday')))));
        return self::$org = [
            '@type'         => 'GeneralContractor',
            '@id'           => self::orgId(),
            'name'          => site('org.name', 'RIELBUILD'),
            'legalName'     => site('org.legal_name') ?: null,
            'url'           => url('/'),
            'logo'          => url(site('seo.logo_path', '/assets/img/brand/logo.png')),
            'image'         => url(site('seo.default_og_image', View::DEFAULT_OG_IMAGE)),
            'telephone'     => e164(site('phone.main', '647-895-4555')),
            'email'         => site('email.main', 'contact@rielbuild.ca'),
            'priceRange'    => '$$',
            'address'       => ['@type' => 'PostalAddress', 'addressLocality' => site('address.city', 'Toronto'), 'addressRegion' => site('address.region', 'ON'), 'addressCountry' => 'CA'],
            'areaServed'    => $areas ? array_map(fn($n) => ['@type' => 'City', 'name' => $n], $areas) : null,
            'openingHoursSpecification' => $days ? [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days,
                'opens' => site('hours.opens', '08:00'), 'closes' => site('hours.closes', '18:00')]] : null,
            'sameAs'        => $sameAs ?: null,
            'hasOfferCatalog' => $offers ? ['@type' => 'OfferCatalog', 'name' => 'Home renovation services', 'itemListElement' => $offers] : null,
        ];
    }

    public static function website(): array
    {
        return [
            '@type'     => 'WebSite',
            '@id'       => self::websiteId(),
            'name'      => site('org.name', 'RIELBUILD'),
            'url'       => url('/'),
            'inLanguage' => 'en-CA',
            'publisher' => ['@id' => self::orgId()],
        ];
    }

    /** No staff Person nodes on this site. */
    public static function staffPeople(): array
    {
        return [];
    }

    /** @param list<array{0:string,1:string}> $trail [name, path] pairs, Home first */
    public static function breadcrumbs(array $trail): ?array
    {
        if (count($trail) < 2) return null;
        $items = [];
        foreach (array_values($trail) as $i => [$name, $path]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => url($path)];
        }
        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** FAQPage from faqs rows (question, answer_html). /faqs/ only. */
    public static function faqPage(array $faqs): ?array
    {
        if (!$faqs) return null;
        return [
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(fn($f) => [
                '@type'          => 'Question',
                'name'           => $f['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => Sanitizer::plain($f['answer_html'] ?? '')],
            ], array_values($faqs)),
        ];
    }

    /** BlogPosting for a posts row: author and publisher are the business. */
    public static function article(array $post): array
    {
        $u = url('/blog/' . $post['slug'] . '/');
        $img = !empty($post['featured_media_id']) ? mediaUrl((int)$post['featured_media_id'], 1200, 'jpg', true) : '';
        return [
            '@type'            => 'BlogPosting',
            '@id'              => $u . '#article',
            'headline'         => mb_substr((string)$post['title'], 0, 110),
            'description'      => ($post['meta_description'] ?? '') ?: (($post['excerpt'] ?? '') ?: excerpt((string)($post['body_html'] ?? ''), 160)),
            'image'            => $img ?: url(site('seo.default_og_image', View::DEFAULT_OG_IMAGE)),
            'datePublished'    => Time::iso($post['published_at'] ?? null),
            'dateModified'     => Time::iso(($post['updated_at'] ?? '') ?: ($post['published_at'] ?? null)),
            'author'           => ['@type' => 'Organization', '@id' => self::orgId(), 'name' => site('org.name', 'RIELBUILD')],
            'publisher'        => ['@id' => self::orgId()],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $u],
            'keywords'         => trim((string)($post['tags'] ?? '')) !== '' ? (string)$post['tags'] : null,
        ];
    }

    /** Service for a service page row (its template data gives the name). */
    public static function service(array $page, array $data): array
    {
        $img = !empty($data['media']['photo']) ? mediaUrl((int)$data['media']['photo'], 1200, 'jpg', true) : '';
        return [
            '@type'       => 'Service',
            '@id'         => url($page['path']) . '#service',
            'name'        => (string)(($data['name'] ?? '') ?: $page['title']),
            'serviceType' => (string)(($data['name'] ?? '') ?: $page['title']),
            'description' => (string)(($page['meta_description'] ?? '') ?: ($data['tag'] ?? '')),
            'url'         => url($page['path']),
            'image'       => $img ?: null,
            'provider'    => ['@id' => self::orgId()],
            'areaServed'  => ['@type' => 'AdministrativeArea', 'name' => 'Greater Toronto Area, Ontario'],
        ];
    }

    /** ItemList (services): $items is a list of ['name' => ..., 'url' => ?]. */
    public static function itemList(string $name, array $items): ?array
    {
        if (!$items) return null;
        $list = [];
        foreach (array_values($items) as $i => $it) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name'], 'url' => !empty($it['url']) ? url((string)$it['url']) : null];
        }
        return ['@type' => 'ItemList', 'name' => $name, 'itemListElement' => $list];
    }

    /** One <script type="application/ld+json"> with the merged @graph. */
    public static function graph(array $nodes): string
    {
        $byId = [];
        $out = [];
        foreach (array_filter($nodes) as $n) {
            $n = self::prune($n);
            if (isset($n['@id']) && is_string($n['@id'])) {
                if (isset($byId[$n['@id']])) {
                    $out[$byId[$n['@id']]] = array_replace($out[$byId[$n['@id']]], $n);
                    continue;
                }
                $byId[$n['@id']] = count($out);
            }
            $out[] = $n;
        }
        if (!$out) return '';
        return '<script type="application/ld+json">' . jsonScript(['@context' => 'https://schema.org', '@graph' => $out]) . '</script>';
    }

    /** GET /robots.txt (R61). */
    public static function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        echo self::robotsTxt();
    }

    public static function robotsTxt(): string
    {
        if (isStaging()) return "User-agent: *\nDisallow: /\n";
        $dis = ['/admin/', '/install/', '/forms/', '/app/', '/storage/'];
        return "User-agent: *\nAllow: /\n" . implode('', array_map(fn($d) => 'Disallow: ' . $d . "\n", $dis))
            . 'Sitemap: ' . url('/sitemap.xml') . "\n";
    }

    /** Drop null values (recursively) so no empty keys reach the JSON-LD. */
    private static function prune(array $a): array
    {
        foreach ($a as $k => $v) {
            if ($v === null) {
                unset($a[$k]);
            } elseif (is_array($v)) {
                $a[$k] = self::prune($v);
            }
        }
        return $a;
    }
}
