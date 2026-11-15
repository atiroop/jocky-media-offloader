<?php
/**
 * Conditional, streaming R2 object upload and remote verification.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use Aws\Exception\AwsException;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Large uploads must stream through PHP resources; WordPress_Filesystem's in-memory APIs cannot safely transfer multipart objects.

/** Transfers one manifest file without overwriting an existing object. */
final class R2ObjectTransfer {
	public const MULTIPART_THRESHOLD = 33554432;
	public const PART_SIZE           = 8388608;
	public const MAX_PARTS_PER_CALL  = 1;
	public const MAX_FILE_SIZE       = 5497558138880;

	/**
	 * Transfer one validated source file without buffering the full file.
	 *
	 * @param object        $client S3-compatible client.
	 * @param array         $manifest_object Manifest object row.
	 * @param string        $path   Local file path.
	 * @param string        $bucket R2 bucket.
	 * @param array         $resume Persisted multipart state.
	 * @param callable|null $save_state Persist multipart progress callback.
	 * @return array{complete:bool,etag:string,sha256:string,size:int} Bounded transfer progress.
	 * @throws TransferFailure On a source, transfer, collision or verification error.
	 */
	public function transfer( object $client, array $manifest_object, string $path, string $bucket, array $resume = array(), ?callable $save_state = null ): array {
		$size = (int) ( $manifest_object['size_bytes'] ?? $manifest_object['size'] ?? -1 );
		$key  = (string) ( $manifest_object['object_key'] ?? '' );
		$sha  = (string) ( $manifest_object['sha256'] ?? '' );
		$mime = (string) ( $manifest_object['mime_type'] ?? $manifest_object['mime'] ?? 'application/octet-stream' );
		if ( $size < 0 || $size > self::MAX_FILE_SIZE || '' === $key || strlen( $key ) > 1024 || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $sha ) || ! is_file( $path ) || is_link( $path ) ) {
			throw new TransferFailure( 'invalid_source' );
		}
		$this->assert_identity( $manifest_object, $path, $size );
		$existing = $this->head_if_present( $client, $bucket, $key );
		if ( null !== $existing ) {
			return $this->verify_result( $existing, $size, $sha ) + array( 'complete' => true );
		}
		try {
			if ( $size < $this->multipart_threshold() ) {
				$this->put_small( $client, $bucket, $key, $path, $size, $mime, $sha );
			} else {
				$complete = $this->put_multipart( $client, $bucket, $key, $path, $size, $mime, $sha, $manifest_object, $resume, $save_state );
				if ( ! $complete ) {
					return array(
						'complete' => false,
						'etag'     => '',
						'sha256'   => $sha,
						'size'     => $size,
					);
				}
			}
		} catch ( AwsException $exception ) {
			if ( $this->is_precondition_failure( $exception ) ) {
				$existing = $this->head_if_present( $client, $bucket, $key );
				if ( null !== $existing ) {
					return $this->verify_result( $existing, $size, $sha ) + array( 'complete' => true );
				}
				throw new TransferFailure( 'object_collision' );
			}
			$operation = $size < $this->multipart_threshold() ? 'put_object' : 'complete_multipart_upload';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception contains only a fixed internal diagnostic category.
			throw new TransferFailure( $this->safe_error_code( $exception, $operation ) );
		} catch ( TransferFailure $failure ) {
			throw $failure;
		} catch ( \Throwable ) {
			$operation = $size < $this->multipart_threshold() ? 'put_object' : 'multipart_transfer';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains a fixed operation and category code only.
			throw new TransferFailure( 'r2_' . $operation . '_client_failure' );
		}
		$remote = $this->head_if_present( $client, $bucket, $key );
		if ( null === $remote ) {
			throw new TransferFailure( 'remote_verification_failed' );
		}
		$result = $this->verify_result( $remote, $size, $sha );
		$this->assert_identity( $manifest_object, $path, $size );
		return $result + array( 'complete' => true );
	}

	/** Verify an uploaded object's size, digest metadata and optional full-object checksum.
	 *
	 * @param array  $head Remote HeadObject result.
	 * @param int    $size Expected size.
	 * @param string $sha  Expected SHA-256.
	 * @throws TransferFailure When remote size or digest does not match.
	 */
	public function verify_result( array $head, int $size, string $sha ): array {
		$remote_size = (int) ( $head['ContentLength'] ?? -1 );
		$metadata    = is_array( $head['Metadata'] ?? null ) ? $head['Metadata'] : array();
		$remote_sha  = (string) ( $metadata['jmo-sha256'] ?? $metadata['Jmo-Sha256'] ?? '' );
		if ( $size !== $remote_size ) {
			throw new TransferFailure( 'remote_size_mismatch' );
		}
		if ( '' === $remote_sha || ! hash_equals( $sha, strtolower( $remote_sha ) ) ) {
			throw new TransferFailure( 'remote_checksum_mismatch' );
		}
		// Convert the digest to the base64 representation required by the S3 checksum API.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- S3 ChecksumSHA256 is specified as base64-encoded digest bytes.
		$expected_checksum = base64_encode( hex2bin( $sha ) );
		if ( isset( $head['ChecksumSHA256'] ) && 'FULL_OBJECT' === strtoupper( (string) ( $head['ChecksumType'] ?? '' ) ) && ! hash_equals( $expected_checksum, (string) $head['ChecksumSHA256'] ) ) {
			throw new TransferFailure( 'remote_checksum_mismatch' );
		}
		return array(
			'etag'   => trim( (string) ( $head['ETag'] ?? '' ), '"' ),
			'sha256' => $sha,
			'size'   => $size,
		);
	}

	/** Verify a stored object in a separate worker phase.
	 *
	 * @param object $client S3-compatible client.
	 * @param string $bucket R2 bucket.
	 * @param array  $manifest_object Persisted object row.
	 * @throws TransferFailure On network, missing-object or mismatch errors.
	 */
	public function verify_remote( object $client, string $bucket, array $manifest_object ): array {
		$head = $this->head_if_present( $client, $bucket, (string) $manifest_object['object_key'] );
		if ( null === $head ) {
			throw new TransferFailure( 'remote_object_missing' );
		}
		return $this->verify_result( $head, (int) $manifest_object['size_bytes'], (string) $manifest_object['sha256'] );
	}

	/** Perform a create-only PutObject with a bounded stream.
	 *
	 * @param object $client S3-compatible client.
	 * @param string $bucket R2 bucket.
	 * @param string $key    Collision-resistant object key.
	 * @param string $path   Local source path.
	 * @param int    $size   Source size in bytes.
	 * @param string $mime   Detected content type.
	 * @param string $sha    SHA-256 digest.
	 * @throws AwsException    When a create-only precondition needs reconciliation.
	 * @throws TransferFailure When the source stream cannot be opened or an R2 response is rejected.
	 */
	private function put_small( object $client, string $bucket, string $key, string $path, int $size, string $mime, string $sha ): void {
		$md5 = hash_file( 'md5', $path, true );
		if ( false === $md5 ) {
			throw new TransferFailure( 'source_read_failed' );
		}
		$stream = fopen( $path, 'rb' );
		if ( false === $stream ) {
			throw new TransferFailure( 'source_unavailable' );
		}
		try {
			try {
				$client->putObject(
					array(
						'Bucket'        => $bucket,
						'Key'           => $key,
						'Body'          => $stream,
						'ContentLength' => $size,
						'ContentType'   => $mime,
						'IfNoneMatch'   => '*',
						// R2 validates the uploaded body with the supported Content-MD5 header.
						'ContentMD5'    => base64_encode( $md5 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- S3 Content-MD5 is a required base64-encoded digest header.
					'Metadata'          => array( 'jmo-sha256' => $sha ),
					)
				);
			} catch ( AwsException $exception ) {
				if ( $this->is_precondition_failure( $exception ) ) {
					throw $exception;
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains only a fixed diagnostic code.
				throw new TransferFailure( $this->safe_error_code( $exception, 'put_object' ) );
			}
		} finally {
			fclose( $stream );
		}
	}

	/** Upload bounded parts and persist each successful part for retry recovery. */
	/** Upload a bounded set of multipart chunks and persist resume state.
	 *
	 * @param object        $client S3-compatible client.
	 * @param string        $bucket R2 bucket name.
	 * @param string        $key    Collision-resistant object key.
	 * @param string        $path   Confined local source path.
	 * @param int           $size   Source size.
	 * @param string        $mime   Detected MIME type.
	 * @param string        $sha    Expected SHA-256 digest.
	 * @param array         $manifest_object Persisted manifest row.
	 * @param array         $resume Existing multipart state.
	 * @param callable|null $save_state Persists every successful part.
	 * @return bool Whether multipart completion succeeded.
	 * @throws AwsException On an R2 API request failure.
	 * @throws TransferFailure On source or multipart state errors.
	 */
	private function put_multipart( object $client, string $bucket, string $key, string $path, int $size, string $mime, string $sha, array $manifest_object, array $resume, ?callable $save_state ): bool {
		$upload_id = is_string( $resume['upload_id'] ?? null ) ? $resume['upload_id'] : '';
		$parts     = is_array( $resume['parts'] ?? null ) ? $resume['parts'] : array();
		if ( '' === $upload_id ) {
			try {
				$result = $client->createMultipartUpload(
					array(
						'Bucket'      => $bucket,
						'Key'         => $key,
						'ContentType' => $mime,
						'Metadata'    => array( 'jmo-sha256' => $sha ),
					)
				);
			} catch ( AwsException $exception ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains only a fixed diagnostic code.
				throw new TransferFailure( $this->safe_error_code( $exception, 'create_multipart_upload' ) );
			}
			$upload_id = (string) ( $result['UploadId'] ?? '' );
			if ( '' === $upload_id ) {
				throw new TransferFailure( 'multipart_start_failed' );
			}
			$parts = array();
			if ( null !== $save_state ) {
				$save_state( $upload_id, $parts );
			}
		} else {
			try {
				$parts = $this->server_parts( $client, $bucket, $key, $upload_id );
			} catch ( AwsException $exception ) {
				if ( 'NoSuchUpload' !== (string) $exception->getAwsErrorCode() ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains only a fixed diagnostic code.
					throw new TransferFailure( $this->safe_error_code( $exception, 'list_parts' ) );
				}
				$upload_id = '';
				$parts     = array();
				try {
					$result = $client->createMultipartUpload(
						array(
							'Bucket'      => $bucket,
							'Key'         => $key,
							'ContentType' => $mime,
							'Metadata'    => array( 'jmo-sha256' => $sha ),
						)
					);
				} catch ( AwsException $exception ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains only a fixed diagnostic code.
					throw new TransferFailure( $this->safe_error_code( $exception, 'create_multipart_upload' ) );
				}
				$upload_id = (string) ( $result['UploadId'] ?? '' );
				if ( '' === $upload_id ) {
					throw new TransferFailure( 'multipart_start_failed' );
				}
				if ( null !== $save_state ) {
					$save_state( $upload_id, $parts );
				}
			}
		}
		$stream = fopen( $path, 'rb' );
		if ( false === $stream ) {
			throw new TransferFailure( 'source_unavailable' );
		}
		try {
			$total              = (int) ceil( $size / self::PART_SIZE );
			$uploaded_this_call = 0;
			if ( $total > 10000 ) {
				throw new TransferFailure( 'multipart_limit_exceeded' );
			}
			for ( $number = 1; $number <= $total; ++$number ) {
				$length = min( self::PART_SIZE, $size - ( ( $number - 1 ) * self::PART_SIZE ) );
				if ( isset( $parts[ $number ] ) ) {
					continue;
				}
				$this->assert_identity( $manifest_object, $path, $size );
				if ( 0 !== fseek( $stream, ( $number - 1 ) * self::PART_SIZE ) ) {
					throw new TransferFailure( 'source_read_failed' );
				}
				$body        = '';
				$body_length = strlen( $body );
				while ( $body_length < $length && ! feof( $stream ) ) {
					$chunk = fread( $stream, min( 1048576, $length - $body_length ) );
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					$body       .= $chunk;
					$body_length = strlen( $body );
				}
				if ( strlen( $body ) !== $length ) {
					throw new TransferFailure( 'source_read_failed' );
				}
				try {
					$result = $client->uploadPart(
						array(
							'Bucket'        => $bucket,
							'Key'           => $key,
							'UploadId'      => $upload_id,
							'PartNumber'    => $number,
							'Body'          => $body,
							'ContentLength' => $length,
							'ContentMD5'    => base64_encode( md5( $body, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- R2 supports Content-MD5 on each UploadPart.
						)
					);
				} catch ( AwsException $exception ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains only a fixed diagnostic code.
					throw new TransferFailure( $this->safe_error_code( $exception, 'upload_part' ) );
				}
				$parts[ $number ] = array(
					'PartNumber' => $number,
					'ETag'       => (string) ( $result['ETag'] ?? '' ),
				);
				if ( '' === $parts[ $number ]['ETag'] ) {
					throw new TransferFailure( 'multipart_part_failed' );
				}
				if ( null !== $save_state ) {
					$save_state( $upload_id, $parts );
				}
				++$uploaded_this_call;
				if ( count( $parts ) < $total && $uploaded_this_call >= self::MAX_PARTS_PER_CALL ) {
					return false;
				}
			}
		} finally {
			fclose( $stream );
		}
		ksort( $parts );
		$this->assert_identity( $manifest_object, $path, $size );
		if ( count( $parts ) < $total ) {
			return false;
		}
		if ( ! hash_equals( $sha, (string) hash_file( 'sha256', $path ) ) ) {
			try {
				$client->abortMultipartUpload(
					array(
						'Bucket'   => $bucket,
						'Key'      => $key,
						'UploadId' => $upload_id,
					)
				);
			} catch ( AwsException $exception ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains only a fixed diagnostic code.
				throw new TransferFailure( $this->safe_error_code( $exception, 'abort_multipart_upload' ) );
			}
			throw new TransferFailure( 'source_changed' );
		}
		try {
			$client->completeMultipartUpload(
				array(
					'Bucket'          => $bucket,
					'Key'             => $key,
					'UploadId'        => $upload_id,
					'MultipartUpload' => array( 'Parts' => array_values( $parts ) ),
					'IfNoneMatch'     => '*',
				)
			);
		} catch ( AwsException $exception ) {
			if ( $this->is_precondition_failure( $exception ) ) {
				throw $exception;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This contains only a fixed diagnostic code.
			throw new TransferFailure( $this->safe_error_code( $exception, 'complete_multipart_upload' ) );
		}
		return true;
	}

	/** Recover server-confirmed part state after a worker interruption.
	 *
	 * @param object $client    S3-compatible client.
	 * @param string $bucket    R2 bucket name.
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload ID.
	 */
	private function server_parts( object $client, string $bucket, string $key, string $upload_id ): array {
		$parts  = array();
		$marker = 0;
		do {
			$result = $client->listParts(
				array(
					'Bucket'           => $bucket,
					'Key'              => $key,
					'UploadId'         => $upload_id,
					'PartNumberMarker' => $marker,
				)
			);
			foreach ( $result['Parts'] ?? array() as $part ) {
				$number = (int) ( $part['PartNumber'] ?? 0 );
				if ( $number > 0 && isset( $part['ETag'] ) ) {
					$parts[ $number ] = array(
						'PartNumber' => $number,
						'ETag'       => (string) $part['ETag'],
					);
				}
			}
			$marker = (int) ( $result['NextPartNumberMarker'] ?? 0 );
		} while ( ! empty( $result['IsTruncated'] ) && $marker > 0 );
		return $parts;
	}

	/** Return an object head if it exists without exposing raw SDK errors.
	 *
	 * @param object $client S3-compatible client.
	 * @param string $bucket R2 bucket.
	 * @param string $key    Object key.
	 * @return array|null Head response or null when absent.
	 * @throws TransferFailure On network or authorization errors.
	 */
	private function head_if_present( object $client, string $bucket, string $key ): ?array {
		try {
			$result = $client->headObject(
				array(
					'Bucket' => $bucket,
					'Key'    => $key,
				)
			);
			if ( null === $result ) {
				return null;
			}
			return is_array( $result ) ? $result : $result->toArray();
		} catch ( AwsException $exception ) {
			if ( in_array( (string) $exception->getAwsErrorCode(), array( 'NoSuchKey', 'NotFound' ), true ) || 404 === (int) $exception->getStatusCode() ) {
				return null;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception contains only a fixed internal diagnostic code.
			throw new TransferFailure( $this->safe_error_code( $exception, 'head_object' ) );
		} catch ( \Throwable ) {
			throw new TransferFailure( 'r2_head_object_client_failure' );
		}
	}

	/** Whether R2 rejected an atomic create-only condition.
	 *
	 * @param AwsException $exception Storage exception.
	 */
	private function is_precondition_failure( AwsException $exception ): bool {
		return in_array( (string) $exception->getAwsErrorCode(), array( 'PreconditionFailed', 'ConditionalRequestConflict' ), true ) || 412 === (int) $exception->getStatusCode();
	}

	/** Map an SDK exception to a fixed non-sensitive diagnostic category.
	 *
	 * @param AwsException $exception Storage exception.
	 * @param string       $operation Fixed S3 operation identifier.
	 */
	private function safe_error_code( AwsException $exception, string $operation ): string {
		$code = (string) $exception->getAwsErrorCode();
		if ( 'AccessDenied' === $code || 'Unauthorized' === $code ) {
			$category = 'permission_denied';
		} elseif ( in_array( $code, array( 'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'ExpiredToken', 'ExpiredRequest' ), true ) ) {
			$category = 'authentication_failed';
		} elseif ( in_array( $code, array( 'BadDigest', 'InvalidDigest' ), true ) ) {
			$category = 'checksum_rejected';
		} elseif ( 'RequestTimeout' === $code || 28 === (int) $exception->getTransferInfo( 'errno' ) ) {
			$category = 'timeout';
		} elseif ( 'RequestTimeTooSkewed' === $code ) {
			$category = 'clock_skew';
		} elseif ( in_array( $code, array( 'SlowDown', 'TooManyRequests' ), true ) || in_array( (int) $exception->getStatusCode(), array( 408, 429 ), true ) ) {
			$category = 'temporary_failure';
		} elseif ( in_array( $code, array( 'NotImplemented', 'NotSupported' ), true ) ) {
			$category = 'unsupported_request';
		} elseif ( in_array( $code, array( 'InvalidRequest', 'InvalidArgument', 'InvalidBucketName' ), true ) || ( (int) $exception->getStatusCode() >= 400 && (int) $exception->getStatusCode() < 500 ) ) {
			$category = 'request_rejected';
		} elseif ( (int) $exception->getStatusCode() >= 500 ) {
			$category = 'service_failure';
		} elseif ( 0 === (int) $exception->getStatusCode() ) {
			$category = 'network_failure';
		} else {
			$category = 'api_failure';
		}
		// Persist only compact allowlisted fields in the existing 64-character queue column.
		$operations    = array(
			'head_object'               => 'head',
			'put_object'                => 'put',
			'multipart_transfer'        => 'multi',
			'create_multipart_upload'   => 'create',
			'list_parts'                => 'parts',
			'upload_part'               => 'part',
			'complete_multipart_upload' => 'complete',
			'abort_multipart_upload'    => 'abort',
		);
		$categories    = array(
			'permission_denied'     => 'perm',
			'authentication_failed' => 'auth',
			'checksum_rejected'     => 'checksum',
			'timeout'               => 'timeout',
			'clock_skew'            => 'skew',
			'temporary_failure'     => 'temporary',
			'unsupported_request'   => 'unsupported',
			'request_rejected'      => 'rejected',
			'service_failure'       => 'service',
			'network_failure'       => 'network',
			'api_failure'           => 'api',
		);
		$allowed_codes = array(
			'AccessDenied',
			'Unauthorized',
			'InvalidAccessKeyId',
			'SignatureDoesNotMatch',
			'ExpiredToken',
			'ExpiredRequest',
			'BadDigest',
			'InvalidDigest',
			'RequestTimeout',
			'RequestTimeTooSkewed',
			'SlowDown',
			'TooManyRequests',
			'NotImplemented',
			'NotSupported',
			'InvalidRequest',
			'InvalidArgument',
			'InvalidBucketName',
			'InternalError',
			'ServiceUnavailable',
			'NoSuchBucket',
			'NoSuchKey',
			'NotFound',
		);
		$s3_code       = in_array( $code, $allowed_codes, true ) ? $code : 'Unknown';
		$status        = (int) $exception->getStatusCode();
		$status        = $status >= 100 && $status <= 599 ? (string) $status : '000';
		return 'r2d_' . ( $operations[ $operation ] ?? 'api' ) . '_' . ( $categories[ $category ] ?? 'api' ) . '_' . $status . '_' . $s3_code;
	}

	/** Compare size and stable filesystem identity captured in the manifest.
	 *
	 * @param array  $manifest_object Persisted manifest row.
	 * @param string $path            Confined local path.
	 * @param int    $size            Expected file size.
	 * @throws TransferFailure When the source changed after manifest creation.
	 */
	private function assert_identity( array $manifest_object, string $path, int $size ): void {
		$stat = stat( $path );
		if ( false === $stat || (int) $stat['size'] !== $size ) {
			throw new TransferFailure( 'source_changed' );
		}
		$expected = (string) ( $manifest_object['file_identity'] ?? '' );
		if ( '' === $expected ) {
			if ( ! hash_equals( (string) $manifest_object['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				throw new TransferFailure( 'source_changed' );
			}
			return;
		}
		$current = implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'], (int) $stat['ctime'] ) );
		if ( ! hash_equals( $expected, $current ) ) {
			throw new TransferFailure( 'source_changed' );
		}
	}

	/** Read a bounded administrator-selected multipart threshold.
	 *
	 * @return int Threshold in bytes.
	 */
	private function multipart_threshold(): int {
		$configured = (int) get_option( 'jmo_multipart_threshold', self::MULTIPART_THRESHOLD );
		return max( 8388608, min( 268435456, $configured ) );
	}
}
