# Webchanges Connector

Connects a WordPress site to the Webchanges SaaS (or any MCP client) so AI
agents can control posts, pages, blocks, media, SEO, taxonomies, menus, users,
builders (Bricks / Elementor), ACF, forms, AI image generation, and stock
photos — plus the filesystem and PHP execution.

## Self-updating across all sites

This plugin updates itself from this **private GitHub repo** via
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).
When a new version is released here, every site running the plugin shows the
native **Update** button on its Plugins screen — no manual file copying.

### Cutting a release (maintainer)

```bash
bin/release.sh 0.2.0 "What changed in one line"
```

That bumps the version (header + constant), updates `CHANGELOG.md`, commits,
tags `v0.2.0`, and pushes. The **Release** GitHub Action then builds
`webchanges-connector.zip` and publishes the GitHub Release. Sites pick it up
within ~12h, or instantly via **Plugins → Check for updates**.

> The tag version **must** match the `Version:` header — `bin/release.sh` keeps
> them in sync, and the Action fails the build if they ever drift.

### Per-site setup (one time)

Because the repo is **private**, each site needs a read-only token so the
updater can read Releases. Either:

- Define in `wp-config.php`:
  ```php
  define('WEBCHANGES_CONNECTOR_GH_TOKEN', 'github_pat_...');
  ```
- Or store the `webchanges_connector_gh_token` option (the connector sets this
  automatically when provisioning a site over MCP).

The token should be a **fine-grained PAT** with **read-only "Contents"**
permission on this repo only. It never ships in the plugin ZIP.

The repo URL is set by the `WEBCHANGES_CONNECTOR_UPDATE_REPO` constant in
`webchanges-connector.php` (override in `wp-config.php` if you fork).

## Layout

```
webchanges-connector.php        Main file: constants, hooks, ability require list
includes/
  helpers.php                   Categories + shared helpers
  *-helpers.php                 bricks / elementor / forms / image-gen / stock helpers
  updater.php                   Self-update client (PUC + GitHub)
  admin-page.php                wp-admin settings UI
  abilities/<category>/*.php    One file per ability
vendor/
  plugin-update-checker/        Bundled PUC library
  (jetpack autoloader, mcp-adapter)  MCP runtime
.github/workflows/release.yml   Builds ZIP + publishes Release on v* tag
bin/release.sh                  Version bump + tag + push helper
```

## License

AGPL-3.0-or-later. Bundled Plugin Update Checker is MIT (see
`vendor/plugin-update-checker/license.txt`).
