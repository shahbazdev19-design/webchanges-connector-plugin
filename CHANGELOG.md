# Changelog

## 0.6.2 - 2026-06-20

- Add media-compress: in-place image recompression for web (Ahrefs oversized fixes) — preserves format/filename/URL, EXIF+strip, quality+downscale, PNG quantize/flag, -scaled-safe, verify

## 0.6.1 - 2026-06-20

- Add Yoast Search Appearance settings abilities (seo-yoast-get-settings / seo-yoast-update-settings)

## 0.6.0 - 2026-06-11

- Consolidated build + complete Formidable (create/edit, settings, notifications with conditional routing); supersedes the 0.5.0 manual build

## 0.4.2 - 2026-06-07

- Add webchanges/verify-change ability (powers the 'change landed' confirmation)

## 0.5.0 - 2026-06-01

Security hardening release (pre-public-release audit).

- Path traversal fixed in the filesystem abilities (realpath confinement; `..` and symlink escapes blocked).
- SSRF guards on all server-side fetchers (media sideload, stock import, image-gen source URL); blocks localhost / private / link-local (incl. 169.254.169.254).
- Unsplash download-trigger pinned to api.unsplash.com so the API key can't be exfiltrated via a caller-supplied URL.
- API keys (OpenAI/Gemini/Replicate, Pexels/Unsplash/Pixabay) and the updater token are now encrypted at rest (AES-256-GCM keyed off WP salts). Transparent migration: existing plaintext keys keep working and re-encrypt on next save.
- MCP transport now requires `manage_options` (was the default `read` capability).
- User role assignment limited to roles the current user may grant (no minting administrators).
- Bricks `code` / Elementor `html`+`shortcode` elements gated by the `unfiltered_html` capability when written via the abilities.
- **High-risk abilities (execute-php, filesystem write/edit/delete/enable/disable) are now OFF by default** and must be opted in from the Abilities Manager. Existing active installs are grandfathered so auto-apply keeps working; set the `WEBCHANGES_CONNECTOR_ENABLE_DANGEROUS` constant to force-enable.
- Set real Plugin URI.

## 0.4.1 - 2026-06-01

- Active-install telemetry: activate/heartbeat/deactivate ping to backend

## 0.4.0 - 2026-05-31

- Native-elements-first Bricks import (native layout controls + Icon elements) + bricks-update-global-css and css_target

## 0.3.2 - 2026-05-26

- Fix bricks-import-html: inline tags (span/b/i/strong/em/...) and unknown tags now render via basic-text instead of the Bricks code element (which escaped them to literal text). Replace-mode now replaces page Custom CSS instead of appending.

## 0.3.1 - 2026-05-26

- bricks-import-html: inline <svg> now imports as a data-URI image (renders without Bricks code execution).

## 0.3.0 - 2026-05-26

- Bricks: bricks-import-html (HTML/CSS -> native Bricks elements, preserving classes/ids/inline-style/data-* attributes, with <style> saved as page Custom CSS).
- Bricks: bricks-import-json (paste Bricks copied-elements / template JSON onto a page; all ids regenerated; optional globalClasses merge).

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
