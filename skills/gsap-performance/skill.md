---
name: GSAP Performance
description: Keep GSAP animations fast and smooth — animate transforms/opacity, avoid layout thrashing, use will-change wisely, batch ScrollTriggers, respect reduced motion, and avoid CLS. Use when optimising or reviewing animation code.
version: 1.0.0
tags: gsap, performance, core-web-vitals, optimization
---

# GSAP Performance

Smooth = 60fps = stay on the compositor and off the main-thread layout path.

## Animate the cheap properties

- ✅ `x`, `y`, `scale`, `rotation`, `opacity`/`autoAlpha` — GPU compositor, no reflow.
- ⚠️ Avoid animating `width`, `height`, `top`, `left`, `margin`, `padding` — they
  trigger layout every frame. Use transforms instead (`x`/`y` not `left`/`top`,
  `scale` not `width`).
- For colour/box-shadow, keep counts low; they paint each frame.

## Don't thrash layout

- Read all measurements first, then write — never read (`offsetTop`) inside a
  per-frame loop after writing. Cache values before the tween.
- Set start states with `gsap.set()` once, not repeatedly.

## will-change

- GSAP auto-manages `will-change` during transform tweens. Don't slap
  `will-change: transform` on hundreds of elements in CSS — it costs memory.
  Let GSAP add/remove it, or apply only to a few heavy hero elements.

## Scroll work

- Use `ScrollTrigger.batch()` for many similar elements instead of one
  ScrollTrigger each.
- Use `scrub: 1` (smoothing) rather than `scrub: true` for heavy scrubbed scenes.
- Call `ScrollTrigger.refresh()` after layout changes; set explicit
  width/height on images/embeds so start/end stay correct.

## Core Web Vitals / UX

- **CLS:** never animate layout-affecting properties on load; reserve space.
  Animating `opacity`/`transform` doesn't shift layout.
- **LCP:** don't hide the largest hero element behind a long delay/opacity:0 —
  keep hero reveals short (≤1s) or animate after paint.
- **Reduced motion:** gate non-essential motion with
  `gsap.matchMedia("(prefers-reduced-motion: reduce)", ...)` and `clearProps`.
- Kill offscreen/initial animations you don't need; fewer concurrent tweens = smoother.

## Loading

- Load GSAP in the footer (`true` for `$in_footer`), defer plugins you don't use,
  and prefer one shared GSAP instance site-wide (the `gsap-animations` loader)
  over per-page copies.
