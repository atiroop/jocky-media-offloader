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
use Jocky\MediaOffloader\Storage\CdnReadiness;

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
		$cdn_readiness    = ( new CdnReadiness( $config, $this->credentials ) )->current();
		$last_status      = is_array( $last ) ? (string) ( $last['status'] ?? 'unknown' ) : 'not_run';
		$credential_ready = null !== $this->credentials->get();
		$settings_ready   = '' !== $settings['account_id'] && '' !== $settings['bucket'];
		$sdk_ready        = class_exists( \Aws\S3\S3Client::class );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Trusted queue table identifier and fixed aggregate query.
		$verified_objects = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Jocky\MediaOffloader\Queue\QueueSchema::objects_table() . " WHERE status = 'verified'" );
		$cdn_enabled      = (bool) get_option( 'jmo_cdn_delivery', false );
		$elementor_active = class_exists( '\\Elementor\\Plugin' );
		$elementor_cache  = $elementor_active && 'disable' !== get_option( 'elementor_element_cache_ttl', '' );
		$jio_compatible   = $jio['active'] && version_compare( $jio['version'], '0.6.2', '>=' ) && class_exists( '\\Jocky\\ImageOptimizer\\Integration\\AttachmentReadiness' );
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
			'CDN Delivery'                => $cdn_enabled ? 'Enabled by administrator; per-resource verified mapping required.' : 'Disabled (default).',
			'CDN public readiness'        => 'Not tested by this release; do not enable delivery until public HTTPS, status, MIME, and length are verified.',
			'Verified objects'            => (string) $verified_objects,
			'JIO completion integration'  => $jio_compatible ? 'Supported (contract v1).' : ( $jio['active'] ? 'Manual confirmation required; install JIO 0.6.2 or newer for terminal-state integration.' : 'Not active; JIO is optional.' ),
			'Elementor compatibility'     => ! $elementor_active ? 'Not installed; core attachment APIs remain supported.' : ( $elementor_cache ? 'Image and Image Box rewrite may be bypassed by active element caching; clear Elementor cache. Background CSS is unsupported.' : 'Image and Image Box supported through final widget HTML. Background CSS is unsupported.' ),
			'WP Rocket cache'             => function_exists( 'rocket_clean_domain' ) ? 'Active; purge affected cached pages manually after URL changes.' : 'Not detected; purge other page, edge, and browser caches manually.',
			'CDN readiness test'          => ! is_array( $cdn_readiness ) ? 'Not run.' : ( ! empty( $cdn_readiness['stale'] ) ? 'Stale — run the CDN Readiness Test again.' : ( 'ready' === $cdn_readiness['status'] ? 'Passed: ' : 'Failed: ' ) . (string) $cdn_readiness['message'] ),
		);
		$states           = array(
			'AWS SDK availability'        => $sdk_ready ? 'success' : 'error',
			'R2 configuration status'     => $settings_ready ? 'success' : 'warning',
			'Credential source'           => $credential_ready ? 'success' : 'warning',
			'Latest connection test'      => is_array( $last ) && ! empty( $last['stale'] ) ? 'warning' : ( 'connected' === $last_status ? 'success' : ( in_array( $last_status, array( 'not_run', 'insufficient_permissions' ), true ) ? 'warning' : 'error' ) ),
			'Connection test time'        => 'neutral',
			'JIO plugin detection'        => $jio['active'] ? 'success' : 'warning',
			'Local file retention policy' => 'success',
			'CDN Delivery'                => $cdn_enabled ? 'warning' : 'neutral',
			'CDN public readiness'        => 'warning',
			'Verified objects'            => 'neutral',
			'JIO completion integration'  => $jio_compatible ? 'success' : 'warning',
			'Elementor compatibility'     => $elementor_cache ? 'warning' : 'neutral',
			'WP Rocket cache'             => 'neutral',
			'CDN readiness test'          => is_array( $cdn_readiness ) && empty( $cdn_readiness['stale'] ) && 'ready' === $cdn_readiness['status'] ? 'success' : ( is_array( $cdn_readiness ) && empty( $cdn_readiness['stale'] ) ? 'error' : 'warning' ),
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
