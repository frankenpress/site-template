<?php
/**
 * Production overrides.
 *
 * Loaded when WP_ENV=production. Disable all auto-updates (the image is
 * the source of truth) and quiet down debug output.
 *
 * @package FrankenPress\Site
 */

use Roots\WPConfig\Config;

Config::define( 'WP_DEBUG', false );
Config::define( 'WP_DEBUG_DISPLAY', false );
Config::define( 'WP_DEBUG_LOG', false );
Config::define( 'SCRIPT_DEBUG', false );
Config::define( 'AUTOMATIC_UPDATER_DISABLED', true );
Config::define( 'WP_AUTO_UPDATE_CORE', false );
Config::define( 'WP_AUTO_UPDATE_PLUGINS', false );
Config::define( 'WP_AUTO_UPDATE_THEMES', false );

ini_set( 'display_errors', '0' );
