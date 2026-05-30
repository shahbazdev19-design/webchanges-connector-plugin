---
name: GSAP Plugins
description: GSAP plugins overview — ScrollTrigger, ScrollSmoother, Flip, Draggable, Observer, SplitText, ScrambleText, MotionPath, Inertia. Which to use for which effect and how to register them. (GSAP 3.12+ made the former Club plugins free.)
version: 1.0.0
tags: gsap, plugins, scrollsmoother, flip, splittext, draggable
---

# GSAP Plugins

Register any plugin once before use: `gsap.registerPlugin(ScrollTrigger, Flip, SplitText);`
Plugins are separate script files — enqueue the ones you use (cdnjs:
`gsap/3.12.5/<Plugin>.min.js`).

## Pick the plugin for the effect

- **ScrollTrigger** — scroll-driven reveals/scrub/pin. (See `gsap-scrolltrigger`.)
- **ScrollSmoother** — smooth/inertial page scrolling + built-in parallax via
  `data-speed` / `data-lag`. Requires a wrapper/content structure and
  ScrollTrigger. Use sparingly; can fight page builders & accessibility.
- **SplitText** — split text into chars/words/lines for staggered text reveals:
  ```js
  let split = new SplitText(".headline", { type: "words,chars" });
  gsap.from(split.chars, { yPercent: 100, opacity: 0, stagger: 0.02, ease: "back.out(1.7)" });
  ```
- **Flip** — animate between two layout states (FLIP technique): record state,
  change the DOM/classes, then `Flip.from(state, { duration: 0.6 })`. Great for
  filtering grids, expanding cards, shared-element transitions.
- **Draggable (+ InertiaPlugin)** — drag/throw UI, sliders, custom carousels.
- **Observer** — unified wheel/touch/pointer events without ScrollTrigger
  (e.g. full-page section snapping).
- **MotionPathPlugin** — animate along an SVG path (`motionPath: { path: "#route" }`).
- **ScrambleText / TextPlugin** — typewriter / scramble text effects.

## Notes

- As of GSAP 3.12, the previously paid plugins (SplitText, ScrollSmoother,
  MorphSVG, Draggable, Inertia, etc.) are **free**. Still load only what you use.
- SplitText changes DOM (wraps spans) — re-run `ScrollTrigger.refresh()` after,
  and revert with `split.revert()` if needed for accessibility/SEO.
- ScrollSmoother + Bricks/Elementor: test carefully; sticky headers and the
  builder's own scroll effects can conflict. The `gsap-animations` declarative
  `data-anim` system is usually enough and lighter-weight.
