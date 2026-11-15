<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Admin\DiagnosticsPage;
use Jocky\MediaOffloader\Admin\SettingsPage;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Plugin;
use Jocky\MediaOffloader\Storage\StorageClientFactory;
use WP_UnitTestCase;

final class PluginSmokeTest extends WP_UnitTestCase {
	public function test_activation_and_deactivation_preserve_options(): void {
		delete_option( 'jmo_settings' );
		Plugin::activate();
		self::assertSame( 'jocky-cdn-storage', get_option( 'jmo_settings' )['bucket'] );
		$custom = array(
			'account_id' => str_repeat( 'a', 32 ),
			'bucket'     => 'custom-bucket',
			'cdn_domain' => 'cdn.example.com',
		);
		update_option( 'jmo_settings', $custom );
		Plugin::deactivate();
		self::assertSame( $custom, get_option( 'jmo_settings' ) );
	}

	public function test_pages_require_administrator_and_escape_diagnostic_output(): void {
		$factory  = new StorageClientFactory( new CredentialsProvider() );
		$settings = new SettingsPage( $factory );
		$settings->register();
		$diagnostics = new DiagnosticsPage( new CredentialsProvider() );
		$diagnostics->register();

		wp_set_current_user( 0 );
		$this->expectException( \WPDieException::class );
		$settings->render();
	}

	public function test_connection_action_rejects_invalid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$settings = new SettingsPage( new StorageClientFactory( new CredentialsProvider() ) );
		$_REQUEST = array();
		$this->expectException( \WPDieException::class );
		$settings->authorize_connection_test();
	}

	public function test_diagnostics_escapes_transient_text_and_jio_is_optional(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_transient(
			'jmo_last_connection_test',
			array(
				'status'  => '<script>xss</script>',
				'message' => 'safe',
			),
			60
		);
		$diagnostics = new DiagnosticsPage( new CredentialsProvider() );
		ob_start();
		$diagnostics->render();
		$html = (string) ob_get_clean();
		self::assertStringNotContainsString( '<script>xss</script>', $html );
		self::assertStringContainsString( '&lt;script&gt;xss&lt;/script&gt;', $html );
		self::assertFalse( ( new \Jocky\MediaOffloader\Integration\JioDetector() )->detect()['active'] );
	}

	public function test_jio_metadata_is_detected_when_inactive_and_active(): void {
		$plugin_file = 'jocky-image-optimizer/jocky-image-optimizer.php';
		$metadata = array(
			$plugin_file => array(
				'Name'       => 'Jocky Image Optimizer',
				'TextDomain' => 'jocky-image-optimizer',
				'Version'    => '0.6.1-beta.1',
			),
		);
		$inactive_detector = new \Jocky\MediaOffloader\Integration\JioDetector(
			static function () use ( $metadata ): array {
				return $metadata;
			},
			static function (): bool {
				return false;
			}
		);
		$inactive = $inactive_detector->detect();
		self::assertFalse( $inactive['active'] );
		self::assertSame( '0.6.1-beta.1', $inactive['version'] );

		$active_detector = new \Jocky\MediaOffloader\Integration\JioDetector(
			static function () use ( $metadata ): array {
				return $metadata;
			},
			static function (): bool {
				return true;
			}
		);
		self::assertTrue( $active_detector->detect()['active'] );
	}

	public function test_plugin_does_not_register_media_mutation_or_url_rewrite_hooks(): void {
		self::assertFalse( has_filter( 'wp_get_attachment_url' ) );
		self::assertFalse( has_filter( 'wp_calculate_image_srcset' ) );
		self::assertFalse( has_action( 'wp_handle_upload' ) );
		self::assertNotFalse( has_filter( 'wp_generate_attachment_metadata' ) );
	}
}
