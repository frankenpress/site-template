# syntax=docker/dockerfile:1.7

# FrankenPress site image.
#
# Multi-stage:
#   1. composer install in a slim composer image (no PHP runtime tooling
#      pulled into the final image).
#   2. fp-runtime (Caddy + FrankenPHP + Souin + WP-friendly extensions +
#      fp-mu-plugin baked) extended with the site's web/, config/ and
#      vendor/ tree.

ARG FP_RUNTIME_IMAGE=ghcr.io/frankenpress/runtime
ARG FP_RUNTIME_VERSION=php8.3

# ---------- Composer build ----------
FROM composer:2 AS deps

WORKDIR /app

# Cache composer downloads across layers when possible.
COPY composer.json composer.lock* ./

# Install runtime deps only — no dev tooling in the final image.
# Mount a GitHub token (CI) to authenticate api.github.com requests
# when fetching VCS dist tarballs; anonymous access hits a 60-req/hour
# rate limit. Local builds without the secret fall through to anonymous.
RUN --mount=type=secret,id=github_token,target=/run/secrets/github_token \
    if [ -s /run/secrets/github_token ]; then \
        composer config --global --auth github-oauth.github.com "$(cat /run/secrets/github_token)"; \
    fi && \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

# Bring in the site code so post-autoload-dump can finalise.
COPY . .

# Re-run autoload generation now that source files are present, and run
# any post-install scripts the project declares.
RUN composer dump-autoload --classmap-authoritative --no-dev

# ---------- Runtime ----------
FROM ${FP_RUNTIME_IMAGE}:${FP_RUNTIME_VERSION}

# The runtime image bakes fp-mu-plugin at /app/web/app/mu-plugins/fp/.
# This site composer-installs the canonical copy at mu-plugins/fp-mu-plugin/
# (loaded via roots/bedrock-autoloader). Remove the runtime-baked copy to
# avoid duplicate code in the image — bedrock-autoloader wouldn't load it
# anyway (no matching <dir>/<dir>.php pattern), so it's pure dead weight.
RUN rm -rf /app/web/app/mu-plugins/fp

# Copy the composer-resolved tree onto the runtime base.
COPY --from=deps --chown=www-data:www-data /app/web/wp /app/web/wp
COPY --from=deps --chown=www-data:www-data /app/web/wp-config.php /app/web/wp-config.php
COPY --from=deps --chown=www-data:www-data /app/web/index.php /app/web/index.php
COPY --from=deps --chown=www-data:www-data /app/web/app /app/web/app
COPY --from=deps --chown=www-data:www-data /app/config /app/config
COPY --from=deps --chown=www-data:www-data /app/vendor /app/vendor

# OCI labels (consumers override SOURCE_COMMIT / BUILD_DATE in CI).
ARG SOURCE_COMMIT=""
ARG BUILD_DATE=""
LABEL org.opencontainers.image.title="fp-site" \
      org.opencontainers.image.description="FrankenPress WordPress site image" \
      org.opencontainers.image.source="https://github.com/frankenpress/site-template" \
      org.opencontainers.image.licenses="Apache-2.0" \
      org.opencontainers.image.revision="${SOURCE_COMMIT}" \
      org.opencontainers.image.created="${BUILD_DATE}"
