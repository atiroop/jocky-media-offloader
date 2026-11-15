<?php
/**
 * Sanitized explanations for stored transfer error codes.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

/** Converts fixed queue error codes into actionable admin messages. */
final class TransferDiagnostics {
	/** Return a safe explanation without using server response text.
	 *
	 * @param string $code Persisted safe diagnostic code.
	 */
	public function message( string $code ): string {
		if ( 1 === preg_match( '/\Ar2d_(head|put|multi|create|parts|part|complete|abort)_(perm|auth|checksum|timeout|skew|temporary|unsupported|rejected|service|network|api)_([0-9]{3})_(AccessDenied|Unauthorized|InvalidAccessKeyId|SignatureDoesNotMatch|ExpiredToken|ExpiredRequest|BadDigest|InvalidDigest|RequestTimeout|RequestTimeTooSkewed|SlowDown|TooManyRequests|NotImplemented|NotSupported|InvalidRequest|InvalidArgument|InvalidBucketName|InternalError|ServiceUnavailable|NoSuchBucket|NoSuchKey|NotFound|Unknown)\z/', $code, $matches ) ) {
			$operations = array(
				'head'     => 'head_object',
				'put'      => 'put_object',
				'multi'    => 'multipart_transfer',
				'create'   => 'create_multipart_upload',
				'parts'    => 'list_parts',
				'part'     => 'upload_part',
				'complete' => 'complete_multipart_upload',
				'abort'    => 'abort_multipart_upload',
			);
			$categories = array(
				'perm'        => 'permission_denied',
				'auth'        => 'authentication_failed',
				'checksum'    => 'checksum_rejected',
				'timeout'     => 'timeout',
				'skew'        => 'clock_skew',
				'temporary'   => 'temporary_failure',
				'unsupported' => 'unsupported_request',
				'rejected'    => 'request_rejected',
				'service'     => 'service_failure',
				'network'     => 'network_failure',
				'api'         => 'api_failure',
			);
			$base       = $this->message( 'r2_' . $operations[ $matches[1] ] . '_' . $categories[ $matches[2] ] );
			$status     = '000' === $matches[3] ? __( 'not available', 'jocky-media-offloader' ) : $matches[3];
			$s3_code    = 'Unknown' === $matches[4] ? __( 'not provided or unrecognized', 'jocky-media-offloader' ) : $matches[4];
			// translators: 1: diagnostic classification, 2: API operation, 3: HTTP status or not available, 4: allowlisted S3 error code.
			return sprintf( __( '%1$s Operation: %2$s. HTTP status: %3$s. R2 error code: %4$s.', 'jocky-media-offloader' ), $base, $this->operation_label( $matches[1] ), $status, $s3_code );
		}
		$operations = array(
			'head_object'               => 'HeadObject',
			'put_object'                => 'PutObject',
			'multipart_transfer'        => 'Multipart transfer',
			'create_multipart_upload'   => 'CreateMultipartUpload',
			'list_parts'                => 'ListParts',
			'upload_part'               => 'UploadPart',
			'complete_multipart_upload' => 'CompleteMultipartUpload',
			'abort_multipart_upload'    => 'AbortMultipartUpload',
		);
		$categories = array( 'permission_denied', 'authentication_failed', 'checksum_rejected', 'timeout', 'clock_skew', 'network_failure', 'temporary_failure', 'service_failure', 'unsupported_request', 'request_rejected', 'client_failure', 'api_failure' );
		foreach ( $operations as $operation_key => $operation_name ) {
			foreach ( $categories as $category_name ) {
				if ( 'r2_' . $operation_key . '_' . $category_name === $code ) {
					$operation = $operation_name;
					$category  = $category_name;
					break 2;
				}
			}
		}
		if ( isset( $operation, $category ) ) {
			if ( 'permission_denied' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( 'R2 denied %s. Grant the token Object Read & Write access to the configured bucket.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'authentication_failed' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( 'R2 rejected authentication for %s. Check the active R2 key pair and server clock.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'clock_skew' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( 'R2 rejected %s because the request time is outside its allowed window. Synchronize the server clock.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'checksum_rejected' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( 'R2 rejected the checksum for %s. Check the request checksum settings and source file integrity.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'timeout' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( '%s timed out while contacting R2. Check outbound HTTPS, DNS, and server network load.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'network_failure' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( '%s could not reach R2. Check outbound HTTPS, DNS, TLS, and firewall rules.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'temporary_failure' === $category || 'service_failure' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( 'R2 could not complete %s temporarily. Check Cloudflare service status and retry after the delay.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'unsupported_request' === $category || 'request_rejected' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( 'R2 rejected the %s request. Check API compatibility, conditional headers, checksums, and bucket configuration.', 'jocky-media-offloader' ), $operation );
			}
			if ( 'client_failure' === $category ) {
				// translators: %s: S3 API operation name, such as PutObject.
				return sprintf( __( 'The server client failed while preparing %s. Check the PHP runtime and plugin package.', 'jocky-media-offloader' ), $operation );
			}
			// translators: %s: S3 API operation name, such as PutObject.
			return sprintf( __( '%s failed with an unclassified R2 API response. Check bucket permissions and server compatibility.', 'jocky-media-offloader' ), $operation );
		}

		$legacy = array(
			'r2_transfer_failure'  => __( 'An older result did not record which R2 API operation failed. Check Object Read & Write permissions and request checksum compatibility.', 'jocky-media-offloader' ),
			'r2_access_denied'     => __( 'R2 denied an object operation. Grant Object Read & Write access to the configured bucket.', 'jocky-media-offloader' ),
			'r2_network_failure'   => __( 'The server could not reach R2. Check outbound HTTPS, DNS, TLS, and firewall rules.', 'jocky-media-offloader' ),
			'r2_temporary_failure' => __( 'R2 reported a temporary failure. Check Cloudflare service status and retry after the delay.', 'jocky-media-offloader' ),
			'worker_failure'       => __( 'The queue worker encountered an internal error. Check the source file and server PHP logs.', 'jocky-media-offloader' ),
			'lease_expired'        => __( 'The worker lease expired before the step completed. Check server load and request timeouts.', 'jocky-media-offloader' ),
			'source_unavailable'   => __( 'A local source file is missing or unreadable. Restore the file and rebuild the manifest.', 'jocky-media-offloader' ),
			'source_changed'       => __( 'A local source file changed after the job was queued. Rebuild the manifest before retrying.', 'jocky-media-offloader' ),
			'invalid_source'       => __( 'The local source file failed validation. Review the file path and manifest before retrying.', 'jocky-media-offloader' ),
		);
		return $legacy[ $code ] ?? __( 'The queue step failed. Review the job state and local source file before retrying.', 'jocky-media-offloader' );
	}

	/** Resolve a compact persisted API operation alias to its display name.
	 *
	 * @param string $alias Persisted safe alias.
	 */
	private function operation_label( string $alias ): string {
		return array(
			'head'     => 'HeadObject',
			'put'      => 'PutObject',
			'multi'    => 'Multipart transfer',
			'create'   => 'CreateMultipartUpload',
			'parts'    => 'ListParts',
			'part'     => 'UploadPart',
			'complete' => 'CompleteMultipartUpload',
			'abort'    => 'AbortMultipartUpload',
		)[ $alias ] ?? 'R2 API';
	}
}
