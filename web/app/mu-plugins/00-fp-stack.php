<?php
/**
 * FrankenPress mu-plugins loader.
 *
 * WordPress only auto-loads .php files at the root of `mu-plugins/`, not in
 * subdirectories. Bedrock-style sites composer-install mu-plugin packages
 * into subdirs (`mu-plugins/<name>/...`), so we need a tiny root-level
 * loader to discover and bootstrap each one.
 *
 * The numeric `00-` prefix makes this load before any other root-level
 * mu-plugin files, alphabetical ordering aside. It does two things:
 *
 *   1. Loads composer's autoloader (so namespaced classes resolve).
 *   2. Boots `roots/bedrock-autoloader`, which scans every package
 *      installed at `mu-plugins/<name>/` and loads its main file.
 *
 * Don't edit this file unless you know what you're doing — it's the
 * platform's loader contract.
 *
 * @package FrankenPress\Site
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$composer = dirname( ABSPATH, 2 ) . '/vendor/autoload.php';
if ( file_exists( $composer ) ) {
	require_once $composer;
}

if ( class_exists( \Roots\Bedrock\Autoloader::class ) ) {
	new \Roots\Bedrock\Autoloader();
}
