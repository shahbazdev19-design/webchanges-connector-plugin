---
name: GSAP Utils
description: gsap.utils helpers — toArray, mapRange, clamp, interpolate, random, snap, wrap, distribute, pipe. Use for math/selection helpers inside animations (e.g. mouse-follow, value remapping, looping indexes).
version: 1.0.0
tags: gsap, utils, helpers, math
---

# GSAP Utils

`gsap.utils.*` are small pure helpers handy when scripting animations.

```js
const u = gsap.utils;

u.toArray(".card");              // selector/NodeList -> real Array (for .forEach/.map)
u.clamp(0, 100, value);          // constrain to range
u.mapRange(0, 1, 0, 100, 0.5);   // remap 0.5 from [0,1] to [0,100] -> 50
u.interpolate("#f00", "#00f", 0.5); // -> midway colour; also numbers/arrays/objects
u.random(0, 100);                // random in range; u.random([1,2,3]) picks one; u.random(0,100,5) snaps to 5
u.snap(10, 47);                  // -> 50 (snap to increment); u.snap([0,50,100], 30) -> 50
u.wrap(0, 3, 5);                 // -> 2 (wrap index into range); great for carousels
u.wrapYoyo(0, 3, 4);             // ping-pong wrap
u.distribute({ base: 0, amount: 1, from: "center" }); // stagger-like distribution
```

## Patterns

**Mouse-follow with remap + clamp:**
```js
window.addEventListener("mousemove", (e) => {
  const x = gsap.utils.mapRange(0, window.innerWidth, -20, 20, e.clientX);
  gsap.to(".blob", { x, duration: 0.6, ease: "power3.out" });
});
```

**Random, varied entrance:**
```js
gsap.utils.toArray(".tile").forEach((el) => {
  gsap.from(el, { y: gsap.utils.random(20, 80), opacity: 0, duration: gsap.utils.random(0.5, 1) });
});
```

**Pipe (compose helpers):**
```js
const transform = gsap.utils.pipe(gsap.utils.clamp(0, 100), gsap.utils.mapRange(0, 100, 0, 1));
```

These are framework-agnostic and safe to use anywhere GSAP is loaded.
