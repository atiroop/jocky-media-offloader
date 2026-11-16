<?php
/**
 * Conservative readiness checks for Jocky Image Optimizer.
 *
 * @package Jocky\MediaOffloader\Integration
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Integration;

/** Holds JIO-supported originals until an administrator confirms optimization is complete. */
final class JioReadiness {
	public const MINIMUM_CONTRACT_VERSION = 1;
	/** Create readiness checker with an injectable public-metadata detector.
	 *
	 * @param JioDetector $detector Public plugin metadata detector.
	 */
	public function __construct( private readonly JioDetector $detector = new JioDetector() ) {}

	/** Whether JIO is active and manages this image family.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 */
	public function requires_confirmation( int $attachment_id ): bool {
		if ( ! $this->detector->detect()['active'] ) {
			return false;
		}
		if ( ! in_array( (string) get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return false;
		}
		$state = $this->state( $attachment_id );
		return null === $state || 'completed' !== (string) ( $state['outcome'] ?? '' );
	}

	/**
	 * Read JIO's versioned completion contract, or null for unavailable/old JIO.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null Valid contract state.
	 */
	public function state( int $attachment_id ): ?array {
		if ( ! $this->detector->detect()['active'] || ! in_array( (string) get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return null;
		}
		$class = '\\Jocky\\ImageOptimizer\\Integration\\AttachmentReadiness';
		if ( ! class_exists( $class ) || ! defined( 'JIO_VERSION' ) || version_compare( (string) constant( 'JIO_VERSION' ), '0.6.2', '<' ) ) {
			return null;
		}
		$state = $class::state( $attachment_id );
		if ( ! is_array( $state ) || (int) ( $state['contract_version'] ?? 0 ) < self::MINIMUM_CONTRACT_VERSION || (int) ( $state['attachment_id'] ?? 0 ) !== $attachment_id ) {
			return null;
		}
		return $state;
	}

	/**
	 * Whether a compatible JIO state allows a current manifest snapshot.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether snapshotting is safe.
	 */
	public function can_snapshot( int $attachment_id ): bool {
		if ( ! $this->requires_confirmation( $attachment_id ) ) {
			return true;
		}
		$state = $this->state( $attachment_id );
		return null === $state;
	}

	/**
	 * Ensure a compatible JIO generation stayed completed across manifest hashing.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $before State captured before hashing.
	 * @return bool Whether the state remained unchanged and terminal-successful.
	 */
	public function snapshot_still_current( int $attachment_id, ?array $before ): bool {
		if ( null === $before ) {
			return true;
		}
		$after = $this->state( $attachment_id );
		return is_array( $after ) && in_array( (string) ( $after['outcome'] ?? '' ), array( 'completed', 'skipped' ), true ) && hash_equals( (string) $before['generation'], (string) $after['generation'] ) && hash_equals( (string) $before['revision'], (string) $after['revision'] );
	}
}
