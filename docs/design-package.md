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

## 4. Storyboard (as built: two chained segments, 12 seconds, hero 850vh)

| Seg | World | Camera | Boundary / lens moment | Final frame |
|---|---|---|---|---|
| 1 | Blue hour outside a renovated two-storey GTA brick home, big ground-floor window glowing | Slow, steady push straight toward the glowing window | Through the glass: soft flare and a short blur beat | Mid-motion inside the white oak kitchen |
| 2 | The kitchen, then a cutaway down through the floor | Tilts down and sinks vertically through the floor (joists, insulation, copper pipes shown as a section cut), emerges from the basement ceiling | The floor cross-section is the lens moment and the texture refresh | At rest: wide, level, symmetric basement lounge with fireplace and oak built-ins |

Revision note: two earlier versions of segment 2 were rejected at the video gate because the stairs made no architectural sense (a stair going up, then a hatch in the kitchen aisle, then a doorway through a solid wall). The floor cutaway replaced the stair idea: no invented doors, and scrolling down literally goes down.

## 5. Band map (as built, validated by the flick test and the worst-frame audit)

| Band | Range | Footage moment | Copy (verbatim) | Entrance | Worst-pixel contrast |
|---|---|---|---|---|---|
| 1 | 0.00 to 0.14 | Dusk house, still far | **Your home, built plumb.** / Renovations and custom builds across the GTA. Straight lines, straight talk. | Word rise, load ramp | 5.57:1 |
| 2 | 0.18 to 0.36 | Pushing toward the window | **No vanishing after the deposit.** / A written, fixed quote. Payments tied to finished work. | Approach from depth | 4.96:1 |
| 3 | 0.47 to 0.63 | Inside the kitchen | **Kitchens you'll want to cook in.** / Cabinets, counters, plumbing, lighting. One crew handles all of it. | Grid snap | 3.73:1 |
| 4 | 0.67 to 0.82 | Sinking through the floor | **We build what's behind the walls.** / Framing, plumbing, insulation. The parts you never see, done right. | Blur to sharp | 4.78:1 |
| 5 | 0.87 to 1.00 | Rest on the basement lounge | **Let's build it right.** / Free consultation. A clear, written quote. / [Book a free consultation] [647-895-4555] | Staged settle | 4.35:1 |

Flick test: every band holds full opacity for 6 to 8 steps at 120px, and none is skippable at 360px.

## 6. Phones and the static hero

Phones and portrait tablets get the same scroll film, from a portrait cut (`hero-scrub-m.mp4`, 810x1080, 2.8 MB) that pans with the action: framed on the glowing window for the house and kitchen, then easing to centre for the floor cutaway and basement. Captions sit low above the thumb bar on a bottom shade (worst-pixel contrast 3.9:1 to 9.9:1 at 390px). Rotating swaps to the matching cut at the same scroll position. iOS is primed with a muted play-then-pause.

The still hero (dusk house crop, "Your home, built plumb.") is kept only for reduced-motion visitors and Data Saver.

## 7. Site map (multi-page for SEO)

Home, Services (overview + Home Renovation, Kitchen Renovation, Bathroom Renovation, Basement Finishing), Process, Projects, About, FAQ, Service Areas, Contact, 404. Every page funnels to one call to action: **Book a free consultation** (contact page form).

## 8. Home below the fold

1. **What we build**: four service cards with image preview. (Design-Build and Custom Home Construction were removed at the client's request: RIELBUILD does not offer them.)
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
