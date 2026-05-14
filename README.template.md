# {{REPO}}

WordPress site running on the [FrankenPress](https://frankenpress.com) stack — Caddy + FrankenPHP + Souin + S3 uploads, deployed to Kubernetes via the [`site`](https://github.com/frankenpress/charts) Helm chart.

## Quickstart — local design dev

```bash
brew install frankenpress/tap/fp        # one-time
git clone git@github.com:{{OWNER}}/{{REPO}}.git
cd {{REPO}}
fp init
```

`fp init` scaffolds `.env` from `.env.example`, runs `composer install` via docker (no PHP needed on host), brings the local stack up (`docker compose up -d --wait`), installs WordPress with sensible defaults (`admin / admin / admin@example.test`), and applies the latest committed snapshot if one exists.

Open <http://localhost:8080/wp/wp-admin/> and log in. Override the admin credentials and title via the `[init]` section of `frankenpress.toml`.

## Designer flow — capturing design state

After editing in WP Site Editor (templates, template parts, global styles, navigation, media):

```bash
fp snapshot
# → writes web/imports/<UTC-timestamp>/
git add web/imports/ && git commit -m "Snapshot: <what changed>"
git push
```

`fp snapshot` defaults the slug to a UTC timestamp (`YYYY-MM-DDTHH-MM-SSZ`); pass `--slug=<name>` for a milestone marker (e.g. `--slug=pre-rebrand`). Snapshots accumulate as history under `web/imports/` — the chart's install Job picks the latest by `manifest.created` at deploy time. No `git rm`-the-previous-snapshot step.

## Recovery from `docker compose down -v`

```bash
fp init
```

Same command. Idempotent. Brings the stack back from empty volumes to the state captured by the latest committed snapshot — assets and all, since binary files travel inside `web/imports/<slug>/uploads/` and are restored into MinIO when apply runs.

## Publishing a release

```bash
git tag v1.0.0 && git push origin v1.0.0
```

CI builds + pushes `ghcr.io/{{OWNER_LOWER}}/{{REPO_LOWER}}:v1.0.0`. GitOps reconciliation (Kargo + ArgoCD) picks the new tag up automatically into staging; production requires a human promotion in the Kargo UI.

## Adding plugins and themes

```bash
composer require wpackagist-plugin/<slug>
composer require wpackagist-theme/<slug>
```

Both [wpackagist](https://wpackagist.org/) and the FrankenPress mu-plugin repo are wired up in `composer.json`. Plugins land in `web/app/plugins/`, themes in `web/app/themes/`. Activate one-time via wp-cli (`make wp ARGS="plugin activate <slug>"`) or the WP admin in local dev.

## Layout

Bedrock-style. Full layout reference + customization patterns at <https://docs.frankenpress.com/components/site-template>.

- `composer.json` — site dependencies
- `frankenpress.toml` — `fp` CLI config (`[snapshot]`, `[init]`)
- `web/imports/` — committed design snapshots
- `web/app/{plugins,themes,mu-plugins}/` — wp-content
- `config/` — env-driven WordPress config (Bedrock `application.php`)
- `Dockerfile` — multi-stage build extending `ghcr.io/frankenpress/runtime`

## Documentation

- **Platform docs**: <https://docs.frankenpress.com/>
  - [Designer flow](https://docs.frankenpress.com/designer-flow) — full snapshot + apply walkthrough
  - [Your first site](https://docs.frankenpress.com/your-first-site) — fork → image → cluster
  - [Components](https://docs.frankenpress.com/components/site-template) — chart values, runtime config, mu-plugin
- **Upstream template**: <https://github.com/frankenpress/site-template>

## Lower-level commands (alternative to `fp init`)

If you'd rather drive each step manually:

```bash
make setup    # composer install + .env from .env.example
make up       # docker compose up -d (site + mariadb + redis + minio)
make wp ARGS="core install --url=http://localhost:8080 \
  --title='{{REPO}}' --admin_user=admin \
  --admin_email=admin@example.test --admin_password=admin --skip-email"
fp apply      # apply the latest committed snapshot (or fp apply <slug>)
```

`fp init` orchestrates all of the above in one command; this form is for designers who want to drive each step explicitly.
