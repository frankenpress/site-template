<?php
/**
 * Plugin Name:  S3 Uploads (FrankenPress)
 * Plugin URI:   https://github.com/humanmade/S3-Uploads
 * Description:  Loads humanmade/s3-uploads as a must-use plugin so it appears as Must-Use in wp-admin and runs on every request without admin-side activation. Configured by fp-mu-plugin's S3UploadsBootstrap from FP_S3_* env vars.
 * Version:      1.0.0
 * Author:       EightOEight
 * Author URI:   https://eightoeight.io
 * License:      Apache-2.0
 *
 * The actual humanmade/s3-uploads code is composer-installed under
 * mu-plugins/s3-uploads/ (per the installer-paths mapping in composer.json).
 * WordPress only auto-loads .php files at the root of mu-plugins/, not in
 * subdirs — this thin stub does the require so WP and wp-admin recognise
 * s3-uploads as a Must-Use plugin.
 *
 * fp-mu-plugin's S3UploadsBootstrap also require_once's the same file as a
 * defensive fallback. require_once is idempotent, so loading from both
 * paths is safe.
 *
 * Don't edit unless you know what you're doing.
 *
 * @package FrankenPress\Site
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fp_s3_uploads_entry = __DIR__ . '/s3-uploads/s3-uploads.php';
if ( is_file( $fp_s3_uploads_entry ) ) {
	require_once $fp_s3_uploads_entry;
}
