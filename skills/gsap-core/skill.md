---
name: GSAP Core
description: Core GSAP API — gsap.to / from / fromTo / set, eases, duration, delay, stagger, repeat/yoyo, and gsap.matchMedia for responsive + reduced-motion. Use when writing any custom GSAP animation code.
version: 1.0.0
tags: gsap, animation, core, javascript
---

# GSAP Core

GSAP animates any numeric property of DOM nodes (or JS objects). Load GSAP first
— on this site the `gsap-animations` skill installs GSAP + ScrollTrigger
site-wide; otherwise enqueue `gsap.min.js`.

## The four tween methods

```js
gsap.to(target, { x: 100, opacity: 1, duration: 0.8 });        // animate TO these values
gsap.from(target, { y: 40, opacity: 0, duration: 0.8 });       // animate FROM these values to current
gsap.fromTo(target, { opacity: 0 }, { opacity: 1, duration: 1 }); // explicit start + end
gsap.set(target, { autoAlpha: 0 });                            // instantly set (no tween)
```

`target` is a CSS selector string, element, NodeList, or array. Prefer
transforms (`x`, `y`, `scale`, `rotation`) and `opacity`/`autoAlpha` — they are
GPU-accelerated and don't trigger layout. `autoAlpha` = opacity + visibility.

## Common vars

- `duration` (s), `delay` (s), `ease` (string), `repeat` (n, `-1` = infinite),
  `yoyo: true` (reverse on repeat), `repeatDelay`, `paused: true`.
- `stagger`: number (seconds between each) or object
  `{ each: 0.1, from: "center", grid: "auto" }` for grids.
- Callbacks: `onComplete`, `onStart`, `onUpdate`.

```js
gsap.to(".card", { y: 0, opacity: 1, duration: 0.6, stagger: 0.12, ease: "power2.out" });
```

## Eases (pick intentionally)

- `power1/2/3/4.out` — natural deceleration (default go-to is `power2.out` / `power3.out`).
- `back.out(1.7)` — slight overshoot (good for pop-in).
- `elastic.out(1, 0.3)` — springy. `bounce.out` — bouncy.
- `none` — linear (use for parallax/scrub).
- `expo.out` — dramatic ease for hero reveals.

## Responsive + reduced motion (always do this)

```js
let mm = gsap.matchMedia();
mm.add("(min-width: 768px)", () => {
  gsap.from(".hero h1", { y: 60, opacity: 0, duration: 1, ease: "expo.out" });
});
mm.add("(prefers-reduced-motion: reduce)", () => {
  gsap.set(".hero h1", { clearProps: "all" }); // no motion for these users
});
```

## Tips

- Set the start state in CSS or with `gsap.set()` to avoid a flash of unstyled
  content (FOUC) before JS runs — e.g. `.reveal{opacity:0}` then animate to 1.
- `gsap.utils.toArray(sel)` to loop elements. `clearProps: "all"` removes inline
  styles GSAP added.
- Keep one timeline per section rather than many loose tweens (see `gsap-timeline`).
