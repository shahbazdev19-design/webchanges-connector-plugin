---
name: GSAP ScrollTrigger
description: Scroll-driven animation with ScrollTrigger — reveal on scroll, scrub, pin, start/end, toggleActions, batch for many elements. Use when an animation should respond to scroll position.
version: 1.0.0
tags: gsap, scrolltrigger, scroll, animation
---

# GSAP ScrollTrigger

ScrollTrigger ties a tween/timeline to scroll position. Register it once:
`gsap.registerPlugin(ScrollTrigger);` (the `gsap-animations` loader already does
this site-wide).

## Reveal on scroll (most common)

```js
gsap.from(".section", {
  y: 60, opacity: 0, duration: 0.9, ease: "power3.out",
  scrollTrigger: { trigger: ".section", start: "top 85%", toggleActions: "play none none none" }
});
```

- `start` / `end`: `"triggerPos viewportPos"`, e.g. `"top 85%"` = when the
  trigger's top hits 85% down the viewport. `end: "bottom top"`.
- `toggleActions: "onEnter onLeave onEnterBack onLeaveBack"` — usually
  `"play none none none"` (play once) or `"play reverse play reverse"`.
- `once: true` — fire a single time then kill.
- `markers: true` — debug overlay (remove for production).

## Scrub (animation follows the scrollbar)

```js
gsap.to(".bg", {
  yPercent: -30, ease: "none",
  scrollTrigger: { trigger: ".bg", start: "top bottom", end: "bottom top", scrub: true }
});
```

`scrub: true` links progress to scroll; `scrub: 1` adds 1s smoothing. Use
`ease: "none"` with scrub.

## Pin a section

```js
ScrollTrigger.create({
  trigger: ".panel", start: "top top", end: "+=100%", pin: true, pinSpacing: true
});
```

## Many elements efficiently — batch

```js
ScrollTrigger.batch(".card", {
  start: "top 88%",
  onEnter: (els) => gsap.to(els, { opacity: 1, y: 0, stagger: 0.12, overwrite: true }),
});
gsap.set(".card", { opacity: 0, y: 30 });
```

## Gotchas

- Call `ScrollTrigger.refresh()` after the DOM changes height (lazy images,
  AJAX, accordions opening). Set explicit image dimensions to avoid layout
  shift breaking start/end calc.
- With page builders, the element you target must exist at load — add a custom
  class via the builder, then select it.
- Respect reduced motion: wrap scrub/pin in `gsap.matchMedia()` and skip for
  `(prefers-reduced-motion: reduce)`.
