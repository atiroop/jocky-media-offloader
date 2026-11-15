<?php
/**
 * Settings screen and explicit connection test action.
 *
 * @package Jocky\MediaOffloader\Admin
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Admin;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Storage\ConnectionResult;
use Jocky\MediaOffloader\Storage\ConnectionStatus;
use Jocky\MediaOffloader\Storage\StorageClientFactory;
use Throwable;

/** Renders settings and runs explicit connection tests. */
final class SettingsPage {
	/** Validated configuration service.
	 *
	 * @var Configuration
	 */
	private Configuration $configuration;

	/**
	 * Create the settings page controller.
	 *
	 * @param StorageClientFactory $factory Storage diagnostic client factory.
	 */
	public function __construct( private readonly StorageClientFactory $factory ) {
		$this->configuration = new Configuration();
	}

	/** Register the settings menu, option, and explicit test handler. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'wp_ajax_jmo_test_connection', array( $this, 'ajax_test_connection' ) );
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
		if ( false === strpos( $hook_suffix, 'jmo-settings' ) ) {
			return;
		}
		wp_enqueue_script( 'jmo-admin', plugins_url( 'assets/admin.js', JMO_FILE ), array(), JMO_VERSION, true );
		wp_localize_script(
			'jmo-admin',
			'jmoConnectionTest',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'strings' => array(
					'test'         => __( 'Test Connection', 'jocky-media-offloader' ),
					'testing'      => __( 'Testing Connection...', 'jocky-media-offloader' ),
					'checking'     => __( 'Checking Cloudflare R2 authentication and bucket access.', 'jocky-media-offloader' ),
					'success'      => __( 'Connection Successful', 'jocky-media-offloader' ),
					'failed'       => __( 'Connection Failed', 'jocky-media-offloader' ),
					'expired'      => __( 'Your session expired. Refresh this page and try the connection test again.', 'jocky-media-offloader' ),
					'unauthorized' => __( 'Your administrator access is no longer valid. Refresh the page and sign in again.', 'jocky-media-offloader' ),
					'unexpected'   => __( 'The server returned an unexpected response. Refresh the page and try again.', 'jocky-media-offloader' ),
					'network'      => __( 'Could not reach WordPress to complete the test. Check your network connection and try again.', 'jocky-media-offloader' ),
					'lastTested'   => __( 'Last tested:', 'jocky-media-offloader' ),
				),
			)
		);
	}

	/** Add the top-level menu and settings page. */
	public function menu(): void {
		add_menu_page( __( 'Jocky Media Offloader', 'jocky-media-offloader' ), __( 'Jocky Media Offloader', 'jocky-media-offloader' ), 'manage_options', 'jmo-settings', array( $this, 'render' ), 'dashicons-cloud', 81 );
		add_submenu_page( 'jmo-settings', __( 'Settings', 'jocky-media-offloader' ), __( 'Settings', 'jocky-media-offloader' ), 'manage_options', 'jmo-settings', array( $this, 'render' ) );
	}

	/** Register the Settings API option and fields. */
	public function settings(): void {
		register_setting(
			'jmo_settings_group',
			Configuration::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
		add_settings_section( 'jmo_r2', __( 'Cloudflare R2', 'jocky-media-offloader' ), '__return_false', 'jmo-settings' );
		foreach ( array(
			'account_id' => __( 'Cloudflare Account ID', 'jocky-media-offloader' ),
			'bucket'     => __( 'R2 Bucket Name', 'jocky-media-offloader' ),
			'cdn_domain' => __( 'CDN Custom Domain', 'jocky-media-offloader' ),
		) as $key => $label ) {
			add_settings_field(
				'jmo_' . $key,
				esc_html( $label ),
				array( $this, 'field' ),
				'jmo-settings',
				'jmo_r2',
				array(
					'key'   => $key,
					'label' => $label,
				)
			);
		}
	}

	/**
	 * Validate and sanitize settings submitted through the Settings API.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string,string> Sanitized settings.
	 */
	public function sanitize_settings( $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$errors = $this->configuration->validate( $input );
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $error ) {
				add_settings_error( Configuration::OPTION, 'invalid', esc_html( $error ), 'error' );
			}
			$existing = get_option( Configuration::OPTION, array() );
			return is_array( $existing ) ? $existing : array();
		}
		return $this->configuration->sanitize( $input );
	}

	/**
	 * Render one escaped text field.
	 *
	 * @param array<string,string> $args Field metadata.
	 */
	public function field( array $args ): void {
		$config = $this->configuration->all();
		$key    = $args['key'];
		printf( '<input class="regular-text" id="jmo-%1$s" name="%2$s[%1$s]" value="%3$s" %4$s />', esc_attr( $key ), esc_attr( Configuration::OPTION ), esc_attr( $config[ $key ] ), 'cdn_domain' === $key ? 'placeholder="cdn.example.com"' : '' );
	}

	/** Render the settings page for administrators. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access these settings.', 'jocky-media-offloader' ), '', array( 'response' => 403 ) );
		}
		$this->configuration->all();
		$credentials = new CredentialsProvider();
		$connection  = ( new ConnectionStatus( $this->configuration, $credentials ) )->current();
		$endpoint    = $this->configuration->endpoint();
		echo '<div class="wrap"><h1>' . esc_html__( 'Jocky Media Offloader', 'jocky-media-offloader' ) . '</h1>';
		settings_errors( Configuration::OPTION );
		echo '<form method="post" action="options.php">';
		settings_fields( 'jmo_settings_group' );
		do_settings_sections( 'jmo-settings' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'R2 Endpoint', 'jocky-media-offloader' ) . '</th><td><code>' . esc_html( '' === $endpoint ? 'Not configured' : $endpoint ) . '</code></td></tr><tr><th>' . esc_html__( 'R2 Region', 'jocky-media-offloader' ) . '</th><td><code>auto</code></td></tr><tr><th>' . esc_html__( 'Credentials Status', 'jocky-media-offloader' ) . '</th><td>' . esc_html( null !== $credentials->get() ? 'Ready (' . $credentials->source() . ')' : 'Not configured' ) . '</td></tr><tr><th>' . esc_html__( 'Keep Local Files', 'jocky-media-offloader' ) . '</th><td>' . esc_html__( 'Always enabled. JMO never moves or deletes local media.', 'jocky-media-offloader' ) . '</td></tr></table>';
		submit_button( __( 'Save Settings', 'jocky-media-offloader' ) );
		echo '</form><hr /><form id="jmo-test-connection-form" method="post"><input type="hidden" name="action" value="jmo_test_connection" />';
		wp_nonce_field( 'jmo_test_connection', 'nonce', false );
		echo '<button type="submit" class="button button-secondary" id="jmo-test-connection-button" aria-describedby="jmo-connection-feedback">' . esc_html__( 'Test Connection', 'jocky-media-offloader' ) . '</button></form>';
		$this->render_connection_feedback( $connection );
		echo '</div>';
	}

	/** Run the authenticated, read-only R2 diagnostic and return sanitized JSON. */
	public function ajax_test_connection(): void {
		$error = $this->authorize_ajax_connection_test();
		if ( null !== $error ) {
			wp_send_json_error(
				array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
				),
				403
			);
		}
		try {
			$result = $this->factory->create()->test_connection( $this->configuration );
		} catch ( Throwable ) {
			$result = new ConnectionResult( 'client_failure', 'The R2 client could not complete the request.' );
		}
		$state = ( new ConnectionStatus( $this->configuration, new CredentialsProvider() ) )->record( $result );
		if ( 'connected' === $state['status'] ) {
			wp_send_json_success( $state );
		}
		wp_send_json_error( $state, 200 );
	}

	/** Validate capability and AJAX nonce without exposing WordPress's raw nonce error.
	 *
	 * @return \WP_Error|null Sanitized authorization failure, or null when authorized.
	 */
	public function authorize_ajax_connection_test(): ?\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to test storage connectivity.', 'jocky-media-offloader' ) );
		}
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'jmo_test_connection' ) ) {
			return new \WP_Error( 'invalid_nonce', __( 'Your session expired. Refresh this page and try the connection test again.', 'jocky-media-offloader' ) );
		}
		return null;
	}

	/** Render the latest result using native WordPress notice classes.
	 *
	 * @param array<string,mixed>|null $connection Current or stale result.
	 */
	private function render_connection_feedback( ?array $connection ): void {
		$class   = 'notice-info';
		$heading = __( 'Connection Not Tested', 'jocky-media-offloader' );
		$message = __( 'Run Test Connection to verify R2 authentication and bucket access.', 'jocky-media-offloader' );
		$tested  = '';
		$role    = 'status';
		if ( is_array( $connection ) ) {
			$tested = $this->format_tested_at( (string) $connection['tested_at'] );
			if ( ! empty( $connection['stale'] ) ) {
				$class   = 'notice-warning';
				$heading = __( 'Connection Result Stale', 'jocky-media-offloader' );
				$message = __( 'The account, bucket, or credential identity changed after this test. Run Test Connection again before relying on the result.', 'jocky-media-offloader' );
			} elseif ( 'connected' === $connection['status'] ) {
				$class   = 'notice-success';
				$heading = __( 'Connection Successful', 'jocky-media-offloader' );
				$message = ( new ConnectionStatus( $this->configuration, new CredentialsProvider() ) )->message( 'connected' );
			} else {
				$class   = 'notice-error';
				$heading = __( 'Connection Failed', 'jocky-media-offloader' );
				$message = ( new ConnectionStatus( $this->configuration, new CredentialsProvider() ) )->message( (string) $connection['status'] );
				$role    = 'alert';
			}
		}
		echo '<div id="jmo-connection-feedback" class="notice ' . esc_attr( $class ) . ' inline" role="' . esc_attr( $role ) . '" aria-live="polite" aria-atomic="true"><p><strong>' . esc_html( $heading ) . '</strong><br /><span class="jmo-connection-message">' . esc_html( $message ) . '</span>';
		if ( '' !== $tested ) {
			echo '<br /><span class="description">' . esc_html__( 'Last tested:', 'jocky-media-offloader' ) . ' ' . esc_html( $tested ) . '</span>';
		}
		echo '</p></div>';
	}

	/** Format the saved UTC time in the site's configured timezone.
	 *
	 * @param string $tested_at UTC ISO-8601 timestamp.
	 */
	private function format_tested_at( string $tested_at ): string {
		$timestamp = strtotime( $tested_at );
		if ( false === $timestamp ) {
			return __( 'Time unavailable', 'jocky-media-offloader' );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
