<?php
/**
 * Read-only Cloudflare R2 diagnostics using the S3-compatible API.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\Result;
use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Closure;
use Throwable;

	/** Implements the read-only R2 diagnostic request. */
final class R2StorageClient implements StorageClientInterface {
	/**
	 * Create the client adapter.
	 *
	 * @param CredentialsProvider                      $credentials     Credential resolver.
	 * @param Closure(array<string,mixed>):object|null $client_factory Test seam; production always uses the AWS SDK.
	 */
	public function __construct( private readonly CredentialsProvider $credentials, private readonly ?Closure $client_factory = null ) {}

	/**
	 * Test authenticated access to the configured bucket using HeadBucket.
	 *
	 * @param Configuration $configuration Validated non-secret settings.
	 * @return ConnectionResult Sanitized connection result.
	 */
	public function test_connection( Configuration $configuration ): ConnectionResult {
		$config = $configuration->all();
		if ( '' === $config['account_id'] ) {
			return new ConnectionResult( 'invalid_configuration', 'Enter a valid Cloudflare account ID.' );
		}
		if ( '' === $config['bucket'] ) {
			return new ConnectionResult( 'invalid_configuration', 'Enter a valid bucket name.' );
		}
		$credentials = $this->credentials->get();
		if ( null === $credentials ) {
			return new ConnectionResult( 'missing_credentials', 'Configure both R2 access key values in wp-config.php or the environment.' );
		}
		try {
			$options  = array(
				'version'                 => 'latest',
				'region'                  => 'auto',
				'endpoint'                => $configuration->endpoint(),
				'use_path_style_endpoint' => true,
				'credentials'             => array(
					'key'    => $credentials['access_key'],
					'secret' => $credentials['secret_key'],
				),
				'http'                    => array(
					'connect_timeout' => 5,
					'timeout'         => 10,
					'allow_redirects' => false,
				),
			);
			$client   = null === $this->client_factory ? new S3Client( $options ) : ( $this->client_factory )( $options );
			$response = $client->headBucket( array( 'Bucket' => $config['bucket'] ) );
			if ( ! $response instanceof Result || 200 !== (int) ( $response['@metadata']['statusCode'] ?? 0 ) ) {
				return new ConnectionResult( 'unexpected_response', 'Cloudflare R2 returned an unexpected diagnostic response.' );
			}
			return new ConnectionResult( 'connected', 'R2 authenticated successfully and the configured bucket is accessible.', true );
		} catch ( AwsException $exception ) {
			$code   = (string) $exception->getAwsErrorCode();
			$status = (int) ( $exception->getStatusCode() ?? 0 );
			if ( in_array( $code, array( 'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'ExpiredToken', 'InvalidToken' ), true ) || in_array( $status, array( 401 ), true ) ) {
				return new ConnectionResult( 'authentication_failure', 'R2 rejected the configured credentials.' );
			}
			if ( in_array( $code, array( 'AccessDenied', 'AllAccessDisabled' ), true ) || 403 === $status ) {
				return new ConnectionResult( 'insufficient_permissions', 'Credentials were rejected for bucket access or lack permission to inspect this bucket.' );
			}
			if ( in_array( $code, array( 'NoSuchBucket', 'NotFound' ), true ) || 404 === $status ) {
				return new ConnectionResult( 'bucket_not_found', 'The configured bucket was not found for this account.' );
			}
			if ( 0 === $status && ( false !== stripos( $exception->getMessage(), 'timed out' ) || false !== stripos( $exception->getMessage(), 'cURL error 28' ) ) ) {
				return new ConnectionResult( 'network_failure', 'The connection to Cloudflare R2 timed out.' );
			}
			return new ConnectionResult( 'connection_failure', 'Cloudflare R2 could not confirm bucket access. Check the account, endpoint, credentials, and network.' );
		} catch ( Throwable $exception ) {
			return new ConnectionResult( 'client_failure', 'The R2 client could not complete the diagnostic request.' );
		}
	}
}
