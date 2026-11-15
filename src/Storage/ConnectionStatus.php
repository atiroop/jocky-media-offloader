<?php
/**
 * Persists and validates the latest administrator-run connection result.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;

/** Prevents an old success from being shown as current after configuration changes. */
final class ConnectionStatus {
	public const OPTION = 'jmo_last_connection_test';

	private const STATUSES = array(
		'connected',
		'invalid_configuration',
		'missing_credentials',
		'authentication_failure',
		'insufficient_permissions',
		'bucket_not_found',
		'network_failure',
		'connection_failure',
		'unexpected_response',
		'client_failure',
	);

	/** Create status tracking for the current R2 settings and credentials.
	 *
	 * @param Configuration       $configuration Validated account and bucket settings.
	 * @param CredentialsProvider $credentials Secure credential source.
	 */
	public function __construct( private readonly Configuration $configuration, private readonly CredentialsProvider $credentials ) {}

	/** Save only sanitized result data and keyed identity fingerprints.
	 *
	 * @param ConnectionResult $result Completed diagnostic result.
	 * @return array<string,mixed> Current presentation-safe status.
	 */
	public function record( ConnectionResult $result ): array {
		$data = $result->to_array();
		unset( $data['message'] );
		$data['settings_fingerprint']   = $this->settings_fingerprint();
		$data['credential_fingerprint'] = $this->credentials->identity_fingerprint();
		update_option( self::OPTION, $data, false );
		return $this->current() ?? array();
	}

	/** Get the latest result and mark it stale when settings or credentials differ.
	 *
	 * @return array<string,mixed>|null Current presentation-safe status.
	 */
	public function current(): ?array {
		$stored = get_option( self::OPTION, false );
		$legacy = false;
		if ( ! is_array( $stored ) ) {
			$stored = get_transient( self::OPTION );
			$legacy = is_array( $stored );
		}
		if ( ! is_array( $stored ) || ! in_array( $stored['status'] ?? '', self::STATUSES, true ) ) {
			return null;
		}
		$settings_fingerprint   = $this->settings_fingerprint();
		$credential_fingerprint = $this->credentials->identity_fingerprint();
		$has_fingerprints       = is_string( $stored['settings_fingerprint'] ?? null ) && is_string( $stored['credential_fingerprint'] ?? null ) && '' !== $stored['settings_fingerprint'] && '' !== $stored['credential_fingerprint'];
		$stale                  = $legacy || ! $has_fingerprints || null === $credential_fingerprint;
		if ( ! $stale ) {
			$stale = ! hash_equals( $stored['settings_fingerprint'], $settings_fingerprint ) || ! hash_equals( $stored['credential_fingerprint'], $credential_fingerprint );
		}
		$tested_at = is_string( $stored['tested_at'] ?? null ) ? $stored['tested_at'] : '';
		return array(
			'status'        => (string) $stored['status'],
			'message'       => $this->message( (string) $stored['status'] ),
			'bucket_access' => 'connected' === $stored['status'] && ! $stale,
			'tested_at'     => $tested_at,
			'stale'         => $stale,
		);
	}

	/** Build a safe, actionable message from a known result code.
	 *
	 * @param string $status Known connection status.
	 */
	public function message( string $status ): string {
		$bucket   = $this->configuration->all()['bucket'];
		$messages = array(
			// translators: %s: configured Cloudflare R2 bucket name.
			'connected'                => sprintf( __( 'Cloudflare R2 authentication succeeded. Bucket %s is accessible.', 'jocky-media-offloader' ), $bucket ),
			'invalid_configuration'    => __( 'The account ID or bucket setting is invalid. Check both values and save the settings.', 'jocky-media-offloader' ),
			'missing_credentials'      => __( 'R2 credentials are missing or incomplete. Configure both the Access Key ID and Secret Access Key in wp-config.php or the server environment.', 'jocky-media-offloader' ),
			'authentication_failure'   => __( 'R2 rejected the credentials. Verify the Access Key ID and Secret Access Key, then test again.', 'jocky-media-offloader' ),
			'insufficient_permissions' => __( 'The credentials could not inspect this bucket. Grant the R2 token permission to list or inspect the configured bucket.', 'jocky-media-offloader' ),
			'bucket_not_found'         => __( 'The bucket was not found in this Cloudflare account. Check the account ID and bucket name.', 'jocky-media-offloader' ),
			'network_failure'          => __( 'The request to Cloudflare R2 timed out. Check outbound HTTPS access and DNS, then try again.', 'jocky-media-offloader' ),
			'connection_failure'       => __( 'R2 could not confirm bucket access. Check the account ID, bucket name, credentials, and server network access.', 'jocky-media-offloader' ),
			'unexpected_response'      => __( 'R2 returned an unexpected response. Check server compatibility and try again.', 'jocky-media-offloader' ),
			'client_failure'           => __( 'The R2 client could not complete the request. Check that the plugin package and server PHP requirements are met.', 'jocky-media-offloader' ),
		);
		return $messages[ $status ] ?? __( 'The connection test could not be completed. Refresh the page and try again.', 'jocky-media-offloader' );
	}

	/** Return a short localized display label for a known result code.
	 *
	 * @param string $status Known connection status.
	 */
	public function label( string $status ): string {
		return 'connected' === $status ? __( 'Connected', 'jocky-media-offloader' ) : __( 'Connection Failed', 'jocky-media-offloader' );
	}

	/** Fingerprint only the settings used by the bucket access test. */
	private function settings_fingerprint(): string {
		$config = $this->configuration->all();
		return hash( 'sha256', (string) wp_json_encode( array( $config['account_id'], $config['bucket'], $this->configuration->endpoint() ) ) );
	}
}
