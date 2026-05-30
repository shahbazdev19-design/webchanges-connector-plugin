---
name: GSAP Timeline
description: Sequence and choreograph animations with gsap.timeline() — position parameters, labels, defaults, nesting, and timeline control (play/pause/reverse/seek). Use when multiple animations must run in a coordinated order.
version: 1.0.0
tags: gsap, timeline, sequencing, animation
---

# GSAP Timeline

A timeline chains tweens so you control order and overlap without juggling
delays. Create with `gsap.timeline(vars)`.

```js
let tl = gsap.timeline({ defaults: { ease: "power3.out", duration: 0.8 } });
tl.from(".hero h1", { y: 60, opacity: 0 })
  .from(".hero p",  { y: 40, opacity: 0 }, "-=0.5")   // start 0.5s BEFORE previous ends
  .from(".hero .btn", { scale: 0.8, opacity: 0 }, "<") // start WITH previous
  .from(".hero img", { opacity: 0 }, 0.2);             // absolute time 0.2s
```

## Position parameter (the key feature)

The 3rd arg of each `.to/.from` controls when it starts:

- `"+=0.3"` — 0.3s after the previous tween ends (gap).
- `"-=0.3"` — 0.3s before the previous ends (overlap).
- `"<"` — at the START of the previous tween. `"<0.2"` — 0.2s after that start.
- `">"` — at the END of the previous tween (default).
- `1.5` — absolute time on the timeline.
- `"label"` — at a named label.

## Labels

```js
tl.addLabel("reveal")
  .from(".a", { opacity: 0 }, "reveal")
  .from(".b", { opacity: 0 }, "reveal+=0.2");
```

## Defaults + nesting

`defaults` apply to every child tween (DRY). Nest timelines for modular
sections: `master.add(buildHeroTL(), 0).add(buildFeaturesTL(), ">");`

## Control

```js
tl.pause(); tl.play(); tl.reverse(); tl.restart();
tl.timeScale(2);     // 2x speed
tl.seek("reveal");   // jump to a label
tl.progress(0.5);    // scrub to 50%
```

Attach a timeline to scroll by passing `scrollTrigger` in the timeline's vars
(see `gsap-scrolltrigger`). Pair with `gsap-core` eases and
`gsap.matchMedia()` for responsive/reduced-motion variants.
