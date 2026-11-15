# AGENTS.md — Metamanager Plugin

## MANDATORY WORKFLOW — ZERO EXCEPTIONS

**You are FORBIDDEN from doing ANY of the following:**
- Checking out `test` or `main` locally
- Running `git checkout test`, `git checkout main`, or `git switch test/main`
- Running `git merge`, `git rebase`, `git push`, or `git reset` on `test` or `main`
- Running `git branch -d`, `git branch -D`, `git push --delete` on `test` or `main`
- Creating files, editing files, or running any `git` command while on `test` or `main`
- Running `gh pr merge` to resolve conflicts (close the PR instead)

**You MUST follow this exact process for ALL changes:**

1. **ALL work happens on `dev`**: `git checkout dev`, make changes, commit, push to dev
2. **Promote via workflow_dispatch only**: Trigger `promote-to-test.yml` — merges dev→test, builds, deploys to apt
3. **Then promote test→main**: Trigger `promote-to-main.yml` — merges test→main, tags, releases, deploys to apt
4. **If a merge has conflicts**: CLOSE the workflow, do NOT try to resolve them by checking out test/main
5. **If you need to update test with main's changes**: Create a new empty commit on dev that triggers CI, or ask the user to resolve

**The ONLY branch you are allowed to checkout, edit, commit, or push is `dev`.**

## CI Flow

```
dev  ──  all development, direct push; CI runs checks + auto-version bump
    │  workflow_dispatch: promote-to-test.yml
    ▼
test  ──  build zip + deploy to apt server TEST channel (/metamanager-test/)
    │  workflow_dispatch: promote-to-main.yml
    ▼
main  ──  tag + GitHub release + deploy to apt server PRODUCTION channel (/metamanager/)
```

- On every dev push: CI first auto-bumps `MM_VERSION`, then runs PHP lint, PHPStan, ShellCheck, integration tests, and builds artifact
- The actor check (`github.actor != 'github-actions[bot]'`) prevents infinite loops — version bump commits don't re-trigger CI
- Promotion workflows merge directly via git (no PRs), build, and deploy to apt server
- **Test channel**: `/var/www/html/metamanager-test/` — safe for testing, does not affect production WordPress updates
- **Production channel**: `/var/www/html/metamanager/` — WordPress polls this for updates via `MM_Updater`
- WordPress detects the update via `MM_Updater` making direct HTTP requests to `metadata.json` (no caching)

## Deployment Rules

**NEVER SCP or copy files directly to production servers unless explicitly testing a fix.**

All software must move to production through native update systems:

- **Plugin test channel**: Push to `dev` → trigger `promote-to-test.yml` → merges dev→test, builds zip → deploys to `metamanager-test/` on apt server → WordPress detects update via `MM_Updater`
- **Plugin production channel**: Push to `dev` → trigger `promote-to-main.yml` → merges test→main, tags, releases → deploys to `metamanager/` on apt server → WordPress detects update via `MM_Updater`
- **Daemon test channel**: Push to `dev` → trigger `promote-to-test.yml` → merges dev→test, builds `.deb` → deploys to `dists/test` on apt server → install on test site via `apt-get install -t test`
- **Daemon production channel**: Push to `dev` → trigger `promote-to-main.yml` → merges test→main, tags, releases → deploys to `dists/stable` on apt server → install on production site via `apt-get upgrade` or `apt-get install -t stable`

The only exception is temporary testing during active development sessions, where files may be SCP'd for immediate verification. After testing, the fix must go through the proper pipeline before being considered deployed.

## Daemon Version Detection

The plugin's `MM_Daemon_Updater` reads the VERSION file for dashboard display only. Daemon updates are handled by OS apt (root context), not the plugin.

### Architecture

- **Plugin PHP** (`MM_Daemon_Updater`): Reads `/usr/local/lib/metamanager/VERSION` for dashboard display. Does not trigger updates.

## Repos

- Plugin repo: `richardkentgates/metamanager-plugin`
- Server repo: `richardkentgates/metamanager`
- Apt server: `34.136.87.92` (DNS: `apt.richardkentgates.com`)
- Production: `34.10.253.160` (Debian 13 trixie, WordPress at `/srv/www/wordpress/`)

## Cross-Repo Scope

**This repo owns:**
- WordPress plugin PHP code (SEO, schema, OG tags, sitemaps, cron tracking, dashboard widget)
- Job queue contract: PHP writes JSON job files to `wp-content/metamanager-jobs/`
- Status JSON (`metamanager-status.json`) for dashboard widget
- `MM_Updater` — WordPress auto-update from apt server `metadata.json`
- `MM_Daemon_Updater` — reads VERSION for display only (does NOT trigger updates)

**Daemon repo (`metamanager`) owns:**
- Shell daemons that process job files (compress, metadata embed)
- `.deb` package build and apt server deployment
- systemd service units
- VERSION file and `debian/changelog`

**GCM CLI repo (`gcm`) owns:**
- Server provisioning (`gcm install`, `gcm adopt`)
- Backup/restore operations
- Status JSON collection and dashboard widget in MU plugin
- Operation gating (MetaManager idle check before backup/upgrade)

**Contract point:** `JOB_QUEUE_SPEC.md` defines the JSON format between PHP and Bash. Both repos must stay in sync.

## Apt Server Channels

- **Test channel (plugin)**: `metamanager-test/` — WordPress detects update via `MM_Updater`
- **Production channel (plugin)**: `metamanager/` — WordPress detects update via `MM_Updater`
- **Test channel (daemons)**: `dists/test` — install via `apt-get install -t test`
- **Production channel (daemons)**: `dists/stable` — install via `apt-get install -t stable` or `apt-get upgrade`

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotion = workflow_dispatch triggers direct git merge (no PRs)
- Shell scripts/daemons update via apt; plugin updates via WordPress native update
- Daemon updates are handled by OS apt (gcm-maintenance timer), not the plugin.
- PHP 8.4 for WP-CLI (`php8.4 /usr/local/bin/wp --path=/srv/www/wordpress`)
- CI auto-bumps `MM_VERSION` on every dev push — do not manually edit version numbers
- **Test channel**: Used for development verification before production deployment
- **Production channel**: Used by production site, requires explicit workflow_dispatch promotion

## Rate limiting — signed-in users are never restricted (2026-11-14)

The programmable rate-limiting pattern (logged-in users / admin dashboard
never rate-limited; visitors only) lives in `includes/class-mm-admin.php`
around lines 267, 1303–1308, and 1443: `is_user_logged_in()` gates the
REST access restrictions — "logged-in WordPress users are never
restricted" (see the settings copy at line 267). This is the reference
implementation.

**It is in the wrong repo.** This is core operations behavior (auth/
visitor discrimination) and belongs in the mu plugin (gap-creek-media),
not a MetaManager feature. MetaManager currently holds it because it was
built here first. Do not extend it here — move/consolidate it into the
mu plugin. The mu plugin is the operations layer (always on, every site);
MetaManager is a product that should only register abilities and defer to
the mu plugin for auth, rate limits, and access control.

## Rate limiting — final design (2026-11-14)

Rate limiting does NOT belong at the WordPress level at all (not
MetaManager, not the mu plugin). It is part of the **OS security
footprint** — the GCM ModSecurity custom rules
(`gcm/modules/configs/gcm-modsecurity.conf`):
- Rules 1001/1002: 200 req/60s per IP (the actual rate limit).
- Rule 1003: exempts **signed-in users** via the `wordpress_logged_in`
  cookie (the session/cookie mechanism).
- Rule 1006: exempts the **admin dashboard by URL** — `^/(wp-admin/|wp-login\.php)`
  per the WordPress codex (the easiest mechanism).
- Rules 1004/1005: Basic auth + monitoring endpoints exempt.

The `is_user_logged_in()` code in `class-mm-admin.php` is **programmatic
access control** (REST auth gating) — a different creature from rate
limiting, not the OS-level limiter. The Coworker `rate_check` was
WordPress-level rate limiting and has been **removed** as split brain —
the OS layer owns rate limiting.

Consolidation target: rate limiting lives only in
`gcm/modules/configs/gcm-modsecurity.conf`. Anything else is split brain.
