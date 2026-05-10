<?php
/**
 * WordPress front-controller (Bedrock layout).
 *
 * The runtime image expects the docroot to be `/app/web` (configurable via
 * the `FP_DOCROOT` env var on the runtime).
 *
 * @package FrankenPress\Site
 */

define( 'WP_USE_THEMES', true );

require __DIR__ . '/wp/wp-blog-header.php';
