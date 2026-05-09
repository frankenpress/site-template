# fp-site-template

> **Building a new site?** Click **[Use this template ↗](https://github.com/EightOEight/fp-site-template/generate)** on GitHub.
> Your new repo will auto-customise (`composer.json`, `Dockerfile`) and publish its first image to GHCR within a couple of minutes.
> Then follow [**Your first site**](https://docs.frankenpress.com/your-first-site) for the rest of the journey.

**FrankenPress site template** — a [GitHub template repo](https://docs.github.com/en/repositories/creating-and-managing-repositories/creating-a-repository-from-a-template) for new WordPress sites running on the FrankenPress stack:

- [`fp-runtime`](https://github.com/EightOEight/fp-runtime) (Caddy + FrankenPHP + Souin) as the base image
- [`fp-mu-plugin`](https://github.com/EightOEight/fp-mu-plugin) (S3 uploads bootstrap, Souin invalidator, Site Health overrides, SMTP mailer) baked in
- [`humanmade/s3-uploads`](https://github.com/humanmade/S3-Uploads) for media offload (transitive dep of fp-mu-plugin)
- Bedrock-style layout (`web/wp` for core, `web/app` for content, `config/` for env-driven settings)

**Documentation:** <https://docs.frankenpress.com/components/fp-site-template>

## Quickstart

1. **Create your repo from this template** — click ["Use this template"](https://github.com/EightOEight/fp-site-template/generate) on GitHub. Pick a name (e.g. `my-site`).
2. **Clone + bootstrap**:
   ```bash
   git clone git@github.com:<your-org>/<your-site>.git
   cd <your-site>
   make setup           # composer install + .env from .env.example
   make up              # docker compose up -d (site + db + redis + minio)
   ```
3. **Install WordPress** (one-time, on first boot):
   ```bash
   make wp -- core install \
     --url=http://localhost:8080 \
     --title="My Site" \
     --admin_user=admin \
     --admin_email=admin@example.com \
     --admin_password=admin
   ```
4. **Visit** http://localhost:8080/wp/wp-admin/ → log in. The "Update WordPress" / "Update Plugins" / "Update Themes" buttons should be **absent** (lockdown is hard-coded — see `config/application.php`).
5. **Upload an image** in the Media Library → it lands in MinIO at http://localhost:9001 (login `minioadmin`/`minioadmin`).

## Layout (Bedrock)

```
.
├── composer.json              # site dependencies (no WC, no theme picks)
├── Dockerfile                 # multi-stage: composer build → fp-runtime
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
        │   └── fp/            # baked by fp-runtime image — don't commit
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
| 8× `*_KEY`/`*_SALT` | Auth keys & salts. Generate via [WP secret-key API](https://api.wordpress.org/secret-key/1.1/salt/) or `wp dotenv salts generate`. |
| `FP_S3_BUCKET` etc. | S3 config consumed by `fp-mu-plugin`'s `S3UploadsBootstrap`. **Required** — the bootstrap *refuses uploads* if these are missing (no silent fallback to ephemeral local disk). |
| `FP_SOUIN_REDIS_*` | Redis connection for `fp-mu-plugin`'s `SouinInvalidator`. Same Redis as the runtime's Souin HTTP cache. |
| `REDIS_URL` | Used by `fp-runtime`'s Caddyfile for the Souin HTTP cache backend. |

## Hardening (the lockdown)

The four constants in `config/application.php` are hard-coded:

```php
Config::define( 'DISALLOW_FILE_EDIT', true );
Config::define( 'DISALLOW_FILE_MODS', true );
```

Plus `production.php`:

```php
Config::define( 'AUTOMATIC_UPDATER_DISABLED', true );
Config::define( 'WP_AUTO_UPDATE_CORE', false );
```

There is **no env-var override** by design. The container image is the source of truth — admin-side plugin installs would land on ephemeral disk and disappear on pod restart, replicating inconsistently across replicas. Hard-failing is the correct UX.

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
| [`fp-runtime`](https://github.com/EightOEight/fp-runtime) | Base container image |
| [`fp-mu-plugin`](https://github.com/EightOEight/fp-mu-plugin) | Must-use plugin (S3 bootstrap + Souin invalidator + Site Health + SMTP) |
| [`fp-site-template`](https://github.com/EightOEight/fp-site-template) (this repo) | GitHub template for new sites |
| [`fp-charts`](https://github.com/EightOEight/fp-charts) | Helm chart `fp-site` for Kubernetes deployment |
