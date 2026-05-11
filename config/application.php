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
 * relaxed out-of-cluster so local dev can drive premium-theme installers
 * (e.g. The7's "Pre-Made Website Templates" importer) end-to-end and
 * promote the result into the image + DB.
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
 * Lockdown — gated on whether we're running inside Kubernetes.
 *
 * In-cluster (`KUBERNETES_SERVICE_HOST` is kubelet-injected on every
 * pod): admin-side plugin/theme installs and the file editor are
 * forbidden. The image is the source of truth; any UI-written file
 * lands on ephemeral pod disk, vanishes on restart, and replicates
 * inconsistently across replicas.
 *
 * Out-of-cluster (docker-compose, bare local): both are relaxed so a
 * developer can drive premium-theme installers (e.g. The7's "Pre-Made
 * Website Templates" importer) end-to-end, then promote the resulting
 * code into the image and the resulting state into a DB snapshot.
 * `KUBERNETES_SERVICE_HOST` can't appear in a local stack unless
 * something fakes it, so prod can't accidentally land in the relaxed
 * mode.
 */
$fp_in_kubernetes = (bool) getenv( 'KUBERNETES_SERVICE_HOST' );
Config::define( 'DISALLOW_FILE_EDIT', $fp_in_kubernetes );
Config::define( 'DISALLOW_FILE_MODS', $fp_in_kubernetes );

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
