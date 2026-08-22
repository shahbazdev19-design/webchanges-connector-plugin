=== Webchanges Connector ===
Contributors: shahbazdev
Tags: mcp, ai, automation, content, seo
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.8.1
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Connect WordPress to MCP-compatible AI clients so agents can manage content, media, SEO, forms, and site settings.

== Description ==

Webchanges Connector exposes WordPress to Model Context Protocol (MCP) clients so an AI agent can manage the site through a gated, auditable set of "abilities" built on the WordPress Abilities API.

Capabilities include:

* Posts, pages, blocks (Gutenberg, Bricks, Elementor) and media.
* SEO (Rank Math / Yoast) — meta, redirects, and Yoast Search Appearance settings.
* Forms (Formidable, Fluent, WPForms, Forminator) — create/edit forms, settings, notifications, and list submissions.
* Media tools — sideload, replace, alt text, and in-place image recompression.
* Taxonomies, menus, users, WooCommerce, ACF, and site settings.

Only three meta tools are exposed over MCP (discover-abilities, get-ability-info, execute-ability); every individual ability is gated per-install from the Abilities Manager. High-risk abilities (arbitrary PHP execution, filesystem writes) are OFF by default and must be explicitly opted in. The MCP transport requires the `manage_options` capability.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/webchanges-connector` or install the ZIP from the Plugins screen.
2. Activate it.
3. Open the Webchanges Connector admin page, generate a connection, and add the MCP endpoint to your AI client.
4. Enable the specific abilities you want from the Abilities Manager.

Updates are delivered from the plugin's GitHub Releases and appear as the native "Update available" button on the Plugins screen.

== Changelog ==

= 0.6.2 =
* Add media-compress: in-place image recompression for the web (Ahrefs oversized fixes) — preserves format/filename/URL, fixes EXIF + strips metadata, quality + gradual downscale, PNG quantize/flag, `-scaled`-safe, verifies output before overwrite.

= 0.6.1 =
* Add Yoast Search Appearance settings abilities (seo-yoast-get-settings / seo-yoast-update-settings).

= 0.6.0 =
* Consolidated build + complete Formidable support (create/edit, settings, notifications with conditional routing); supersedes the 0.5.0 manual build.

= 0.5.0 =
* Security hardening release: path-traversal confinement in filesystem abilities; SSRF guards on all server-side fetchers; API keys and the updater token encrypted at rest; MCP transport requires `manage_options`; high-risk abilities OFF by default (opt-in).

= 0.4.2 =
* Add webchanges/verify-change ability (powers the "change landed" confirmation).

= 0.4.0 =
* Native-first page importer (Bricks native elements + global CSS) and owner telemetry/dashboard.
