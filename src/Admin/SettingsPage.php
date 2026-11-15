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
use Jocky\MediaOffloader\Storage\StorageClientFactory;

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
		add_action( 'admin_post_jmo_test_connection', array( $this, 'test_connection' ) );
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
		$endpoint    = $this->configuration->endpoint();
		echo '<div class="wrap"><h1>' . esc_html__( 'Jocky Media Offloader', 'jocky-media-offloader' ) . '</h1>';
		settings_errors( Configuration::OPTION );
		echo '<form method="post" action="options.php">';
		settings_fields( 'jmo_settings_group' );
		do_settings_sections( 'jmo-settings' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'R2 Endpoint', 'jocky-media-offloader' ) . '</th><td><code>' . esc_html( '' === $endpoint ? 'Not configured' : $endpoint ) . '</code></td></tr><tr><th>' . esc_html__( 'R2 Region', 'jocky-media-offloader' ) . '</th><td><code>auto</code></td></tr><tr><th>' . esc_html__( 'Credentials Status', 'jocky-media-offloader' ) . '</th><td>' . esc_html( null !== $credentials->get() ? 'Ready (' . $credentials->source() . ')' : 'Not configured' ) . '</td></tr><tr><th>' . esc_html__( 'Keep Local Files', 'jocky-media-offloader' ) . '</th><td>' . esc_html__( 'Always enabled. JMO never moves or deletes local media.', 'jocky-media-offloader' ) . '</td></tr></table>';
		submit_button( __( 'Save Settings', 'jocky-media-offloader' ) );
		echo '</form><hr /><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jmo_test_connection" />';
		wp_nonce_field( 'jmo_test_connection' );
		submit_button( __( 'Test Connection', 'jocky-media-offloader' ), 'secondary' );
		echo '</form></div>';
	}

	/** Run the administrator-triggered read-only connection diagnostic. */
	public function test_connection(): void {
		$this->authorize_connection_test();
		$result = $this->factory->create()->test_connection( $this->configuration );
		set_transient( 'jmo_last_connection_test', $result->to_array(), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'jmo-settings',
					'jmo_test' => $result->status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Enforce capability and nonce checks for the diagnostic action. */
	public function authorize_connection_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to test storage connectivity.', 'jocky-media-offloader' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'jmo_test_connection' );
	}
}
