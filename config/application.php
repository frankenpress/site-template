<?php
/**
 * Site config — Bedrock-style, FrankenPress-aware.
 *
 * Reads env vars with the names the FrankenPress stack injects. The
 * platform contract:
 *
 *   - WP_HOME / WP_SITEURL                     — required, set by your
 *                                                 deployment (Helm/compose)
 *   - DB_NAME / DB_USER / DB_PASSWORD / DB_HOST — DB connection
 *   - AUTH_KEY etc. (8 keys+salts)              — Secret-injected
 *   - FP_S3_*                                   — consumed by mu-plugin's
 *                                                 S3UploadsBootstrap
 *   - FP_SOUIN_REDIS_*                          — consumed by mu-plugin's
 *                                                 SouinInvalidator
 *   - REDIS_URL                                 — consumed by runtime's
 *                                                 Caddyfile (Souin HTTP cache)
 *
 * The lockdown constants (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`)
 * are gated on `KUBERNETES_SERVICE_HOST`: locked in-cluster (the image
 * is the source of truth and UI-written files vanish on pod restart),
 * relaxed out-of-cluster so local dev can drive Site Editor, install
 * block plugins or evaluation themes, and round-trip the result into
 * the image + DB via `wp fp snapshot`.
 *
 * @package FrankenPress\Site
 */

use Roots\WPConfig\Config;
use function Env\env;

$root_dir = dirname( __DIR__ );

if ( file_exists( $root_dir . '/.env' ) ) {
	$dotenv = Dotenv\Dotenv::createImmutable( $root_dir );
	$dotenv->load();
	$dotenv->required(
		array(
			'WP_HOME',
			'WP_SITEURL',
			'DB_NAME',
			'DB_USER',
			'DB_PASSWORD',
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		)
	);
}

/** Environment (development|staging|production). */
Config::define( 'WP_ENV', env( 'WP_ENV' ) ?: 'production' );

/** URLs. */
Config::define( 'WP_HOME', env( 'WP_HOME' ) );
Config::define( 'WP_SITEURL', env( 'WP_SITEURL' ) );

/** Path containing the WordPress core (Bedrock convention). */
Config::define( 'WP_CORE_DIRECTORY', env( 'WP_CORE_DIRECTORY' ) ?: '/wp' );

/** Custom Content Directory: web/app (Bedrock convention). */
Config::define( 'CONTENT_DIR', '/app' );
$webroot_dir = $root_dir . '/web';
Config::define( 'WP_CONTENT_DIR', $webroot_dir . Config::get( 'CONTENT_DIR' ) );
Config::define( 'WP_CONTENT_URL', Config::get( 'WP_HOME' ) . Config::get( 'CONTENT_DIR' ) );

/** Database. */
Config::define( 'DB_NAME', env( 'DB_NAME' ) );
Config::define( 'DB_USER', env( 'DB_USER' ) );
Config::define( 'DB_PASSWORD', env( 'DB_PASSWORD' ) );
Config::define( 'DB_HOST', env( 'DB_HOST' ) ?: 'localhost' );
Config::define( 'DB_CHARSET', 'utf8mb4' );
Config::define( 'DB_COLLATE', '' );

$table_prefix = env( 'DB_PREFIX' ) ?: 'wp_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress requires assigning $table_prefix in wp-config.

/** Authentication keys + salts. Inject via Secret in production. */
Config::define( 'AUTH_KEY', env( 'AUTH_KEY' ) );
Config::define( 'SECURE_AUTH_KEY', env( 'SECURE_AUTH_KEY' ) );
Config::define( 'LOGGED_IN_KEY', env( 'LOGGED_IN_KEY' ) );
Config::define( 'NONCE_KEY', env( 'NONCE_KEY' ) );
Config::define( 'AUTH_SALT', env( 'AUTH_SALT' ) );
Config::define( 'SECURE_AUTH_SALT', env( 'SECURE_AUTH_SALT' ) );
Config::define( 'LOGGED_IN_SALT', env( 'LOGGED_IN_SALT' ) );
Config::define( 'NONCE_SALT', env( 'NONCE_SALT' ) );

/**
 * Cron driver.
 *
 * In-process pseudo-cron is opt-out via env so the chart can pair this
 * with its `wpCron` CronJob (which runs `wp cron event run --due-now`).
 * Defaults to false: a developer running the template without the chart
 * still gets WP's normal page-load cron behaviour.
 */
Config::define( 'DISABLE_WP_CRON', filter_var( env( 'DISABLE_WP_CRON' ) ?: 'false', FILTER_VALIDATE_BOOLEAN ) );

/**
 * SSL behind a reverse proxy (Caddy/Gateway terminates TLS upstream).
 * Default off in development (local Docker Compose serves plain http on
 * :8080 — forcing https would 302 every admin request to a port that
 * isn't TLS-served). Default on everywhere else.
 */
$fp_default_force_ssl_admin = 'development' === Config::get( 'WP_ENV' ) ? 'false' : 'true';
Config::define( 'FORCE_SSL_ADMIN', filter_var( env( 'FORCE_SSL_ADMIN' ) ?: $fp_default_force_ssl_admin, FILTER_VALIDATE_BOOLEAN ) );
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

/**
 * Lockdown — gated on whether we're running inside Kubernetes, with
 * a narrow per-Pod opt-out for the chart's install Job.
 *
 * In-cluster (`KUBERNETES_SERVICE_HOST` is kubelet-injected on every
 * pod): admin-side plugin/theme installs, the file editor, AND the
 * indirect filesystem-write paths (translation packs, font installs,
 * Site Health helper-file writes, etc.) are forbidden. The image is
 * the source of truth; any UI-written file lands on ephemeral pod
 * disk, vanishes on restart, and replicates inconsistently across
 * replicas. `DISALLOW_INDIRECT_FILE_MODS` (WP 6.4+) is the third flag
 * — `DISALLOW_FILE_MODS` covers the obvious installer paths but core
 * still writes via `WP_Filesystem` for language packs, the font
 * library, and Site Health probes; this closes those.
 *
 * Out-of-cluster (docker-compose, bare local): all three are relaxed
 * so a developer can use Site Editor freely, install block plugins or
 * evaluation themes, and promote the resulting code into the image
 * and the resulting state into a snapshot via `wp fp snapshot`.
 * `KUBERNETES_SERVICE_HOST` can't appear in a local stack unless
 * something fakes it, so prod can't accidentally land in the relaxed
 * mode.
 *
 * Narrow opt-out: `FP_ALLOW_FILE_MODS=1`. The charts `site` chart sets
 * this on the install Job container ONLY (web Pods, wpcron Pods, and
 * init containers never see it) so `wp plugin install` can run
 * transiently inside the install Job — used by `wp fp apply` to bring
 * up WP-Importer for the duration of snapshot apply. The plugin file
 * lives in the Job pod's writable overlay only; mu-plugin's apply
 * path deactivates the plugin before the Pod exits, so the next web
 * Pod sees a clean `wp_options.active_plugins`. All three flags share
 * the opt-out — WP-Importer's setup touches indirect-write paths too.
 */
$fp_in_kubernetes = (bool) getenv( 'KUBERNETES_SERVICE_HOST' );
$fp_allow_mods    = filter_var( getenv( 'FP_ALLOW_FILE_MODS' ), FILTER_VALIDATE_BOOLEAN );
$fp_lockdown      = $fp_in_kubernetes && ! $fp_allow_mods;
Config::define( 'DISALLOW_FILE_EDIT', $fp_lockdown );
Config::define( 'DISALLOW_FILE_MODS', $fp_lockdown );
Config::define( 'DISALLOW_INDIRECT_FILE_MODS', $fp_lockdown );

/**
 * Out-of-cluster only: force `WP_Filesystem` to "direct" mode. WP's
 * autodetect (`get_filesystem_method()`) rejects "direct" when the file
 * PHP creates is owned differently than `wp-admin/includes/file.php` —
 * on the runtime image PHP runs as root but WP files are www-data, so
 * the check fails and admin install flows fall back to a ftp/ssh2
 * credentials prompt that AJAX endpoints can't satisfy. In-cluster the
 * chart's securityContext makes the autodetect agree on its own, so we
 * leave it alone there.
 */
if ( ! $fp_in_kubernetes ) {
	Config::define( 'FS_METHOD', 'direct' );
}

/** Per-environment overrides. */
$env_config = __DIR__ . '/environments/' . Config::get( 'WP_ENV' ) . '.php';
if ( file_exists( $env_config ) ) {
	require_once $env_config;
}

Config::apply();

/** Bootstrap WordPress. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $webroot_dir . '/wp/' );
}
