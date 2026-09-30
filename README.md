# RIELBUILD website redesign (demo)

A cinematic, scroll-driven redesign of rielbuild.ca. Plain PHP 7.4+, HTML, CSS and vanilla JS. No framework, no database, no admin panel.

- `site/`: the deployable website (upload the contents of this folder)
- `docs/design-package.md`: the approved creative plan (story, palette, type, band map)

## Deploy to cPanel (HostPapa)

1. Download `rielbuild-demo.zip` from the latest commit or the session.
2. In cPanel File Manager, open `public_html/demo/rielbuild/` (create it if needed).
3. Upload the zip there and choose **Extract**. `index.php` must sit directly inside `demo/rielbuild/`.
4. Visit `https://esolutify.com/demo/rielbuild/`.

The site detects its own folder, so it also works in any other folder or at a domain root without edits.

## Settings (`site/inc/config.php`)

| Setting | Default | What it does |
|---|---|---|
| `FORM_MODE` | `demo` | `demo` shows the thank-you and sends nothing. `mail` emails each request to `FORM_TO` with PHP `mail()`. |
| `FORM_TO` | contact@rielbuild.ca | Where consultation requests go in `mail` mode. |
| `NOINDEX` | `true` | Keeps the demo out of Google. Set `false` on the live domain. |
| `SITE_URL_OVERRIDE` | empty | Force the canonical domain, e.g. `https://rielbuild.ca`. |
| `ASSET_V` | `1.0.0` | Bump after changing CSS/JS to bust caches. |

## Going live on rielbuild.ca

1. Upload the same files to the domain root.
2. In `config.php`: `NOINDEX` to `false`, `SITE_URL_OVERRIDE` to `https://rielbuild.ca`, `FORM_MODE` to `mail`.
3. Confirm the business facts on the site with the client (service areas, hours, warranty wording, timelines).
4. Replace illustrative images in `site/assets/img/stills/` with real project photos (same file names: `name.jpg`, `name.webp`, `name-sm.webp`) and remove the footer note in `site/inc/footer.php`.
5. Submit `https://rielbuild.ca/sitemap.xml` in Google Search Console.

## The scroll film

`site/assets/video/hero-scrub.mp4` (widescreen, desktops and landscape) and `hero-scrub-m.mp4` (portrait, phones and portrait tablets). Visitors with reduced motion or Data Saver get a still hero instead.

## SEO included

Unique title and description per page, canonical URLs, Open Graph and Twitter cards, JSON-LD (GeneralContractor, Service, FAQPage, BreadcrumbList, HowTo, WebSite), dynamic `sitemap.xml` and `robots.txt`, semantic headings, image alt text, fast static pages with long-cache headers and gzip.
