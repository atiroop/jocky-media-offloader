<?php
declare(strict_types=1);

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $tests_dir ) || ! is_file( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library is missing. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

$polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( is_string( $polyfills_path ) && '' !== $polyfills_path && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path );
}
require_once $tests_dir . '/includes/functions.php';
$plugin_file = getenv( 'JMO_PLUGIN_FILE' );
if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
	$plugin_file = dirname( __DIR__ ) . '/jocky-media-offloader.php';
}
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_file ): void {
		require_once $plugin_file;
	}
);
require $tests_dir . '/includes/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
