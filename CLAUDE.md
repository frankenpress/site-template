# CLAUDE.md — fp-site-template

Guidance for Claude Code when working in this repo OR in a fork of it.

## What this repo is

A **GitHub template repo** for new WordPress sites running on the
FrankenPress stack. Bedrock-style layout. Builds an immutable site
image you publish to your registry; deploys via the
[`charts`](https://github.com/frankenpress/charts) Helm chart.

Click "Use this template" on GitHub to fork. Most of this guidance
applies equally to the template and to forked sites.

Public docs: **<https://docs.frankenpress.com/components/fp-site-template>**

## File layout (Bedrock)

- `composer.json` — slim deps. `roots/wordpress` (no-content WP core), `roots/wp-config`, `roots/bedrock-autoloader` (loads composer-installed mu-plugins), `roots/bedrock-disallow-indexing`, `vlucas/phpdotenv`, `oscarotero/env`, `eightoeight/fp-mu-plugin`, `wpackagist-theme/twentytwentyfive` as a default theme. **No WooCommerce, no opinionated plugins.**
- `config/application.php` — env-driven config. The four lockdown constants (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, plus `AUTOMATIC_UPDATER_DISABLED`/`WP_AUTO_UPDATE_CORE` in `production.php`) are **hard-coded**.
- `config/environments/{development,staging,production}.php` — per-env overrides (debug flags, auto-update disabled).
- `web/index.php` / `web/wp-config.php` — Bedrock front-controller + thin loader.
- `web/wp/` — composer-installed WP core (gitignored).
- `web/app/{plugins,themes,mu-plugins}/` — wp-content. Composer-managed; `.gitkeep` is the only committed file by default.
- `web/app/mu-plugins/00-fp-stack.php` — the **only** file we commit under `mu-plugins/`. Boots `roots/bedrock-autoloader` so composer-installed mu-plugins (fp-mu-plugin, bedrock-disallow-indexing) actually load. Don't edit unless you know what you're doing.
- `Dockerfile` — multi-stage: composer install → `FROM ghcr.io/eightoeight/fp-runtime:php8.3` (overridable for local dev). **Removes the runtime-baked `mu-plugins/fp/`** so the composer-installed canonical copy is the only one that loads.
- `docker-compose.yml` — full local stack: site + MariaDB 11 + Redis 7 + MinIO + minio-init.
- `.env.example` — every platform env var documented with local-dev defaults.
- `.github/workflows/{build,lint}.yml` — PHPCS + composer audit on PR; tag-triggered build → push to GHCR.
- `Makefile` — `setup / build / up / down / logs / shell / wp / lint / ci / reset / clean` targets.

## Conventions

- **The four lockdown constants are hard-coded by design.** No env-var override. Admin-side plugin/theme/core installs would land on ephemeral pod disk and disappear on restart, replicating inconsistently across replicas. Hard-failing is the correct UX.
- **The site image is immutable.** All code (WP core + plugins + themes + custom code) is baked at build time. Releases happen via `git tag vX.Y.Z` → CI builds → `helm upgrade --set image.tag=vX.Y.Z`.
- **Bedrock layout is the contract.** `web/wp` for core, `web/app` for content, `config/` for env-driven settings. Don't flatten or rearrange.
- **`humanmade/s3-uploads` is a transitive dep** of `eightoeight/fp-mu-plugin`. Don't `composer require` it directly — that risks version drift.

## Common edits

- **Add a plugin:** `composer require wpackagist-plugin/<slug>` (composer config wires up wpackagist as a repo). After image rebuild + deploy, activate once with `wp plugin activate <slug>` against the running pod — activation is DB state, not image state, so it persists across releases. Full step-by-step at <https://docs.frankenpress.com/customizing#add-a-plugin>.
- **Remove a plugin:** `wp plugin deactivate <slug>` against the live cluster, then `composer remove wpackagist-plugin/<slug>` on a branch. Deactivate-before-remove avoids a noisy admin warning every page load. Full flow at <https://docs.frankenpress.com/customizing#remove-a-plugin>.
- **Add a theme:** `composer require wpackagist-theme/<slug>`. After deploy, `wp theme activate <slug>` (one-time, DB state).
- **Remove a theme:** `wp theme activate <other>` first (you can't remove the active theme), then `composer remove wpackagist-theme/<slug>` on a branch.
- **Add custom code:** drop a directory under the right `web/app/*` subtree and commit it. The `.gitignore` ignores composer-installed content but unhides committed paths.
- **Bump WP core:** edit the `roots/wordpress` constraint in `composer.json` and `composer update roots/wordpress`.
- **Bump fp-runtime base:** edit the `ARG FP_RUNTIME_VERSION` line in `Dockerfile`, or override at build via `--build-arg FP_RUNTIME_VERSION=<tag>`.

## Don'ts

- **Don't commit `web/wp/`, `vendor/`, `.env`, `node_modules/`, or `web/app/uploads/`.** The `.gitignore` already covers these.
- **Don't put real secrets in `.env`** — use `wp dotenv salts generate` for local dev keys; production injects via Helm values + Secrets.
- **Don't edit `web/wp-config.php`** to add config — it's a thin loader. All config lives in `config/application.php` and `config/environments/*.php`.
- **Don't relax the lockdown constants.** They're not a setting; they're a load-bearing safety property. If you genuinely need them off (developer-only environment, etc.), you understand what you're doing.
- **Don't bake fp-mu-plugin config into `application.php`.** It reads `FP_S3_*` and `FP_SOUIN_*` env vars itself; defining those constants directly may double-define.
- **Don't bypass `roots/bedrock-autoloader`** by manually requiring mu-plugin files — the loader handles discovery + caching.
- **Don't add the Mintlify "starter kit" copy** if you find yourself writing READMEs/docs for a fork.

## Running things

- `make setup` — first-time bootstrap (composer install, `.env` from `.env.example`)
- `make up` — start the local stack (site + db + redis + minio)
- `make down` — stop and drop volumes
- `make wp -- <wp-cli args>` — run wp-cli in the site container
- `make lint` — phpcs against `config/` and any custom mu-plugins
- `make logs` — tail site logs
- `make shell` — bash into the site container

## When you ship a release

```bash
git tag v1.2.0
git push origin v1.2.0
```

CI builds + pushes `ghcr.io/<your-org>/<your-site>:v1.2.0` to your GHCR.
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
