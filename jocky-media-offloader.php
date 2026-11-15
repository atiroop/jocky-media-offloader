<?php
/**
 * Plugin Name: Jocky Media Offloader
 * Description: Safely queue and verify WordPress media copies in Cloudflare R2 while retaining local files.
 * Version: 0.2.1
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Author: Jocky
 * License: GPL-2.0-or-later
 * Text Domain: jocky-media-offloader
 *
 * @package Jocky\MediaOffloader
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'JMO_VERSION', '0.2.1' );
define( 'JMO_FILE', __FILE__ );
define( 'JMO_DIR', plugin_dir_path( __FILE__ ) );

$jmo_autoload = JMO_DIR . 'vendor/autoload.php';
if ( ! class_exists( Jocky\MediaOffloader\Plugin::class ) && ! is_readable( $jmo_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Jocky Media Offloader is missing its Composer dependencies. Install the complete plugin package.', 'jocky-media-offloader' ) . '</p></div>';
			}
		}
	);
	return;
}

if ( ! class_exists( Jocky\MediaOffloader\Plugin::class ) ) {
	require_once $jmo_autoload;
}

register_activation_hook( __FILE__, array( Jocky\MediaOffloader\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Jocky\MediaOffloader\Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		( new Jocky\MediaOffloader\Plugin() )->boot();
	}
);
