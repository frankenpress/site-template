# CLAUDE.md — site-template

Guidance for Claude Code when working in this repo OR in a fork of it.

## What this repo is

A **GitHub template repo** for new WordPress sites running on the
FrankenPress stack. Bedrock-style layout. Builds an immutable site
image you publish to your registry; deploys via the
[`charts`](https://github.com/frankenpress/charts) Helm chart.

Click "Use this template" on GitHub to fork. Most of this guidance
applies equally to the template and to forked sites.

Public docs: **<https://docs.frankenpress.com/components/site-template>**

## File layout (Bedrock)

- `composer.json` — slim deps. `roots/wordpress` (no-content WP core), `roots/wp-config`, `roots/bedrock-autoloader` (loads composer-installed mu-plugins), `roots/bedrock-disallow-indexing`, `vlucas/phpdotenv`, `oscarotero/env`, `frankenpress/mu-plugin`, `wpackagist-theme/twentytwentyfive` as a default theme. **No WooCommerce, no opinionated plugins.** `require-dev` includes `wpackagist-plugin/create-block-theme` for the Phase 3 designer flow (`wp fp snapshot` works without it; the plugin is only needed when designers want to save block-theme customizations back to theme files instead of shipping them as DB rows). `--no-dev` builds keep the plugin out of the production image.
- `config/application.php` — env-driven config. `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` / `DISALLOW_INDIRECT_FILE_MODS` are **gated on `KUBERNETES_SERVICE_HOST`** — locked in-cluster, relaxed out-of-cluster so local dev can install block plugins / evaluation themes during design work. `DISALLOW_INDIRECT_FILE_MODS` (WP 6.4+) closes the indirect write paths the other two miss (language packs, font installs, Site Health helper writes). `AUTOMATIC_UPDATER_DISABLED` / `WP_AUTO_UPDATE_CORE` / `WP_AUTO_UPDATE_PLUGINS` / `WP_AUTO_UPDATE_THEMES` in `production.php` remain hard-coded. Required env at boot includes WP_HOME/WP_SITEURL, the 4 DB vars, and all 8 auth keys + salts — a missing secret fails loud, never silently degrades session crypto.
- `config/environments/{development,staging,production}.php` — per-env overrides (debug flags, auto-update disabled).
- `web/index.php` / `web/wp-config.php` — Bedrock front-controller + thin loader.
- `web/wp/` — composer-installed WP core (gitignored).
- `web/app/{plugins,themes,mu-plugins}/` — wp-content. Composer-managed; `.gitkeep` is the only committed file by default.
- `web/app/mu-plugins/00-stack.php` — the **only** file we commit under `mu-plugins/`. Boots `roots/bedrock-autoloader` so composer-installed mu-plugins (mu-plugin, bedrock-disallow-indexing) actually load. Don't edit unless you know what you're doing.
- `Dockerfile` — multi-stage: composer install → `FROM ghcr.io/frankenpress/runtime:php8.3` (overridable for local dev). **Removes the runtime-baked `mu-plugins/fp/`** so the composer-installed canonical copy is the only one that loads.
- `docker-compose.yml` — full local stack: site + MariaDB 11 + Redis 7 + MinIO + minio-init.
- `.env.example` — every platform env var documented with local-dev defaults.
- `.github/workflows/{build,lint}.yml` — PHPCS + composer audit on PR; tag-triggered build → push to GHCR.
- `Makefile` — `setup / build / up / down / logs / shell / wp / lint / ci / reset / clean` targets.

## Conventions

- **Lockdown is gated on `KUBERNETES_SERVICE_HOST`.** In-cluster, `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` / `DISALLOW_INDIRECT_FILE_MODS` are `true` so admin-side installs (which would land on ephemeral pod disk and replicate inconsistently) hard-fail. Out-of-cluster (docker-compose, bare local) all three are `false` so developers can install block plugins / evaluation themes during design work and promote the result into the image + DB via `wp fp snapshot`. The kubelet injects `KUBERNETES_SERVICE_HOST` on every pod — prod can't accidentally land in the relaxed mode. Narrow per-Pod opt-out via `FP_ALLOW_FILE_MODS=1` (the chart sets this on the install Job container only) flips all three back off so `wp fp apply` can transiently install WP-Importer.
- **The site image is immutable.** All code (WP core + plugins + themes + custom code) is baked at build time. Releases happen via `git tag vX.Y.Z` → CI builds → `helm upgrade --set image.tag=vX.Y.Z`.
- **Bedrock layout is the contract.** `web/wp` for core, `web/app` for content, `config/` for env-driven settings. Don't flatten or rearrange.
- **`humanmade/s3-uploads` is a transitive dep** of `frankenpress/mu-plugin`. Don't `composer require` it directly — that risks version drift.

## Common edits

- **Add a plugin:** `composer require wpackagist-plugin/<slug>` (composer config wires up wpackagist as a repo). After image rebuild + deploy, activate once with `wp plugin activate <slug>` against the running pod — activation is DB state, not image state, so it persists across releases. Full step-by-step at <https://docs.frankenpress.com/customizing#add-a-plugin>.
- **Remove a plugin:** `wp plugin deactivate <slug>` against the live cluster, then `composer remove wpackagist-plugin/<slug>` on a branch. Deactivate-before-remove avoids a noisy admin warning every page load. Full flow at <https://docs.frankenpress.com/customizing#remove-a-plugin>.
- **Add a theme:** `composer require wpackagist-theme/<slug>`. After deploy, `wp theme activate <slug>` (one-time, DB state).
- **Remove a theme:** `wp theme activate <other>` first (you can't remove the active theme), then `composer remove wpackagist-theme/<slug>` on a branch.
- **Add custom code:** drop a directory under the right `web/app/*` subtree and commit it. The `.gitignore` ignores composer-installed content but unhides committed paths.
- **Bump WP core:** edit the `roots/wordpress` constraint in `composer.json` and `composer update roots/wordpress`.
- **Bump runtime base:** edit the `ARG FP_RUNTIME_VERSION` line in `Dockerfile`, or override at build via `--build-arg FP_RUNTIME_VERSION=<tag>`.

## Don'ts

- **Don't commit `web/wp/`, `vendor/`, `.env`, `node_modules/`, or `web/app/uploads/`.** The `.gitignore` already covers these.
- **Don't put real secrets in `.env`** — local dev keeps the `dev-key` / `dev-salt` defaults in `docker-compose.yml`; production injects real keys/salts via Helm values + Secrets.
- **Don't edit `web/wp-config.php`** to add config — it's a thin loader. All config lives in `config/application.php` and `config/environments/*.php`.
- **Don't reverse the lockdown gate.** `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` / `DISALLOW_INDIRECT_FILE_MODS` *must* track `KUBERNETES_SERVICE_HOST`: locked when set, relaxed when absent. Hard-coding any of them back to `true` breaks local installer workflows; hard-coding any to `false` lets a pod silently write to ephemeral disk and lose state on the next image roll. The CI smoke test in `build.yml` exercises all three cases (in-cluster, out-of-cluster, install-Job opt-out) — don't disable it.
- **Don't bake mu-plugin config into `application.php`.** It reads `FP_S3_*` and `FP_SOUIN_*` env vars itself; defining those constants directly may double-define.
- **Don't bypass `roots/bedrock-autoloader`** by manually requiring mu-plugin files — the loader handles discovery + caching.
- **Don't add the Mintlify "starter kit" copy** if you find yourself writing READMEs/docs for a fork.

## Running things

Canonical onboarding (fresh clone + recovery from `down -v`):
- `fp init` — bootstrap (composer install + `.env` scaffold) + stack up + WP install + apply latest snapshot. One command. Idempotent on re-runs. Requires `fp` ≥ v0.6.0 — install via `brew install frankenpress/tap/fp`.

Lower-level Make targets (still supported; equivalent to what `fp init` orchestrates):
- `make setup` — first-time bootstrap (composer install, `.env` from `.env.example`)
- `make up` — start the local stack (site + db + redis + minio)
- `make down` — stop and drop volumes
- `make wp ARGS="<wp-cli args>"` — run wp-cli in the site container (the recipe injects `--allow-root --path=/app/web/wp`)
- `make lint` — phpcs against `config/` and any custom mu-plugins
- `make logs` — tail site logs
- `make shell` — bash into the site container

## When you ship a release

```bash
git tag v1.2.0
git push origin v1.2.0

# After the tag-build workflow goes green:
gh release create v1.2.0 --generate-notes
```

CI builds + pushes `ghcr.io/<your-org>/<your-site>:v1.2.0` to your GHCR.
The `gh release create` step publishes the GitHub Release page with
auto-generated notes from the PR titles since the previous tag — don't
skip it; the image push succeeds without it but the Releases feed goes
stale, and that's been the most common drift point across this
workspace.

Then in your cluster:

```bash
helm upgrade mysite oci://ghcr.io/frankenpress/charts/site \
  --namespace mysite --reuse-values \
  --set image.tag=v1.2.0
```

## Companion repos

| Repo | Purpose |
|---|---|
| [`runtime`](https://github.com/frankenpress/runtime) | Base container image (this Dockerfile's FROM) |
| [`mu-plugin`](https://github.com/frankenpress/mu-plugin) | Must-use plugin (composer-installed by this repo) |
| [`site-template`](https://github.com/frankenpress/site-template) (this repo) | GitHub template |
| [`charts`](https://github.com/frankenpress/charts) | Helm chart for k8s deployment |
| [`docs`](https://github.com/frankenpress/docs) | Mintlify docs site |
