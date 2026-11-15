<?php
/**
 * Plugin bootstrap and lifecycle.
 *
 * @package Jocky\MediaOffloader
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader;

use Jocky\MediaOffloader\Admin\DiagnosticsPage;
use Jocky\MediaOffloader\Admin\JobsPage;
use Jocky\MediaOffloader\Admin\SettingsPage;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Storage\StorageClientFactory;
use Jocky\MediaOffloader\Queue\JobRepository;
use Jocky\MediaOffloader\Queue\MediaDiscovery;
use Jocky\MediaOffloader\Queue\QueueSchema;
use Jocky\MediaOffloader\Queue\QueueWorker;

/** Plugin lifecycle and service wiring. */
final class Plugin {
	/** Create default non-secret settings without overwriting existing values. */
	public static function activate(): void {
		QueueSchema::install();
		if ( function_exists( 'get_option' ) && false === get_option( 'jmo_settings', false ) ) {
			add_option(
				'jmo_settings',
				array(
					'account_id' => '',
					'bucket'     => 'jocky-cdn-storage',
					'cdn_domain' => '',
				),
				'',
				false
			);
		}
		add_option( 'jmo_auto_offload', false, '', false );
	}

	/** Preserve all settings and media during deactivation. */
	public static function deactivate(): void {
		// Preserve settings and all WordPress media on deactivation.
	}

	/** Load translations and register administrator screens. */
	public function boot(): void {
		QueueSchema::maybe_install();
		if ( function_exists( 'load_plugin_textdomain' ) ) {
			load_plugin_textdomain( 'jocky-media-offloader', false, dirname( plugin_basename( JMO_FILE ) ) . '/languages' );
		}
		$jobs   = new JobRepository();
		$worker = new QueueWorker( $jobs );
		$worker->register();
		( new MediaDiscovery( $jobs, $worker ) )->register();
		if ( is_admin() ) {
			$credentials = new CredentialsProvider();
			$factory     = new StorageClientFactory( $credentials );
			( new SettingsPage( $factory ) )->register();
			( new DiagnosticsPage( $credentials ) )->register();
			( new JobsPage( $jobs, $worker ) )->register();
		}
	}
}
