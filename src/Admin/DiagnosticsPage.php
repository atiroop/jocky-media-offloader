<?php
/**
 * Diagnostics screen.
 *
 * @package Jocky\MediaOffloader\Admin
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Admin;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Integration\JioDetector;
use Jocky\MediaOffloader\Storage\ConnectionStatus;

/** Renders the capability-gated system and storage diagnostics. */
final class DiagnosticsPage {
	/**
	 * Create the diagnostics page controller.
	 *
	 * @param CredentialsProvider $credentials Secure credential resolver.
	 */
	public function __construct( private readonly CredentialsProvider $credentials ) {}

	/** Register the admin menu. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue local styles for JMO screens.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false !== strpos( $hook_suffix, 'jmo-' ) || false !== strpos( $hook_suffix, 'jmo_' ) ) {
			wp_enqueue_style( 'jmo-admin', plugins_url( 'assets/admin.css', JMO_FILE ), array(), JMO_VERSION );
		}
	}

	/** Add the diagnostics submenu. */
	public function menu(): void {
		add_submenu_page( 'jmo-settings', __( 'Diagnostics', 'jocky-media-offloader' ), __( 'Diagnostics', 'jocky-media-offloader' ), 'manage_options', 'jmo-diagnostics', array( $this, 'render' ) );
	}

	/** Render escaped runtime and connectivity status. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view diagnostics.', 'jocky-media-offloader' ), '', array( 'response' => 403 ) );
		}
		global $wp_version;
		$config           = new Configuration();
		$settings         = $config->all();
		$jio              = ( new JioDetector() )->detect();
		$last             = ( new ConnectionStatus( $config, $this->credentials ) )->current();
		$last_status      = is_array( $last ) ? (string) ( $last['status'] ?? 'unknown' ) : 'not_run';
		$credential_ready = null !== $this->credentials->get();
		$settings_ready   = '' !== $settings['account_id'] && '' !== $settings['bucket'];
		$sdk_ready        = class_exists( \Aws\S3\S3Client::class );
		$values           = array(
			'Plugin version'              => JMO_VERSION,
			'WordPress version'           => (string) ( $wp_version ?? 'Unknown' ),
			'PHP version'                 => PHP_VERSION,
			'AWS SDK availability'        => $sdk_ready ? 'Available' : 'Unavailable',
			'R2 configuration status'     => $settings_ready ? 'Configured' : 'Incomplete',
			'Credential source'           => $this->credentials->source(),
			'Latest connection test'      => ! is_array( $last ) ? 'Not run' : ( ! empty( $last['stale'] ) ? 'Stale — run Test Connection again. The previous result is not current.' : ( new ConnectionStatus( $config, $this->credentials ) )->label( $last_status ) . ': ' . (string) $last['message'] ),
			'Connection test time'        => is_array( $last ) ? $this->format_tested_at( (string) $last['tested_at'] ) : 'Not available',
			'JIO plugin detection'        => $jio['active'] ? 'Active' . ( '' !== $jio['version'] ? ' (' . $jio['version'] . ')' : '' ) : ( '' !== $jio['version'] ? 'Installed but inactive' : 'Not active or not installed' ),
			'Local file retention policy' => 'Enabled permanently. Local media is never changed or removed.',
		);
		$states           = array(
			'AWS SDK availability'        => $sdk_ready ? 'success' : 'error',
			'R2 configuration status'     => $settings_ready ? 'success' : 'warning',
			'Credential source'           => $credential_ready ? 'success' : 'warning',
			'Latest connection test'      => is_array( $last ) && ! empty( $last['stale'] ) ? 'warning' : ( 'connected' === $last_status ? 'success' : ( in_array( $last_status, array( 'not_run', 'insufficient_permissions' ), true ) ? 'warning' : 'error' ) ),
			'Connection test time'        => 'neutral',
			'JIO plugin detection'        => $jio['active'] ? 'success' : 'warning',
			'Local file retention policy' => 'success',
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Jocky Media Offloader Diagnostics', 'jocky-media-offloader' ) . '</h1><table class="widefat striped"><tbody>';
		foreach ( $values as $label => $value ) {
			$state = $states[ $label ] ?? 'neutral';
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><span class="jmo-status jmo-status--' . esc_attr( $state ) . '">' . esc_html( $value ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Format a saved UTC timestamp for the site's timezone.
	 *
	 * @param string $tested_at UTC ISO-8601 timestamp.
	 */
	private function format_tested_at( string $tested_at ): string {
		$timestamp = strtotime( $tested_at );
		return false === $timestamp ? __( 'Time unavailable', 'jocky-media-offloader' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
