# site-template

> **Building a new site?** Click **[Use this template ↗](https://github.com/frankenpress/site-template/generate)** on GitHub.
> Your new repo will auto-customise (`composer.json`, `Dockerfile`) and publish its first image to GHCR within a couple of minutes.
> Then follow [**Your first site**](https://docs.frankenpress.com/your-first-site) for the rest of the journey.

**FrankenPress site template** — a [GitHub template repo](https://docs.github.com/en/repositories/creating-and-managing-repositories/creating-a-repository-from-a-template) for new WordPress sites running on the FrankenPress stack:

- [`runtime`](https://github.com/frankenpress/runtime) (Caddy + FrankenPHP + Souin) as the base image
- [`mu-plugin`](https://github.com/frankenpress/mu-plugin) (S3 uploads bootstrap, Souin invalidator, Site Health overrides, SMTP mailer) baked in
- [`humanmade/s3-uploads`](https://github.com/humanmade/S3-Uploads) for media offload (transitive dep of mu-plugin)
- Bedrock-style layout (`web/wp` for core, `web/app` for content, `config/` for env-driven settings)

**Documentation:** <https://docs.frankenpress.com/components/site-template>

## Quickstart

Local Docker Compose dev — site + MariaDB + Redis + MinIO on your machine. For the cluster path, see [Your first site](https://docs.frankenpress.com/your-first-site).

1. **Create your repo from this template.** Click ["Use this template"](https://github.com/frankenpress/site-template/generate) on GitHub. Pick a name (e.g. `my-site`).

2. **Clone it locally.**
   ```bash
   git clone git@github.com:<your-org>/<your-site>.git
   cd <your-site>
   ```

3. **Bootstrap** — composer install + `.env` from `.env.example`.
   ```bash
   make setup
   ```

4. **Start the stack** — site + MariaDB + Redis + MinIO via Docker Compose.
   ```bash
   make up
   ```
   `curl http://localhost:8080/healthz` should return `ok` once Caddy is up (a few seconds).

5. **Install WordPress** (one-time — the Helm chart auto-runs this on cluster deploys, Docker Compose doesn't).
   ```bash
   make wp ARGS="core install \
     --url=http://localhost:8080 \
     --title='My Site' \
     --admin_user=admin \
     --admin_email=admin@example.com \
     --admin_password=admin \
     --skip-email"
   ```

6. **Log in** at http://localhost:8080/wp/wp-admin/. In local dev the "Update WordPress" / "Update Plugins" / "Update Themes" buttons are **enabled** — handy for installing block plugins or evaluation themes during design work. In-cluster (where `KUBERNETES_SERVICE_HOST` is kubelet-injected), the same UIs are disabled because the image is the source of truth. The gate lives in `config/application.php`.

7. **Upload an image** in the Media Library. It lands in MinIO at http://localhost:9001 (login `minioadmin` / `minioadmin`).

**Next:** [Your first site](https://docs.frankenpress.com/your-first-site) (fork → image → cluster) or [Customizing](https://docs.frankenpress.com/customizing) (add plugins and themes).

## Designer flow — capturing local design state

Once a designer has iterated locally (Site Editor templates, global styles, navigation, site identity), capture the state with the [`fp`](https://github.com/frankenpress/fp) host-side CLI:

```bash
brew install frankenpress/tap/fp
cd path/to/your-site
fp snapshot                # prompts for slug + note (Enter accepts the suggested defaults)
```

This writes `web/imports/<slug>/` containing the manifest, scoped templates / options / attachments, plus referenced upload binaries. Review the diff, commit, push, open a site-repo PR. The chart's install Job picks up the snapshot on the next image release and applies it on-cluster. Full walkthrough: [designer flow](https://docs.frankenpress.com/designer-flow).

`fp` reads optional configuration from `frankenpress.toml` at the repo root. The empty file is valid (every default works for a site-template-shaped repo); a custom layout overrides via:

```toml
[snapshot]
# project = "your-compose-project"     # default: basename(repo-root)
# service = "site"                     # compose service running WordPress
# output_dir = "web/imports"           # host-side, relative to repo root
# container_output_dir = "/app/web/imports"
```

The legacy `make snapshot` target is retained for one release with a deprecation message pointing here — it will be removed in a follow-up.

## Layout (Bedrock)

```
.
├── composer.json              # site dependencies (no WC, no theme picks)
├── Dockerfile                 # multi-stage: composer build → runtime
├── docker-compose.yml         # local dev: site + mariadb + redis + minio
├── .env.example               # all platform env vars with sane defaults
├── config/
│   ├── application.php        # main config, lockdown constants, env wiring
│   └── environments/
│       ├── development.php    # WP_DEBUG=true, indexing disabled
│       ├── staging.php        # WP_DEBUG=true (log only), indexing disabled
│       └── production.php     # WP_DEBUG=false, all auto-updates disabled
└── web/                       # docroot
    ├── index.php              # WP front controller
    ├── wp-config.php          # thin loader → config/application.php
    ├── wp/                    # WP core (composer-installed, gitignored)
    └── app/                   # wp-content
        ├── mu-plugins/
        │   └── fp/            # baked by runtime image — don't commit
        ├── plugins/           # composer require wpackagist-plugin/...
        └── themes/            # composer require wpackagist-theme/...
```

## Adding plugins / themes

Composer-managed (recommended):
```bash
composer require wpackagist-plugin/seo-by-rank-math
composer require wpackagist-theme/twentytwentyfive
```

The `wpackagist` repository is wired up in `composer.json`. Plugins land in `web/app/plugins/`, themes in `web/app/themes/`.

Custom code (your own theme or plugin): drop a directory under the right `web/app/*` subtree and commit it. Composer-managed slugs go through composer; bespoke code goes through git.

## Environment variables

The full reference is in [`.env.example`](./.env.example). Highlights:

| Var | Notes |
|---|---|
| `WP_ENV` | `development` / `staging` / `production` — selects which `config/environments/*.php` overrides load. Defaults to `production` if unset. |
| `WP_HOME`, `WP_SITEURL` | Site URLs (no trailing slash). |
| `DB_*` | DB connection. Match `docker-compose.yml`'s defaults for local dev. |
| 8× `*_KEY`/`*_SALT` | Auth keys & salts. Generate at [WP secret-key API](https://api.wordpress.org/secret-key/1.1/salt/). Local dev keeps the `dev-key` / `dev-salt` defaults in `docker-compose.yml` — no need to generate. |
| `FP_S3_BUCKET` etc. | S3 config consumed by `mu-plugin`'s `S3UploadsBootstrap`. **Required** — the bootstrap *refuses uploads* if these are missing (no silent fallback to ephemeral local disk). |
| `FP_SOUIN_REDIS_*` | Redis connection for `mu-plugin`'s `SouinInvalidator`. Same Redis as the runtime's Souin HTTP cache. |
| `REDIS_URL` | Used by `runtime`'s Caddyfile for the Souin HTTP cache backend. |

## Hardening (the lockdown)

`config/application.php` gates the two filesystem-mod constants on `KUBERNETES_SERVICE_HOST` — locked in-cluster, relaxed out-of-cluster so developers can install block plugins / evaluation themes / etc. during design work:

```php
$fp_in_kubernetes = (bool) getenv( 'KUBERNETES_SERVICE_HOST' );
Config::define( 'DISALLOW_FILE_EDIT', $fp_in_kubernetes );
Config::define( 'DISALLOW_FILE_MODS', $fp_in_kubernetes );
```

Plus `production.php` (only loaded when `WP_ENV=production`, always on there):

```php
Config::define( 'AUTOMATIC_UPDATER_DISABLED', true );
Config::define( 'WP_AUTO_UPDATE_CORE', false );
```

The kubelet injects `KUBERNETES_SERVICE_HOST` on every pod; docker-compose and bare-local never set it. Prod can't accidentally land in the relaxed mode. In-cluster the image is still the source of truth — admin-side installs there would land on ephemeral disk and disappear on pod restart, replicating inconsistently across replicas, so hard-failing is the correct UX.

## CI / publishing

- `.github/workflows/lint.yml` runs PHPCS + composer audit on every push and PR.
- `.github/workflows/build.yml` builds the site image and pushes to `ghcr.io/<your-org>/<your-site>` on push-to-main and on `v*.*.*` tag pushes.

To cut a versioned release of your site image:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

The image is then available at `ghcr.io/<your-org>/<your-site>:v1.0.0`.

## Companion repos

| Repo | Purpose |
|---|---|
| [`runtime`](https://github.com/frankenpress/runtime) | Base container image |
| [`mu-plugin`](https://github.com/frankenpress/mu-plugin) | Must-use plugin (S3 bootstrap + Souin invalidator + Site Health + SMTP) |
| [`site-template`](https://github.com/frankenpress/site-template) (this repo) | GitHub template for new sites |
| [`charts`](https://github.com/frankenpress/charts) | Helm chart `site` for Kubernetes deployment |
