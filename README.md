# RIELBUILD website redesign

A cinematic, scroll-driven redesign of rielbuild.ca, in two versions:

- `cms/`: **the website with its admin panel** (PHP 8.1+, SQLite). Built on the ICM / NHF admin framework. This is the version to deploy.
- `site/`: the original static demo (PHP 7.4+, no database, no admin), kept for reference.
- `docs/design-package.md`: the approved creative plan (story, palette, type, band map)

## The CMS version (`cms/`)

### Install

1. Create a domain or subdomain for it (for example `rielbuild.example.com`). It must sit at the top of its domain, not in a sub-folder.
2. Choose PHP 8.1, 8.2 or 8.3 for that domain. It needs the pdo_sqlite, gd and mbstring extensions (on by default on most hosts). No MySQL database is needed.
3. Upload `rielbuild-cms.zip` into the domain's folder and extract it, so `index.php` sits directly inside it.
4. Open `https://your-domain/install/`, fill in the one form (your name, email, a password of 12 or more characters) and press **Set up the website**. It creates the database with every page, photo, menu, FAQ, project and three starter blog posts.
5. Sign in at `https://your-domain/admin/`.

Leave "This is the real, public website" unticked for a demo: every page then tells search engines not to index it. On the real domain, tick it (or set `live_url` in `app/config.local.php` to the site address) and remove the `#` from the HTTPS lines in `.htaccess` once the SSL certificate works.

**Windows / IIS hosting** (Plesk on Windows, MSPControl, WebsitePanel): IIS ignores `.htaccess`, so the package also ships a `web.config` with the same rules. The server needs PHP 8.1+ enabled for the site and the IIS URL Rewrite module, and the site's application pool must be allowed to write to `app/`, `storage/` and `uploads/` (the installer writes `app/config.local.php`). Once SSL works, set `enabled="true"` on the "Force HTTPS" rule in `web.config`.

### What the client edits in the admin

| Screen | What it edits |
|---|---|
| Pages and SEO | Every page: page name, main heading (H1), introduction, body, **all wording and photos of each section** ("Page wording and images"), SEO title, meta description, social image, search-engine visibility, web address (old addresses redirect), extra blocks (text, cards, questions, buttons, images, tables). "Add a page" makes a text page or a new service (it joins the Services menu, footer, home page and estimate form by itself). |
| Blog posts | Write, schedule and publish posts: headline, address, summary, body (rich text with headings, links, lists), featured photo with alt text, photo gallery, category (a service links the post to that service page), tags (related posts), SEO title and description with a live Google preview and an SEO score. |
| Menus | The header menu (with the Services mega panel), the phone menu and the two footer columns: add, reorder (drag and drop), hide, rename, link to a page or any address, icons and photos. |
| FAQs | Questions for the FAQ page, each service page and the home page. |
| Projects | The gallery on Projects and the home page, with filter buttons. |
| Media | Every photo: upload (resized automatically to WebP and JPEG in four sizes), alt text, focal point, where it is used. |
| Inbox | Every estimate request from the site's forms, also emailed to the addresses in Settings. |
| Redirects | 301 redirects and the list of addresses visitors could not find. |
| Settings | Business name, phone, email, hours, social links, the estimate band and form wording, search defaults, how email is sent, form recipients, security, maintenance mode. |
| Users | Accounts and roles (owner, manager, editor), optional two-step sign-in. |
| System | Health, backups (download), database updates. |

### SEO built in

Per-page titles and descriptions (with length checks and a duplicate-title check), canonical links, Open Graph and Twitter cards, one JSON-LD graph per page (GeneralContractor with hours, areas and services; WebSite; BreadcrumbList; Service; FAQPage; HowTo; BlogPosting; Blog), an XML sitemap with images (`/sitemap.xml`), `robots.txt`, an RSS feed (`/feed/`), breadcrumbs, exactly one H1 per page, responsive WebP images with width and height, and internal links between services, blog posts (by category and tags), FAQs and service areas.

### Optional cron job

Not required: scheduled posts go live on their own. For nightly database backups, add a cron job every 5 minutes: `php /path/to/site/app/cron/run.php` (System shows the exact path).

## Windows hosting without PHP (`static/`)

rielbuild.ca's host (MSPControl, IIS) offers ASP.NET but no PHP, so `static/` is the finished site exported to plain HTML: every page, the three blog posts, the sitemap, robots.txt and the RSS feed (`/feed.xml`), with the same SEO tags and structured data as the CMS. There is no admin; wording changes are made in the CMS and exported again.

- **Live since 2026-10-29** on rielbuild.ca (MSPControl, ASP.NET 4.0 Integrated, IIS URL Rewrite available). The files sit in `rielbuild.ca\wwwroot`; the previous site is kept as `rielbuild.ca\old-site-backup.zip` (outside the web folder). `web.config` sends http:// and www. to https://rielbuild.ca/ and serves `404.html` with a real 404 status.
- **Upload:** the contents of `static/` go into `wwwroot`. The panel's Unzip extracts next to the zip, so upload to `rielbuild.ca`, unzip, then Move the files into `wwwroot`. Space is limited (100 MB plan).
- **Estimate form:** posts to `forms/contact.ashx`, which checks the fields, drops obvious spam, emails the request and keeps a copy in `App_Data\estimate-requests.log`. Mail settings (recipient, SMTP server, login) are at the top of `web.config`; send failures are written to `App_Data\mail-errors.log`. `App_Data` has write permission for the site (set in File Manager > Edit Permissions); IIS never serves that folder.
- **Old addresses:** `contact.html`, `design-build.html`, `remodeling-renovation.html`, `riel-construction.html` and `kitchen-bath/` from the previous site send visitors to their new pages (301).
- **Export again** after changing the CMS: install it locally in live mode (`php app/cli.php install --url=https://rielbuild.ca --live=https://rielbuild.ca ...`), serve it with `php -S 127.0.0.1:8096` and a router that sends unknown paths to `index.php`, then run `python3 tools/export_static.py http://127.0.0.1:8096 <cms folder> static`. The form handler and `web.config` live in `static-src/` and are copied in.

## The static demo (`site/`)

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
3. Confirm the business facts on the site with the client (service areas, hours, timelines).
4. Replace illustrative images in `site/assets/img/stills/` with real project photos (same file names: `name.jpg`, `name.webp`, `name-sm.webp`) and remove the footer note in `site/inc/footer.php`.
5. Submit `https://rielbuild.ca/sitemap.xml` in Google Search Console.

## The scroll film

`site/assets/video/hero-scrub.mp4` (widescreen, desktops and landscape) and `hero-scrub-m.mp4` (portrait, phones and portrait tablets). Visitors with reduced motion or Data Saver get a still hero instead.

## SEO included

Unique title and description per page, canonical URLs, Open Graph and Twitter cards, JSON-LD (GeneralContractor, Service, FAQPage, BreadcrumbList, HowTo, WebSite), dynamic `sitemap.xml` and `robots.txt`, semantic headings, image alt text, fast static pages with long-cache headers and gzip.
