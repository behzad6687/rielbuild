# RIELBUILD: Design Package

The single source for the rebuild. Copy below ships verbatim. Band ranges are starting points, validated by the flick test.

## 1. Brand premise

**Plumb.** A builder's word for true vertical: a line that is dead straight, checked with a weight on a string. RIELBUILD builds plumb, in the walls and in the way it deals with people: a written fixed quote, payments tied to finished work, one project lead who picks up the phone, and a clean site every day. Every section on the site teaches and sells that one idea. The signature element is a brass plumb line that hangs down the page and settles true at each section. The one interactive moment asks the visitor to hold a swinging plumb bob still until it reads true.

## 2. Palette (warm and crafted, sampled from the dusk-to-lamplight footage)

```css
:root{
  --canvas:#F2EDE4;       /* warm plaster, light sections */
  --canvas-2:#E8E1D5;     /* plaster in shade */
  --ink:#141D19;          /* deep forest charcoal, dark sections + hero */
  --panel:#1C2A24;        /* raised surface on dark */
  --forest:#23402F;       /* brand green */
  --accent:#C4913F;       /* brushed brass: CTA, focus, rare emphasis */
  --accent-hover:#D6A553;
  --accent-muted:rgba(196,145,63,.28);
  --text-primary:#18201C; /* on plaster */
  --text-secondary:#555D57;
  --text-on-dark:#F2EDE4;
  --text-on-dark-2:#B9BFB6;
}
```

Deviation said out loud: plaster + green + brass sits near the "cream canvas" family the skill warns about. It is earned here because plaster, white oak and brass are literally the materials in the footage, and the accent is brass on forest, not terracotta.

## 3. Type trio

- Display: **Bricolage Grotesque** 600, 700 (optical size axis on). Warm, hand-cut grotesque with carpenter-pencil character.
- Body: **Public Sans** 400, 500. Quiet, architectural, very legible.
- Mono labels: **IBM Plex Mono** 500. Blueprint callouts, step numbers, measurements.

## 4. Storyboard (Tier 2, three chained segments, about 18 seconds, hero about 1000vh)

| Seg | World | Camera | Boundary / lens moment | Final frame | Text lives |
|---|---|---|---|---|---|
| 1 | Blue hour outside a renovated two-storey GTA brick home, black-framed windows, one big ground-floor window glowing warm | Slow, steady forward push straight toward the glowing window | Passing through the glass: soft reflection flare and a short blur beat | Mid-motion, just inside, the finished kitchen opening up ahead | Left third (house sits centre-right) |
| 2 | The finished kitchen: white oak cabinets, warm plaster walls, stone island, brass pendants, lamplight | Continuous forward glide past the island, gentle arc toward an open staircase going down | none | Mid-motion at the top of the stairs, camera beginning to tilt down | Left then right as the glide passes |
| 3 | The stairs down into a warm finished basement: oak floor, built-in shelves, fireplace wall, deep sofa, soft lamps | Descends the stairs, then settles into a wide, level view of the lounge | none | At rest: wide, level, symmetric basement lounge, calm upper third | Upper centre (settle) |

Seams sit inside motion (just after the glass blur, and mid-stair), per the seam law.

## 5. Band map

| Band | Range | Footage moment | Copy (verbatim) | Entrance |
|---|---|---|---|---|
| 1 | 0.00 to 0.12 | Dusk house, still far | **Your home, built plumb.** / Renovations and custom builds across the GTA. Straight lines, straight talk. | Word rise, load ramp |
| 2 | 0.16 to 0.30 | Pushing toward the window | **No vanishing after the deposit.** / A written, fixed quote. Payments tied to finished work. | Approach from depth |
| 3 | 0.36 to 0.50 | Gliding through the kitchen | **Kitchens you'll want to cook in.** / Cabinets, counters, plumbing, lighting. One crew handles all of it. | Grid snap, sliding with the glide |
| 4 | 0.54 to 0.68 | Arc toward the stairs | **One project lead. One number.** / You always know who to call, and they pick up. | Blur to sharp |
| 5 | 0.72 to 0.86 | Descending the stairs | **Then we head downstairs.** / Basements finished into rooms your family actually uses. | Drift down, with the descent |
| 6 | 0.90 to 1.00 | Rest on the basement lounge | **Let's build it right.** / Free consultation. A clear, written quote. / [Book a free consultation] [647-895-4555] | Staged settle |

## 6. Static hero (phones, portrait tablets, reduced motion)

Over the ending frame: **Your home, built plumb.** / Renovations, basements, kitchens and custom builds across the GTA. Straight lines, straight talk. / [Book a free consultation] [Call 647-895-4555]

## 7. Site map (multi-page for SEO)

Home, Services (overview + Home Renovation, Kitchen Renovation, Bathroom Renovation, Basement Finishing, Design-Build, Custom Home Construction), Process, Projects, About, FAQ, Service Areas, Contact, 404. Every page funnels to one call to action: **Book a free consultation** (contact page form).

## 8. Home below the fold

1. **What we build**: six service cards with image preview.
2. **The plumb promise** (interactive moment): "Hold to set it plumb." Holding settles the swinging bob; four promises light up in turn: A fixed, written quote. Payments tied to finished work. One project lead, start to finish. A clean site at the end of every day.
3. **How it works**: four steps on a self-drawing line (Talk, Design and quote, Build, Walk through), each with its own image.
4. **Recent work** (illustrative imagery): gallery strip.
5. **Straight answers** FAQ: deposit, timeline, cost, living at home during the work, changes mid-project, permits, dust.
6. **Where we work**: GTA area list.
7. **Final CTA** with the short form.

Form: demo mode (validates, shows a real thank-you, sends nothing). `config.php` switch turns on PHP mail to contact@rielbuild.ca.

Footer note: "Project imagery on this site is illustrative."

## 9. Vector layer

Brass plumb line down the left gutter (desktop), drawn by scroll, bob swings and settles per section. Blueprint dimension lines that draw on scroll under section headings. Faint plaster grain + slow warm light drift as the one fixed background layer. All of it honours reduced motion.

## 10. Engineering list

Streamed Blob fetch with loading ring and watchdog, dt-normalized lerp, gated seeks with error escape, delta-gated DOM writes, band pacing with flick test, four-layer legibility system, the five static-hero gates live in CSS and JS, complete without video, reduced motion live both ways, overflow clip, PHP 7.4 compatible includes, folder-agnostic base path, full SEO (unique titles and descriptions, canonical, Open Graph, JSON-LD GeneralContractor + Service + FAQPage + BreadcrumbList, sitemap.xml, robots), demo noindex switch.

## 11. Copy gate

Every viewer-facing line ships verbatim, and the built pages pass the grep gate: zero em dashes, zero stock words (leverage, seamless, empower, unlock, robust, actionable, data-driven, solutions), plus the AI-tell sweep.
