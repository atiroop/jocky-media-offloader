<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Admin\DiagnosticsPage;
use Jocky\MediaOffloader\Admin\SettingsPage;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Plugin;
use Jocky\MediaOffloader\Media\VerifiedObjectResolver;
use Jocky\MediaOffloader\Storage\ConnectionStatus;
use Jocky\MediaOffloader\Storage\StorageClientFactory;
use WP_UnitTestCase;

final class PluginSmokeTest extends WP_UnitTestCase {
	public function test_activation_and_deactivation_preserve_options(): void {
		delete_option( 'jmo_settings' );
		delete_option( 'jmo_cdn_delivery' );
		Plugin::activate();
		self::assertSame( 'jocky-cdn-storage', get_option( 'jmo_settings' )['bucket'] );
		self::assertFalse( (bool) get_option( 'jmo_cdn_delivery', false ) );
		self::assertFalse( (bool) get_option( 'jmo_auto_offload', false ) );
		$custom = array(
			'account_id' => str_repeat( 'a', 32 ),
			'bucket'     => 'custom-bucket',
			'cdn_domain' => 'cdn.example.com',
		);
		update_option( 'jmo_settings', $custom );
		Plugin::deactivate();
		self::assertSame( $custom, get_option( 'jmo_settings' ) );
	}

	public function test_plugin_header_and_runtime_use_the_milestone_three_patch_version(): void {
		$plugin_data = get_plugin_data( JMO_FILE, false, false );
		self::assertSame( '0.3.1', JMO_VERSION );
		self::assertSame( JMO_VERSION, $plugin_data['Version'] );
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
		$_POST    = array( 'nonce' => 'invalid' );
		self::assertSame( 'invalid_nonce', $settings->authorize_ajax_connection_test()->get_error_code() );
	}

	public function test_connection_action_requires_administrator_capability(): void {
		wp_set_current_user( 0 );
		$settings = new SettingsPage( new StorageClientFactory( new CredentialsProvider() ) );
		$_POST    = array();
		self::assertSame( 'forbidden', $settings->authorize_ajax_connection_test()->get_error_code() );
	}

	public function test_cdn_readiness_action_rejects_unauthorized_and_invalid_nonce_requests(): void {
		$settings = new SettingsPage( new StorageClientFactory( new CredentialsProvider() ) );
		wp_set_current_user( 0 );
		$_POST = array();
		self::assertSame( 'forbidden', $settings->authorize_ajax_cdn_readiness()->get_error_code() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array( 'cdn_nonce' => 'invalid' );
		self::assertSame( 'invalid_nonce', $settings->authorize_ajax_cdn_readiness()->get_error_code() );
	}

	public function test_diagnostics_escapes_transient_text_and_jio_is_optional(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			ConnectionStatus::OPTION,
			array(
				'status'                  => 'connected',
				'message'                 => '<script>xss</script>',
				'tested_at'               => gmdate( 'c' ),
				'settings_fingerprint'    => 'old-fingerprint',
				'credential_fingerprint'  => 'old-credential-fingerprint',
			)
		);
		$diagnostics = new DiagnosticsPage( new CredentialsProvider() );
		ob_start();
		$diagnostics->render();
		$html = (string) ob_get_clean();
		self::assertStringNotContainsString( '<script>xss</script>', $html );
		self::assertStringNotContainsString( '&lt;script&gt;xss&lt;/script&gt;', $html );
		self::assertStringContainsString( 'Stale', $html );
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

	public function test_plugin_registers_scoped_url_filters_without_media_mutation_hooks(): void {
		self::assertNotFalse( has_filter( 'wp_get_attachment_url' ) );
		self::assertNotFalse( has_filter( 'wp_calculate_image_srcset' ) );
		self::assertFalse( has_action( 'wp_handle_upload' ) );
		self::assertNotFalse( has_filter( 'wp_generate_attachment_metadata' ) );
	}

	public function test_cdn_resolver_fails_closed_when_disabled_or_unverified(): void {
		$attachment_id = wp_insert_attachment( array( 'post_mime_type' => 'application/pdf', 'post_title' => 'resolver test', 'post_status' => 'inherit' ), false );
		$url = 'http://example.org/wp-content/uploads/2026/11/test.pdf';
		$resolver = new VerifiedObjectResolver();
		update_option( 'jmo_cdn_delivery', false );
		self::assertSame( $url, $resolver->resolve( $url, $attachment_id ) );
		update_option( 'jmo_cdn_delivery', true );
		update_option( 'jmo_settings', array( 'account_id' => str_repeat( 'a', 32 ), 'bucket' => 'jocky-cdn-storage', 'cdn_domain' => 'cdn.example.com' ) );
		self::assertSame( $url, $resolver->resolve( $url, $attachment_id ), 'no verified DB mapping must retain the local URL' );
	}
}
