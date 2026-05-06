<?php
/**
 * Staging overrides.
 *
 * Loaded when WP_ENV=staging. Like production but with verbose logging
 * and indexing disabled.
 *
 * @package FrankenPress\Site
 */

use Roots\WPConfig\Config;

Config::define( 'WP_DEBUG', true );
Config::define( 'WP_DEBUG_DISPLAY', false );
// Route WP debug entries to stderr, not wp-content/debug.log on disk.
// The pod runs with readOnlyRootFilesystem=true, so the default
// `WP_DEBUG_LOG=true` (which writes to disk) silently fails. stderr
// is picked up by any cluster log shipper alongside Caddy's JSON
// access log on stdout.
Config::define( 'WP_DEBUG_LOG', 'php://stderr' );
Config::define( 'SCRIPT_DEBUG', false );
Config::define( 'AUTOMATIC_UPDATER_DISABLED', true );
Config::define( 'WP_AUTO_UPDATE_CORE', false );
Config::define( 'DISALLOW_INDEXING', true );
