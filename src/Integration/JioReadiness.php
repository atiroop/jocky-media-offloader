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
		return in_array( (string) get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true );
	}
}
