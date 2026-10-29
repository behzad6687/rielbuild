<?php
/**
 * The RIELBUILD starter content, written once by the installer (and by app/cli.php seed):
 * settings, the starter photos (into the media library), every page with its wording, the FAQs,
 * the projects, the menus, three blog posts and the estimate form's recipients.
 *
 * Every step writes only what is missing (natural keys: skey, original_name, path, question, title, slug),
 * so running it again never overwrites a change made in the admin.
 *
 *   require APP_DIR . '/seed/RbSeed.php';   // not autoloaded (app/lib only)
 *   RbSeed::run(fn(string $line) => print($line . "\n"));
 */
defined('ICM') or exit;

final class RbSeed
{
    private static array $media = [];

    public static function run(?callable $say = null): array
    {
        $say ??= static fn(string $s) => null;
        $out = [];
        foreach (['settings', 'media', 'pages', 'faqs', 'projects', 'menus', 'posts', 'forms'] as $step) {
            $n = self::{$step}();
            $out[$step] = $n;
            $say($step . ': ' . $n);
        }
        Content::flush();
        Sitemap::invalidate();
        return $out;
    }

    // ------------------------------------------------------------------

    private static function settings(): int
    {
        $email = 'contact@rielbuild.ca';
        $rows = [
            'org.name' => 'RIELBUILD', 'org.legal_name' => 'RIELBUILD Renovation', 'org.tagline' => 'Renovation & Remodelling',
            'footer.line' => 'Home renovations across the Greater Toronto Area. Straight lines, straight talk.',
            'footer.note' => 'Project imagery on this site is illustrative.',
            'phone.main' => '647-895-4555', 'email.main' => $email, 'hours.text' => 'Mon to Sat, 8am to 6pm',
            'hours.days' => 'Monday,Tuesday,Wednesday,Thursday,Friday,Saturday', 'hours.opens' => '08:00', 'hours.closes' => '18:00',
            'address.city' => 'Toronto', 'address.region' => 'ON', 'area.line' => 'Serving Toronto and the GTA',
            'header.cta_label' => 'Free estimate',
            'cta.kicker' => 'Let\'s build it right', 'cta.title' => 'Start with a free visit and an honest number.',
            'cta.points' => "A price range on the first visit\nA fixed, written quote after that\nNo pressure to sign anything",
            'cta.call' => 'Rather talk now?',
            'form.heading' => 'Book a free estimate', 'form.sub' => 'Tell us a little about the job. A project lead calls you back within one business day.',
            'form.button' => 'Book my free estimate', 'form.note' => 'No pressure, no obligation. We never share your details.',
            'form.thanks_title' => 'Thanks. We\'ve got it.', 'form.thanks_text' => 'A project lead will call you within one business day to book your visit.',
            'seo.default_description' => 'Kitchen, bathroom and basement renovations and full home remodels across the GTA. Fixed written quotes, one project lead, payments tied to finished work.',
            'seo.default_og_image' => '/assets/img/og.jpg', 'seo.logo_path' => '/assets/img/brand/logo.png',
            'mail.tx.transport' => 'mail', 'mail.tx.from' => 'no-reply@rielbuild.ca', 'mail.tx.from_name' => 'RIELBUILD website', 'mail.tx.daily_cap' => '200',
            'security.require_2fa_roles' => '', 'security.csp_mode' => 'report-only',
        ];
        $n = 0;
        foreach ($rows as $k => $v) {
            if (Db::value('SELECT 1 FROM settings WHERE skey = ?', [$k])) continue;
            Db::setSetting($k, $v);
            $n++;
        }
        Db::flushSettings();
        return $n;
    }

    /** The starter photos (seed/media.php) into the media library, with every size the site uses. */
    private static function media(): int
    {
        $n = 0;
        foreach ((static fn(): array => require APP_DIR . '/seed/media.php')() as $key => $m) {
            $id = Db::value('SELECT id FROM media WHERE original_name = ? ORDER BY id LIMIT 1', [$m['file']]);
            if (!$id) {
                $src = ROOT_DIR . '/assets/img/' . $m['src'];
                if (!is_file($src)) continue;
                $dir = storagePath('cache/seed-media');
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                $tmp = $dir . '/' . $m['file'];
                copy($src, $tmp);
                try {
                    $row = Media::importFile($tmp, ['alt' => $m['alt'], 'name' => $key, 'is_ai_generated' => 1], null);
                } catch (Throwable $e) {
                    Log::exception($e, 'seed media ' . $key);
                    $row = [];
                }
                @unlink($tmp);
                if (empty($row['id'])) continue;
                $id = (int)$row['id'];
                $n++;
            }
            self::$media[$key] = (int)$id;
        }
        return $n;
    }

    private static function mid(string $key): ?int
    {
        if (!isset(self::$media[$key])) {
            $file = ((static fn(): array => require APP_DIR . '/seed/media.php')()[$key]['file'] ?? null);
            $id = $file ? Db::value('SELECT id FROM media WHERE original_name = ? ORDER BY id LIMIT 1', [$file]) : null;
            self::$media[$key] = $id ? (int)$id : 0;
        }
        return self::$media[$key] ?: null;
    }

    /** media slots for page data: ['hero' => 'kitchen'] => ['hero' => 12]. */
    private static function slots(array $slots): array
    {
        $out = [];
        foreach ($slots as $k => $key) if ($id = self::mid($key)) $out[$k] = $id;
        return $out;
    }

    // ------------------------------------------------------------------

    private static function pages(): int
    {
        $n = 0;
        $ids = [];
        foreach (self::pageRows() as $i => $p) {
            $parent = isset($p['parent']) ? ($ids[$p['parent']] ?? Db::value('SELECT id FROM pages WHERE path = ?', [$p['parent']])) : null;
            $existing = Db::value('SELECT id FROM pages WHERE path = ?', [$p['path']]);
            if ($existing) {
                $ids[$p['path']] = (int)$existing;
                continue;
            }
            $data = $p['data'] ?? [];
            if (!empty($p['media'])) $data['media'] = self::slots($p['media']);
            if (isset($data['media']) && !$data['media']) unset($data['media']);
            $now = Time::nowUtc();
            $ids[$p['path']] = Db::insert('pages', [
                'path' => $p['path'], 'template' => $p['template'], 'title' => $p['title'], 'h1' => $p['h1'] ?? null,
                'seo_title' => $p['seo_title'] ?? null, 'meta_description' => $p['meta'] ?? null,
                'og_media_id' => isset($p['og']) ? self::mid($p['og']) : null,
                'intro_html' => $p['intro'] ?? null, 'body_html' => $p['body'] ?? null,
                'data_json' => $data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'parent_id' => $parent ?: null, 'robots' => 'index,follow', 'in_sitemap' => 1, 'status' => 'published',
                'sort' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
            ContentAdmin::reindexPage((int)$ids[$p['path']]);
            $n++;
        }
        return $n;
    }

    private static function pageRows(): array
    {
        $estimate = "It depends on the size and requirements. When we visit you for your free estimate, we'll discuss the timeline in detail.";
        return [
            [
                'path' => '/', 'template' => 'home', 'title' => 'Home', 'og' => 'hero-start',
                'h1' => 'RIELBUILD: home renovation contractor in Toronto and the GTA',
                'seo_title' => 'Home Renovation Contractor in Toronto & the GTA',
                'meta' => 'Kitchen, bathroom and basement renovations and full home remodels across the GTA. Fixed written quotes, one project lead, payments tied to finished work.',
                'data' => [
                    'film1_kicker' => 'Renovation & remodelling', 'film1_title' => 'Home renovations across the GTA.', 'film1_sub' => 'Love your living space.',
                    'film2_kicker' => 'The first promise', 'film2_title' => 'No delay after the deposit.', 'film2_sub' => 'We\'ll get to work right away. Subsequent payments tied to finished work.',
                    'film3_kicker' => 'Kitchens', 'film3_title' => 'Kitchens you\'ll want to cook in.', 'film3_sub' => 'Cabinets, counters, plumbing, lighting. One crew handles all of it.',
                    'film4_kicker' => 'Built true', 'film4_title' => 'We build what\'s behind the walls.', 'film4_sub' => 'Framing, plumbing, insulation. The parts you never see, done right.',
                    'film5_title' => 'Let\'s build it right.', 'film5_sub' => 'Free estimate. A clear, written quote.', 'film_cta' => 'Book a free estimate',
                    'svc_kicker' => 'What we build', 'svc_title' => 'Four ways we renovate. One way of working.',
                    'svc_lede' => 'From a single bathroom to the whole house, every job gets the same fixed written quote, the same project lead and the same clean site.',
                    'way_title' => 'The way we work', 'hold_label' => 'Press and hold to set it plumb',
                    'steps_kicker' => 'How it works', 'steps_title' => 'Four steps. No surprises.',
                    'gal_title' => 'Rooms people actually live in.', 'gal_lede' => 'Warm materials, honest details, clean lines.',
                    'faq_kicker' => 'Straight answers', 'faq_title' => 'What homeowners ask us first.', 'faq_lede' => 'The questions we hear at almost every first visit.',
                    'blog_kicker' => 'From the blog', 'blog_title' => 'Read before you renovate.',
                    'areas_kicker' => 'Where we work', 'areas_title' => 'Toronto and the whole GTA.', 'areas_lede' => 'Not on the list? Call us anyway. If we can do the job right, we will.',
                ],
            ],
            [
                'path' => '/services/', 'template' => 'services', 'title' => 'Services', 'og' => 'home-renovation',
                'h1' => 'Everything your home needs, *built plumb.*',
                'seo_title' => 'Home Renovation Services in Toronto & the GTA',
                'meta' => 'Full home renovations, kitchens, bathrooms and basements across the GTA. One team, one fixed written quote, one project lead.',
                'intro' => '<p>Four services, one way of working: a fixed written quote, one project lead, payments tied to finished work and a clean site every day.</p>',
                'media' => ['hero' => 'home-renovation', 'help_image' => 'step-talk'],
                'data' => [
                    'kicker' => 'Services',
                    'help_kicker' => 'Not sure where to start?', 'help_title' => 'Tell us about the space. We\'ll tell you straight.',
                    'help_text' => 'Some jobs are one service. Most touch two or three: a kitchen that opens into the dining room, a basement that needs a bathroom. On the first visit we walk the house with you and give you an honest price range before we leave.',
                ],
            ],
            self::service('home-renovation', 'Full Home Renovation', 'Home Renovation', 'house', 'home-renovation',
                'Full Home Renovation in Toronto & the GTA',
                'Whole-home and multi-room renovations across the GTA. One written, fixed quote, one project lead, payments tied to finished work.',
                'One room or the whole house, planned and built by one crew.',
                'Some homes need one room fixed. Others need everything opened up and rethought. Either way, you get one crew, one plan and one person running it from the first measurement to the final walkthrough.',
                ['Layout changes and wall removals, with engineering where needed', 'Framing, drywall, trim, doors and paint', 'Plumbing and electrical, done by licensed trades', 'Flooring and tile throughout', 'Kitchens and bathrooms as part of the whole plan', 'Permits handled for you'], $estimate),
            self::service('kitchen-renovation', 'Kitchen Renovation', 'Kitchens', 'kitchen', 'kitchen',
                'Kitchen Renovation in Toronto & the GTA',
                'Custom kitchen renovations in the GTA: cabinets, counters, plumbing, electrical, flooring and tile, all under one fixed written quote.',
                'Cabinets, counters, plumbing, lighting. One crew handles all of it.',
                'The kitchen is where the day starts and ends. We build kitchens that work hard and look calm: good storage, good light, counters with room to actually cook.',
                ['Layout planning and design, including open-concept changes', 'Custom and semi-custom cabinetry, shelving and wall units', 'Quartz, stone and wood countertops', 'New sinks, taps and appliance hookups', 'Plumbing and electrical, including new lighting', 'Flooring, backsplash and tiling'], $estimate),
            self::service('bathroom-renovation', 'Bathroom Renovation', 'Bathrooms', 'bath', 'bathroom',
                'Bathroom Renovation in Toronto & the GTA',
                'Bathroom and ensuite renovations across the GTA. Proper waterproofing, clean tile work, new fixtures and a fixed written quote.',
                'Waterproofed properly, tiled cleanly.',
                'A bathroom is small, but it hides a lot of risk behind the tile. We take it down to the studs when it needs it, waterproof it properly, and finish it so it stays beautiful for years.',
                ['Full tear-out down to the studs when needed', 'Waterproofing behind every wet wall', 'Walk-in showers, glass enclosures and soaker tubs', 'Vanities, toilets, sinks and taps', 'Floor and wall tile, heated floors', 'Lighting, fans and electrical'], $estimate),
            self::service('basement-finishing', 'Basement Finishing', 'Basements', 'basement', 'basement',
                'Basement Finishing in Toronto & the GTA',
                'Basement finishing and renovation in the GTA: family rooms, guest suites, home gyms and offices. Dry, warm, permitted and built right.',
                'Basements finished into rooms your family actually uses.',
                'Your basement is the biggest room you are not using yet. We turn it into a warm, dry, bright space: a family room, a guest suite, a gym or an office.',
                ['Moisture check and fixes before anything is closed in', 'Insulation, framing and drywall', 'Pot lights, electrical and heating', 'Bathrooms and wet bars', 'Flooring, trim, doors and paint', 'Permits and inspections handled'], $estimate),
            [
                'path' => '/process/', 'template' => 'process', 'title' => 'How we work', 'og' => 'step-build',
                'h1' => 'Straight lines. *Straight talk.*',
                'seo_title' => 'How We Work: Our Renovation Process & Promise',
                'meta' => 'How RIELBUILD runs a renovation: a free visit, a fixed written quote, one project lead, payments tied to finished work and a clean site every day.',
                'intro' => '<p>The part of a renovation people dread is not the dust. It is not knowing what is happening, what it costs, or who to call. So we built our whole process around that.</p>',
                'media' => ['hero' => 'step-build', 'step1' => 'step-talk', 'step2' => 'step-design', 'step3' => 'step-build', 'step4' => 'step-walk'],
                'data' => [
                    'kicker' => 'How we work',
                    'steps_kicker' => 'Four steps', 'steps_title' => 'From first call to final walkthrough.',
                    'steps' => [
                        'Talk | A free visit to your home. We listen, measure and give you an honest price range before we leave.',
                        'Design and quote | We plan the layout and finishes with you, then hand you a fixed, written quote and timeline.',
                        'Build | One project lead runs the job. The site is protected, tidy every evening, and you always know what is next.',
                        "Walkthrough | We walk the finished space together, check everything on the list, and ensure you're happy so far.",
                    ],
                    'way_title' => 'The way we work', 'hold_label' => 'Press and hold to set it plumb',
                    'promises' => [
                        'A fixed, written quote | The price we write down is the price. Changes only happen when you agree in writing.',
                        'Payments tied to finished work | A small deposit, then you pay as each stage is done and you have seen it.',
                        'One project lead, start to finish | One person who knows your job, with a direct number that gets answered.',
                        'A clean site every day | Floors covered, dust sealed off, and the day swept up before we leave.',
                    ],
                    'worries_kicker' => 'What changes for you', 'worries_title' => 'The worries we take off your plate.',
                    'worries_lede' => 'We hear the same fears at almost every first visit. Here is how we answer each one.',
                    'worries' => [
                        '"What if it goes over budget?" | You get a fixed, written quote. Any change is priced and agreed in writing before it happens, never after.',
                        '"What if they disappear?" | You pay as each stage is finished, so we are never ahead of the work. And your project lead picks up the phone.',
                        '"How long will we live in a mess?" | You get a written timeline up front. The site is sealed, covered and cleaned at the end of every working day.',
                        '"Who fixes it if something\'s wrong?" | We walk the finished space with you, check everything on the list, and fix anything that is not right before we call it done.',
                        '"Can we live at home during the work?" | For one room or a bathroom, usually yes. We plan the order of work around your family and seal off the work area.',
                        '"What about permits?" | We prepare the drawings, apply for the permits and book every inspection. You never deal with the city.',
                    ],
                ],
            ],
            [
                'path' => '/projects/', 'template' => 'projects', 'title' => 'Projects', 'og' => 'kitchen-2',
                'h1' => 'Rooms people *actually live in.*',
                'seo_title' => 'Renovation Projects: Kitchens, Baths & Basements',
                'meta' => 'See the kind of kitchens, bathrooms, basements and full home renovations RIELBUILD builds across Toronto and the GTA.',
                'intro' => '<p>Warm materials, honest details and clean lines. A look at the kind of spaces we build across the GTA.</p>',
                'media' => ['hero' => 'kitchen-2'],
                'data' => ['kicker' => 'Projects', 'note' => 'Project imagery on this site is illustrative. Ask us for photos and references from recent jobs near you.'],
            ],
            [
                'path' => '/about/', 'template' => 'about', 'title' => 'About', 'og' => 'planning',
                'h1' => 'A builder that *works plumb.*',
                'seo_title' => 'About Us: An Honest Renovation Contractor, GTA',
                'meta' => 'RIELBUILD is a GTA renovation company built on honesty: fixed written quotes, one project lead, payments tied to finished work.',
                'intro' => '<p>Plumb is a builder\'s word for dead straight. It is how we frame a wall, and it is how we deal with people.</p>',
                'body' => '<p>We take honesty and integrity seriously, so we built them into how every job runs. You get a fixed, written quote. You pay as each stage is finished. You have one project lead with a direct number. And the site is cleaned up at the end of every day, because it is still your home.</p><p>Every home is different. That is why we give every client a plan built for their house, their family and their budget, and then we stick to it.</p>',
                'media' => ['hero' => 'planning', 'who_image' => 'step-design'],
                'data' => [
                    'kicker' => 'About us',
                    'who_kicker' => 'Who we are', 'who_title' => 'Honesty is not a slogan here. It is the system.',
                    'who_lede' => 'RIELBUILD is a renovation company serving Toronto and the Greater Toronto Area. We do full and partial home renovations, kitchens, bathrooms and basement finishing.',
                    'who_tag' => 'Measure twice. Quote once.',
                    'values_kicker' => 'What we stand for', 'values_title' => 'Three things we will not bend on.',
                    'values' => [
                        'Straight talk | If something will cost more, take longer or not work, we tell you before it happens. No surprises in the final bill.',
                        'Built true | Proper waterproofing, proper framing, proper permits. The parts you never see are built as carefully as the parts you do.',
                        'Respect for your home | Floors covered, dust sealed off, and a tidy site every evening. We work like guests, because we are.',
                    ],
                    'stats' => ['4 | Core renovation services', '1 | Project lead per job', '14 | GTA communities served', '$0 | For your estimate'],
                ],
            ],
            [
                'path' => '/faq/', 'template' => 'faq', 'title' => 'FAQ', 'og' => 'step-talk',
                'h1' => 'Ask us *anything.*',
                'seo_title' => 'Renovation FAQ: Deposits, Timelines & Permits',
                'meta' => 'Straight answers to what GTA homeowners ask about renovations: deposits, written quotes, timelines, permits, cost and living at home during the work.',
                'intro' => '<p>The questions we hear at almost every first visit, answered plainly. Do not see yours? Call us, we like questions.</p>',
                'media' => ['hero' => 'step-talk'],
                'data' => ['kicker' => 'Straight answers', 'general_kicker' => 'Before you start', 'general_title' => 'Money, time and trust.', 'byservice_kicker' => 'By project', 'byservice_title' => 'Questions about specific jobs.'],
            ],
            [
                'path' => '/areas/', 'template' => 'areas', 'title' => 'Service areas', 'og' => 'hero-start',
                'h1' => 'Toronto and *the whole GTA.*',
                'seo_title' => 'Service Areas: Toronto, Vaughan, Markham & GTA',
                'meta' => 'RIELBUILD renovates homes across Toronto, North York, Etobicoke, Scarborough, Vaughan, Woodbridge, Richmond Hill, Markham, Mississauga and the GTA.',
                'intro' => '<p>Kitchens, bathrooms, basements and full renovations, from downtown Toronto to York, Peel and Durham.</p>',
                'media' => ['hero' => 'hero-start'],
                'data' => [
                    'kicker' => 'Where we work',
                    'areas' => ['Toronto', 'North York', 'Etobicoke', 'Scarborough', 'East York', 'Vaughan', 'Woodbridge', 'Richmond Hill', 'Markham', 'Thornhill', 'Mississauga', 'Oakville', 'Aurora', 'Newmarket'],
                    'list_kicker' => 'Communities we serve', 'list_title' => 'If it\'s in the GTA, we\'ll come see it.',
                    'list_lede' => 'Every first visit is free. Not on the list? Call anyway. If we can do the job right, we will.',
                    'popular_kicker' => 'Popular in your area', 'popular_title' => 'What GTA homeowners ask us for most.',
                    'popular' => [
                        'Basements in Vaughan, Markham and Richmond Hill | Family rooms, guest suites and home gyms, with moisture checks and permits handled. | /services/basement-finishing/ | Basement finishing',
                        'Kitchens across Toronto and Etobicoke | Open-concept layouts, custom cabinetry and new lighting in older homes. | /services/kitchen-renovation/ | Kitchen renovation',
                        'Bathrooms in North York and Mississauga | Walk-in showers, heated floors and proper waterproofing. | /services/bathroom-renovation/ | Bathroom renovation',
                    ],
                ],
            ],
            [
                'path' => '/contact/', 'template' => 'contact', 'title' => 'Contact', 'og' => 'step-talk',
                'h1' => 'Let\'s talk about *your home.*',
                'seo_title' => 'Contact Us: Book a Free Renovation Estimate',
                'meta' => 'Book a free in-home estimate with RIELBUILD. Call 647-895-4555 or send the form. A project lead calls back within one business day.',
                'intro' => '<p>Book a free estimate. We visit, listen, measure and give you an honest price range before we leave.</p>',
                'media' => ['hero' => 'step-talk'],
                'data' => [
                    'kicker' => 'Contact', 'talk_kicker' => 'Talk to a project lead', 'talk_title' => 'Straight to the person who runs the job.',
                    'phone_note' => 'Mon to Sat, 8am to 6pm', 'email_note' => 'We reply within one business day',
                    'area_line' => 'Toronto and the GTA', 'area_note' => 'We come to you',
                    'next_text' => 'We call to book a time, visit your home, and give you an honest range on the spot. A fixed written quote follows once the plan is set.',
                ],
            ],
            [
                'path' => '/blog/', 'template' => 'blog_index', 'title' => 'Blog', 'og' => 'planning',
                'h1' => 'Renovation advice, *straight.*',
                'seo_title' => 'Renovation Blog: Tips for GTA Homeowners',
                'meta' => 'Practical renovation advice for GTA homeowners: planning a kitchen, finishing a basement, bathroom waterproofing, budgets, permits and how payments should work.',
                'intro' => '<p>What we tell homeowners at the kitchen table, written down: planning, budgets, permits and the details that make a renovation last.</p>',
                'media' => ['hero' => 'planning'],
                'data' => ['kicker' => 'Blog'],
            ],
        ];
    }

    private static function service(string $slug, string $name, string $short, string $icon, string $img, string $seo, string $meta, string $tag, string $lead, array $includes, string $time): array
    {
        return [
            'path' => '/services/' . $slug . '/', 'template' => 'service', 'parent' => '/services/', 'title' => $name, 'h1' => $name,
            'seo_title' => $seo, 'meta' => $meta, 'og' => $img, 'media' => ['photo' => $img],
            'data' => [
                'name' => $name, 'short' => $short, 'tag' => $tag, 'icon' => $icon, 'still' => 'stills/' . ($img === 'home-renovation' ? 'home-renovation' : $img),
                'kicker' => $short . ' in the GTA', 'how_kicker' => 'How we do it', 'how_title' => 'Done right, the first time.',
                'lead' => $lead, 'includes' => $includes, 'time' => $time,
                'way_kicker' => 'The way we work', 'way_title' => 'What you get on every job.',
            ],
        ];
    }

    // ------------------------------------------------------------------

    private static function faqs(): int
    {
        $rows = [
            ['general', '/', 'How much do I pay up front?', 'A small deposit to book your start date. After that, every payment is tied to a finished stage of work that you can see with your own eyes. You never pay for work that has not been done.'],
            ['general', '/', 'Will I get a written quote?', 'Always. After we visit and understand the job, you get a fixed, written quote that lists what is included. If something changes, we agree on it in writing before we do it.'],
            ['general', '/', 'How long will my renovation take?', "It depends on the size and requirements. When we visit you for your free estimate, we'll discuss the timeline in detail."],
            ['general', '/', 'How much will it cost?', 'It depends on the size and the finishes you choose. Book a free estimate and we will give you an honest range on the spot, then a fixed written quote.'],
            ['general', '/', 'Do I need to move out during the work?', 'For one room or a bathroom, usually not. For a major renovation, it can be easier to stay elsewhere for part of it. We plan the work around your family, kids and pets.'],
            ['general', '/', 'What if I change my mind halfway?', 'Changes are welcome. Before any change, we tell you what it does to the price and the timeline, and we only go ahead once you agree in writing.'],
            ['general', '', 'Do you handle permits?', 'Yes. We prepare the drawings, apply for permits and book the inspections, so you do not have to deal with the city.'],
            ['general', '', 'Who will I talk to during the project?', 'One project lead, from start to finish. You get their direct number, and they pick up.'],
            ['general', '', 'How do you deal with dust and mess?', 'We seal off the work area, protect your floors and furniture, and clean up at the end of every day. Your home should still feel like your home.'],
            ['home-renovation', '', 'Do we need to move out?', 'For a full gut renovation, usually yes, for part of the job. For a few rooms at a time, most families stay. We plan the order of work around how you live.'],
            ['home-renovation', '', 'Can we renovate in phases?', 'Yes, a first phase may include the kitchen and main floor for example, then the basement or bathrooms can be done as a second phase, and so on. We design it as one plan with phases that work best for you.'],
            ['kitchen-renovation', '', 'Can we keep using the kitchen during the work?', 'Not the room itself, but we can set up a simple temporary kitchen elsewhere in the house so you still have a fridge, microwave and sink nearby.'],
            ['kitchen-renovation', '', 'Do you supply cabinets or can we buy our own?', 'Either works. We can source and install cabinets through our suppliers, or install ones you pick. We will tell you honestly if a product will cause problems.'],
            ['bathroom-renovation', '', 'We only have one bathroom. What happens?', 'Tell us early. We plan the work so the toilet is out of action for as short a time as possible, and we can arrange a temporary option if needed.'],
            ['bathroom-renovation', '', 'Do you do heated floors?', 'Yes. Electric in-floor heat is a common add-on and is installed under the tile before it goes down.'],
            ['basement-finishing', '', 'Do I need a permit to finish my basement?', 'In most GTA cities, yes, especially if you add a bathroom or change walls. We handle the drawings and the permit for you.'],
            ['basement-finishing', '', 'My basement gets a little damp. Can you still finish it?', 'Yes, but we fix the moisture first. Closing in a damp basement is how mould happens, and we will not do it.'],
        ];
        $n = 0;
        foreach ($rows as $i => [$cat, $on, $q, $a]) {
            if (Db::value('SELECT 1 FROM faqs WHERE question = ? AND category = ?', [$q, $cat])) continue;
            $id = Db::insert('faqs', ['question' => $q, 'answer_html' => '<p>' . e($a) . '</p>', 'category' => $cat, 'show_on' => $on !== '' ? $on : null,
                'sort' => ($i + 1) * 10, 'is_visible' => 1, 'updated_at' => Time::nowUtc()]);
            ContentAdmin::reindexFaq($id);
            $n++;
        }
        return $n;
    }

    private static function projects(): int
    {
        $rows = [
            ['kitchen', 'White oak kitchen with stone island', 'Kitchen', 'kitchen', 1],
            ['basement', 'Family lounge with fireplace wall', 'Basement', 'basement', 1],
            ['bathroom', 'Spa ensuite with walk-in shower', 'Bathroom', 'bathroom', 1],
            ['home-renovation', 'Open-concept main floor', 'Full home', 'home', 1],
            ['powder-room', 'Powder room in green plaster', 'Bathroom', 'bathroom', 1],
            ['mudroom', 'Mudroom with built-in benches', 'Full home', 'home', 0],
            ['basement-bar', 'Basement wet bar and games room', 'Basement', 'basement', 0],
            ['kitchen-2', 'Galley kitchen with brass details', 'Kitchen', 'kitchen', 0],
        ];
        $n = 0;
        foreach ($rows as $i => [$img, $title, $cat, $filter, $home]) {
            if (Db::value('SELECT 1 FROM projects WHERE title = ?', [$title])) continue;
            Db::insert('projects', ['title' => $title, 'category' => $cat, 'filter_key' => $filter, 'media_id' => self::mid($img), 'alt' => null,
                'on_home' => $home, 'sort' => ($i + 1) * 10, 'is_visible' => 1, 'updated_at' => Time::nowUtc()]);
            $n++;
        }
        return $n;
    }

    private static function menus(): int
    {
        $n = MenusAdmin::ensureMenus();
        foreach (MenusAdmin::defaults() as $menu => $items) {
            if (!$items || Db::value('SELECT 1 FROM menu_items WHERE menu = ?', [$menu])) continue;
            $n += MenusAdmin::applyDefaults($menu, $items, 'reset');
        }
        Menus::flush();
        return $n;
    }

    private static function posts(): int
    {
        $n = 0;
        $cats = ['Kitchens' => 'kitchen-renovation', 'Basements' => 'basement-finishing', 'Bathrooms' => 'bathroom-renovation', 'Planning' => 'planning'];
        foreach ((static fn(): array => require APP_DIR . '/seed/posts.php')() as $p) {
            if (Db::value('SELECT 1 FROM posts WHERE slug = ?', [$p['slug']])) continue;
            $img = basename((string)($p['img'] ?? ''));
            $at = Time::toUtc(($p['date'] ?? Time::today()) . ' 09:00:00');
            $id = Db::insert('posts', [
                'slug' => $p['slug'], 'category' => $cats[$p['category'] ?? ''] ?? 'tips', 'title' => $p['title'],
                'seo_title' => $p['seo_title'] ?? null, 'meta_description' => $p['seo_desc'] ?? null, 'excerpt' => $p['excerpt'] ?? null,
                'body_html' => Sanitizer::html((string)$p['body']), 'featured_media_id' => $img !== '' ? self::mid($img) : null,
                'tags' => $p['tags'] ?? (($p['category'] ?? '') . ', renovation, GTA'), 'author_name' => $p['author'] ?? 'RIELBUILD team',
                'status' => 'published', 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            ContentAdmin::reindexPost($id);
            $n++;
        }
        return $n;
    }

    private static function forms(): int
    {
        if (Db::value("SELECT 1 FROM form_types WHERE type_key = 'contact'")) return 0;
        Db::insert('form_types', ['type_key' => 'contact', 'label' => 'Estimate request', 'is_enabled' => 1, 'recipients' => site('email.main', 'contact@rielbuild.ca'),
            'send_autoreply' => 0, 'retention_days' => 730, 'updated_at' => Time::nowUtc()]);
        return 1;
    }
}
