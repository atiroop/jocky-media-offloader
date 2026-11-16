<?php
/**
 * Read-only public CDN readiness check against a currently verified object.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Queue\QueueSchema;
use Jocky\MediaOffloader\Integration\JioDetector;
use Jocky\MediaOffloader\Integration\JioReadiness;
use Closure;
use Throwable;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Bounded diagnostic lookup against trusted JMO table names.

/** Tests R2 authentication and one public CDN object without mutating storage. */
final class CdnReadiness {
	public const OPTION = 'jmo_cdn_readiness';

	/** Validated configuration provider.
	 *
	 * @var Configuration
	 */
	private Configuration $configuration;

	/** R2 credential provider.
	 *
	 * @var CredentialsProvider
	 */
	private CredentialsProvider $credentials;

	/**
	 * Create the read-only readiness checker with optional test seams.
	 *
	 * @param Configuration|null       $configuration Validated settings.
	 * @param CredentialsProvider|null $credentials   Secret resolver.
	 * @param Closure|null             $connection_test R2 connection diagnostic seam.
	 * @param Closure|null             $object_provider Verified test-object provider seam.
	 * @param Closure|null             $head_request Public HEAD request seam.
	 */
	public function __construct( ?Configuration $configuration = null, ?CredentialsProvider $credentials = null, private readonly ?Closure $connection_test = null, private readonly ?Closure $object_provider = null, private readonly ?Closure $head_request = null ) {
		$this->configuration = $configuration ?? new Configuration();
		$this->credentials   = $credentials ?? new CredentialsProvider();
	}

	/** Run the bounded, read-only test and save only sanitized result data. */
	public function test(): array {
		$config = $this->configuration->all();
		if ( '' === $config['cdn_domain'] ) {
			return $this->record( 'incomplete_configuration', 'Set a valid CDN hostname before testing public delivery.' );
		}
		try {
			$r2 = null === $this->connection_test ? ( new StorageClientFactory( $this->credentials ) )->create()->test_connection( $this->configuration ) : ( $this->connection_test )();
		} catch ( Throwable ) {
			return $this->record( 'r2_failure', 'JMO could not confirm R2 authentication and bucket access. Review R2 settings and credentials.' );
		}
		if ( ! $r2 instanceof ConnectionResult ) {
			return $this->record( 'r2_failure', 'JMO received an unexpected R2 diagnostic response.' );
		}
		if ( ! $r2->bucket_access ) {
			return $this->record( 'r2_' . sanitize_key( $r2->status ), $r2->message );
		}
		$object = $this->latest_verified_object();
		if ( null === $object ) {
			return $this->record( 'no_verified_object', 'R2 bucket access succeeded, but no fully verified object is available for a public CDN test.' );
		}
		$url      = 'https://' . $config['cdn_domain'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', (string) $object['object_key'] ) ) );
		$request  = $this->head_request ?? static fn ( string $request_url, array $args ) => wp_safe_remote_head( $request_url, $args );
		$response = $request(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->record( 'dns_tls_or_network_failure', 'The public CDN host could not be reached or its HTTPS certificate could not be verified. Check DNS, domain activation, TLS, and outbound network access.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 403 === $status ) {
			return $this->record( 'public_forbidden', 'The object is private or the CDN domain denies public access. Check bucket custom-domain and access settings.' );
		}
		if ( 404 === $status ) {
			return $this->record( 'public_not_found', 'The CDN domain could not find the verified object key. Check custom-domain routing and bucket binding.' );
		}
		if ( 200 !== $status ) {
			return $this->record( 'public_http_failure', 'The CDN returned an unexpected HTTP status. Check custom-domain routing and origin availability.' );
		}
		$headers = wp_remote_retrieve_headers( $response );
		$length  = isset( $headers['content-length'] ) ? (int) $headers['content-length'] : -1;
		$mime    = isset( $headers['content-type'] ) ? strtolower( trim( explode( ';', (string) $headers['content-type'] )[0] ) ) : '';
		if ( $length !== (int) $object['size_bytes'] ) {
			return $this->record( 'content_length_mismatch', 'The public response length does not match the verified object. Check CDN cache and object metadata.' );
		}
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both sides are normalized dynamic values.
		if ( $mime !== strtolower( (string) $object['mime_type'] ) ) {
			return $this->record( 'mime_mismatch', 'The public response Content-Type does not match the verified object. Correct R2 metadata or CDN response headers.' );
		}
		return $this->record( 'ready', 'R2 authentication and bucket access succeeded. A verified object is publicly retrievable over HTTPS with matching MIME type and content length.' );
	}

	/** Return the saved result and whether its tested configuration/object is current. */
	public function current(): ?array {
		$saved = get_option( self::OPTION, false );
		if ( ! is_array( $saved ) || empty( $saved['fingerprint'] ) ) {
			return null;
		}
		$saved['stale'] = ! hash_equals( (string) $saved['fingerprint'], $this->fingerprint() );
		return $saved;
	}

	/** Store a sanitized status and invalidate it when the tested identity changes.
	 *
	 * @param string $status Safe result classification.
	 * @param string $message Safe administrator guidance.
	 * @return array<string, mixed> Persisted result.
	 */
	private function record( string $status, string $message ): array {
		$result = array(
			'status'      => sanitize_key( $status ),
			'message'     => sanitize_text_field( $message ),
			'tested_at'   => gmdate( 'c' ),
			'fingerprint' => $this->fingerprint(),
			'stale'       => false,
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/** Find one persisted verified object from a fully verified job. */
	private function latest_verified_object(): ?array {
		if ( null !== $this->object_provider ) {
			$object = ( $this->object_provider )();
			return is_array( $object ) ? $object : null;
		}
		global $wpdb;
		$rows    = $wpdb->get_results(
			' SELECT j.attachment_id,j.manifest_json,j.manifest_hash,o.object_index,o.object_key,o.relative_path,o.size_bytes,o.mime_type,o.sha256,o.file_identity FROM ' . QueueSchema::objects_table() . ' o INNER JOIN ' . QueueSchema::jobs_table() . " j ON j.id = o.job_id WHERE j.status = 'verified' AND o.status = 'verified' ORDER BY j.id DESC,o.object_index ASC LIMIT 50",
			ARRAY_A
		);
		$uploads = wp_upload_dir();
		$root    = realpath( (string) ( $uploads['basedir'] ?? '' ) );
		if ( false === $root ) {
			return null;
		}
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$manifest         = json_decode( (string) $row['manifest_json'], true );
			$file             = is_array( $manifest ) ? ( $manifest['files'][ (int) $row['object_index'] ] ?? null ) : null;
			$attachment_id    = (int) $row['attachment_id'];
			$current_metadata = wp_get_attachment_metadata( $attachment_id, true );
			$current_metadata = is_array( $current_metadata ) ? $current_metadata : array();
			$current          = hash( 'sha256', (string) wp_json_encode( array( get_post_meta( $attachment_id, '_wp_attached_file', true ), $current_metadata, get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) ) ) );
			if ( ! is_array( $file ) || ! isset( $manifest['attachment_fingerprint'] ) || ! hash_equals( (string) $row['manifest_hash'], (string) ( $manifest['hash'] ?? '' ) ) || ! hash_equals( (string) $manifest['attachment_fingerprint'], $current ) || (string) $file['object_key'] !== (string) $row['object_key'] || (string) $file['relative_path'] !== (string) $row['relative_path'] || (int) $file['size'] !== (int) $row['size_bytes'] || (string) $file['mime'] !== (string) $row['mime_type'] || (string) $file['sha256'] !== (string) $row['sha256'] ) {
				continue;
			}
			$jio = ( new JioDetector() )->detect();
			if ( $jio['active'] && version_compare( $jio['version'], '0.6.2', '>=' ) && in_array( (string) get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
				$state = ( new JioReadiness() )->state( $attachment_id );
				if ( ! is_array( $state ) || ! in_array( (string) $state['outcome'], array( 'completed', 'skipped' ), true ) || ! isset( $manifest['jio_generation'], $manifest['jio_revision'], $manifest['jio_outcome'] ) || ! hash_equals( (string) $manifest['jio_generation'], (string) $state['generation'] ) || ! hash_equals( (string) $manifest['jio_revision'], (string) $state['revision'] ) || ! hash_equals( (string) $manifest['jio_outcome'], (string) $state['outcome'] ) ) {
					continue;
				}
			}
			$path     = realpath( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) $row['relative_path'] ) );
			$stat     = false !== $path ? stat( $path ) : false;
			$identity = is_array( $stat ) ? implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'], (int) $stat['ctime'] ) ) : '';
			if ( false === $path || ! str_starts_with( wp_normalize_path( $path ), rtrim( wp_normalize_path( $root ), '/' ) . '/' ) || ! is_file( $path ) || ! hash_equals( (string) $row['file_identity'], $identity ) ) {
				continue;
			}
			return array(
				'object_key' => (string) $row['object_key'],
				'size_bytes' => (int) $row['size_bytes'],
				'mime_type'  => (string) $row['mime_type'],
			);
		}
		return null;
	}

	/** Fingerprint only public settings and the selected object identity. */
	private function fingerprint(): string {
		$config = $this->configuration->all();
		$object = $this->latest_verified_object();
		return hash( 'sha256', (string) wp_json_encode( array( $config['account_id'], $config['bucket'], $config['cdn_domain'], $this->credentials->identity_fingerprint(), is_array( $object ) ? array( hash( 'sha256', (string) $object['object_key'] ), (int) $object['size_bytes'], (string) $object['mime_type'] ) : null ) ) );
	}
}
