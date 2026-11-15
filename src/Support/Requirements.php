<?php
/**
 * Runtime requirement checks.
 *
 * @package Jocky\MediaOffloader\Support
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Support;

/** Evaluates minimum runtime compatibility. */
final class Requirements {
	public const MIN_PHP       = '8.3.0';
	public const MIN_WORDPRESS = '6.6';

	/**
	 * Check both runtime requirements.
	 *
	 * @return array<string,bool> Requirement results.
	 */
	public static function check(): array {
		global $wp_version;
		return array(
			'php'       => version_compare( PHP_VERSION, self::MIN_PHP, '>=' ),
			'wordpress' => isset( $wp_version ) && version_compare( (string) $wp_version, self::MIN_WORDPRESS, '>=' ),
		);
	}

	/** Return whether all minimum requirements are met. */
	public static function supported(): bool {
		return ! in_array( false, self::check(), true );
	}
}
	/** Compare the current runtime with the declared plugin floor. */
