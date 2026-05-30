# Changelog

## 0.2.5 - 2026-05-26

- Bundle full GSAP skill set: gsap-core, gsap-scrolltrigger, gsap-timeline, gsap-utils, gsap-plugins, gsap-performance (in addition to gsap-animations). Enabled by default.

## 0.2.4 - 2026-05-26

- New Images submenu (dark/glass): AI image generation + stock photo sources at a glance.
- Abilities Manager: enable/disable abilities per site (all or selected); disabled abilities are not registered, so the agent can't use them. Meta abilities always on.
- Skills can be enabled/disabled per site (e.g. turn GSAP off on a given site).

## 0.2.3 - 2026-05-26

- Skills admin page restyled to the dark/glass theme.
- New Abilities browser submenu (dark/glass) with category grouping + live filter.
- Shared admin theme + in-page nav (Settings / Abilities / Skills).

## 0.2.2 - 2026-05-26

- Add Webchanges -> Skills admin page: list bundled + custom skills, add a custom skill, upload a .md skill, delete custom skills.

## 0.2.1 - 2026-05-26

- Fix release build excluding skill .md instruction files (only root README/CHANGELOG are dropped now).

## 0.2.0 - 2026-05-26

- Add Skills subsystem: git-synced bundled skills + per-site custom skills, with executable macros (skills-list / skills-get / skills-save / skills-delete / skills-run).
- Bundled skills: gsap-animations (declarative scroll/entrance animations via GSAP + ScrollTrigger), skill-creator.
- Skills surface in discover-abilities instructions.

## 0.1.1 - 2026-05-26

- Verify end-to-end self-update pipeline (no functional change).

## 0.1.0 - 2026-05-26

- Initial tracked release.
- Core: filesystem, code execution, posts, Gutenberg blocks, media, taxonomies, menus, users, plugins/themes, customizer.
- Builders: Bricks element CRUD, Elementor element CRUD (gated by active builder).
- Forms: WPForms / Gravity / Formidable / Forminator / Fluent / CF7 / Ninja detection, list, create, submissions.
- SEO: RankMath meta + redirects.
- ACF: read field groups, read/write values.
- AI image generation: OpenAI / Gemini / Replicate / Pollinations.
- Stock photos: Pexels / Unsplash / Pixabay search + import, auto-fallback for AI image generation.
- Media extras: bulk alt-text update, AltText.AI bulk generate, replace-file, edit-image, find-unused.
- Self-update via private GitHub repo + Plugin Update Checker.
