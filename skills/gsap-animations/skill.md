---
name: GSAP Animations
description: Add professional scroll-triggered and entrance animations to any page using GSAP + ScrollTrigger, via a declarative data-anim attribute system. Runnable macro installs the loader site-wide.
version: 1.0.0
tags: animation, gsap, scrolltrigger, frontend, bricks, elementor, gutenberg
---

# GSAP Animations

Add polished, performant animations (entrance reveals, scroll effects, staggers,
parallax) to a WordPress site using [GSAP](https://gsap.com) + ScrollTrigger.

This skill uses a **declarative** approach: a small loader script reads
`data-anim` attributes off any element and animates it. You add attributes to
elements — you don't write per-element JavaScript. That keeps it builder-agnostic
(Bricks, Elementor, Gutenberg, raw HTML) and easy to undo.

## Step 1 — Install the loader (once per site)

Run this skill's macro:

```
webchanges/skills-run { "slug": "gsap-animations" }
```

That writes an mu-plugin (`wp-content/mu-plugins/webchanges-gsap.php`) which
enqueues GSAP 3 + ScrollTrigger from CDN and registers the `data-anim` driver on
the front end. It loads on every page automatically, only on the front end, and
respects `prefers-reduced-motion`. Idempotent — re-running just rewrites the file.

To check it's installed: `webchanges/read-file { "path": "wp-content/mu-plugins/webchanges-gsap.php" }`.

## Step 2 — Animate elements by adding attributes

Add these attributes to any element. The driver animates it when it scrolls into view.

| Attribute | Values | Effect |
|---|---|---|
| `data-anim` | `fade-up`, `fade-down`, `fade-left`, `fade-right`, `zoom-in`, `zoom-out`, `fade` | Entrance animation as it enters the viewport |
| `data-anim-duration` | seconds (default `0.8`) | Animation length |
| `data-anim-delay` | seconds (default `0`) | Delay before it starts |
| `data-anim-start` | ScrollTrigger start (default `top 85%`) | When it fires, e.g. `top 70%` |
| `data-anim-stagger` | seconds (default `0.12`) | Animate the element's CHILDREN in sequence (great for lists/grids/cards) |
| `data-parallax` | number, e.g. `0.2` | Subtle parallax drift on scroll (use on images/backgrounds) |

### How to add attributes per builder

- **Bricks:** set element custom attributes. Read the element with
  `webchanges/bricks-get-elements`, then `webchanges/bricks-update-element` with
  `settings._attributes` = `[{"name":"data-anim","value":"fade-up"}]`. For a
  staggered grid, put `data-anim-stagger` on the container so its child columns
  reveal in sequence.
- **Elementor:** add to the element's `_attributes` via
  `webchanges/elementor-update-element` (Pro: Advanced → Attributes), or wrap
  content in an HTML widget carrying the attribute.
- **Gutenberg / blocks:** add the attribute to a block's wrapper. Many blocks
  support `Additional CSS class` only; for arbitrary attributes use a
  `core/html` block, or add a class and target it from custom code.
- **Raw HTML / templates:** just put the attribute on the tag:
  `<section data-anim="fade-up" data-anim-duration="1">…</section>`.

## Recipes

- **Hero headline reveal:** on the H1, `data-anim="fade-up" data-anim-duration="1"`;
  on the subhead, add `data-anim-delay="0.15"`; on the CTA button `data-anim-delay="0.3"`.
- **Card grid that cascades in:** put `data-anim-stagger="0.1"` on the grid/row
  container. Its direct children animate one after another.
- **Section slides from the side:** `data-anim="fade-right"` on left-column text,
  `data-anim="fade-left"` on the right-column image.
- **Parallax hero image:** `data-parallax="0.15"` on the image.

## Guardrails

- **Accessibility:** the loader auto-disables animations for visitors with
  `prefers-reduced-motion: reduce`. Don't override that.
- **Above the fold:** keep hero animations short (≤1s) and avoid long delays so
  content isn't hidden on load. Never animate critical text with a long delay.
- **Don't over-animate:** one entrance per section reads as premium; animating
  every element reads as noisy.
- **Caching:** if a page caching plugin (WP Rocket here) serves stale HTML, the
  attributes still work because the driver runs client-side; but purge cache
  after big template edits so new attributes are present in the HTML.
- **Removing it:** delete `wp-content/mu-plugins/webchanges-gsap.php` (via
  `webchanges/delete-file`) to remove GSAP site-wide; remove `data-anim`
  attributes from elements to stop animating individual ones.

## Notes

- Loads GSAP 3.12.5 + ScrollTrigger from cdnjs. If the site must be fully
  self-hosted (no third-party CDN), download the two libraries into the theme
  and edit the mu-plugin's `wp_enqueue_script` URLs to point at local copies.
- The driver only initialises each element once (`data-wc-anim-done`), so it's
  safe with AJAX/infinite-scroll re-runs if you call its init again.
