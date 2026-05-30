---
name: Skill Creator
description: How to author a new Webchanges skill — the markdown + optional macro format, where bundled vs custom skills live, and how they distribute to every site via the plugin update system.
version: 1.0.0
tags: meta, authoring, skills
---

# Skill Creator

A **skill** is a reusable specialist playbook the agent loads on demand. Use one
when a task recurs (e.g. "add GSAP animations", "build a pricing page in Bricks",
"migrate ACF fields to Pods"). A skill is mostly *instructions*; it may also
carry a *macro* that runs a sequence of abilities.

## Two kinds of skill

- **Bundled** (recommended for anything reused across sites): markdown files in
  the plugin repo under `skills/<slug>/skill.md`. Because the plugin
  self-updates from the private GitHub repo, a bundled skill authored once ships
  to **every connected site** on the next release — no per-site work.
- **Custom** (one site only): created live with `webchanges/skills-save`, stored
  in that site's database. Good for site-specific playbooks. A custom skill with
  the same slug as a bundled one shadows it on that site.

## File format (bundled)

```
skills/
  my-skill/
    skill.md          # required: frontmatter + instructions
    macro.json        # optional: ordered runnable steps
    assets/           # optional: files a write_asset step installs
      something.php
```

`skill.md` starts with frontmatter, then markdown:

```markdown
---
name: My Skill
description: One sentence shown in skills-list and the discover instructions.
version: 1.0.0
tags: comma, separated, keywords
---

# My Skill

Step-by-step instructions the agent follows. Reference real webchanges
abilities by name. Keep it concrete and ordered.
```

Write a tight, action-first `description` — it's what the agent sees in the
session instructions and uses to decide whether to load the skill.

## Macros (optional — makes a skill "runnable")

`macro.json` is an array of steps run in order by `webchanges/skills-run`. Two
step types:

```json
[
  { "id": "make_page", "ability": "webchanges/create-post",
    "params": { "post_type": "page", "title": "{{input.title}}", "status": "draft" } },
  { "id": "set_body", "ability": "webchanges/bricks-set-elements",
    "params": { "post_id": "{{steps.make_page.post_id}}", "elements": [] } },
  { "id": "loader", "action": "write_asset",
    "asset": "something.php", "dest": "wp-content/mu-plugins/something.php" }
]
```

- `ability` steps call any `webchanges/*` ability with `params`.
- `action: "write_asset"` copies `assets/<asset>` into a path under the site
  root (bundled skills only).
- **Placeholders:** `{{input.X}}` pulls from the `inputs` passed to skills-run;
  `{{steps.ID.field}}` pulls a field from an earlier step's output. A
  whole-string placeholder keeps the original type (int/array); embedded ones
  stringify.
- Runs stop at the first failing step and report what ran.

## Checklist for a good skill

1. Slug is kebab-case and unique. Folder name = slug.
2. `description` is one sentence, action-first, scannable.
3. Instructions reference real abilities (verify names with `discover-abilities`).
4. If there's a macro, keep steps idempotent where possible and validate inputs
   in the instructions ("expects input.title").
5. State guardrails: accessibility, caching, how to undo.
6. To ship it everywhere: add the folder under `/skills`, then cut a release
   (`bin/release.sh`). To test on one site first: `webchanges/skills-save` a
   custom copy, iterate, then promote it into the repo.
