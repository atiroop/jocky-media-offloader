<?php
/**
 * A transfer failure with a fixed, sanitized diagnostic code.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use RuntimeException;

// phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found -- Constructor stores a typed safe diagnostic alongside the parent message.

/** Exception whose message is a fixed, non-sensitive diagnostic code. */
final class TransferFailure extends RuntimeException {
	/** Create a sanitized transfer error.
	 *
	 * @param string $safe_code Allowlisted diagnostic code.
	 */
	public function __construct( public readonly string $safe_code ) {
		parent::__construct( $safe_code );
	}
}
