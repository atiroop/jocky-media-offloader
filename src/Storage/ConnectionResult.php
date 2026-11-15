<?php
/**
 * Safe structured connection diagnostic.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

/** Carries a sanitized storage diagnostic. */
final class ConnectionResult {
	/**
	 * Create a result with only safe status details.
	 *
	 * @param string $status Diagnostic classification.
	 * @param string $message Sanitized human-readable summary.
	 * @param bool   $bucket_access Whether the bucket request succeeded.
	 */
	public function __construct( public readonly string $status, public readonly string $message, public readonly bool $bucket_access = false ) {}

	/**
	 * Convert the result into a transient-safe array.
	 *
	 * @return array{status:string,message:string,bucket_access:bool,tested_at:string} Safe result.
	 */
	public function to_array(): array {
		return array(
			'status'        => $this->status,
			'message'       => $this->message,
			'bucket_access' => $this->bucket_access,
			'tested_at'     => gmdate( 'c' ),
		);
	}
}
