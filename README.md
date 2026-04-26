# DefynWP

A ManageWP-style multi-site WordPress management platform.

## Project layout

| Path | Contents |
|---|---|
| `docs/` | Specs and implementation plans |
| `packages/dashboard-plugin/` | The WordPress plugin powering the dashboard backend |
| `packages/connector-plugin/` | (added in F4) The plugin installed on each managed WP site |
| `packages/web/` | (added in F3) The React SPA (`app.defyn.dev`) |
| `app/`, `conf/`, `logs/` | Local by Flywheel runtime — gitignored |

## Local development

1. Install [Local by Flywheel](https://localwp.com/)
2. Create a Local site at this directory (PHP 8.2, nginx, MySQL 8.0) — see `docs/superpowers/plans/2026-04-25-defyn-foundation-f1-scaffolding.md` Task 1
3. Symlink the plugin into the WP install (Task 4 of F1 plan)

## Status

F1 (scaffolding) in progress. See `docs/superpowers/plans/`.
