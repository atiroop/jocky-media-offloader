<?php
/**
 * Defers media discovery until WordPress has persisted the generated metadata.
 *
 * @package Jocky\MediaOffloader\Queue
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Queue;

use Jocky\MediaOffloader\Integration\JioReadiness;
use Jocky\MediaOffloader\Media\ManifestBuilder;

/** Schedules metadata discovery after the media request has stored its result. */
final class MediaDiscovery {
	public const DISCOVER_HOOK = 'jmo_discover_attachment';

	/** Create the deferred discovery controller.
	 *
	 * @param JobRepository $jobs   Persistent job repository.
	 * @param QueueWorker   $worker Scheduled transfer worker.
	 */
	public function __construct( private readonly JobRepository $jobs, private readonly QueueWorker $worker ) {}

	/** Register deferred discovery and its cron callback. */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'defer_after_metadata' ), PHP_INT_MAX, 2 );
		add_action( self::DISCOVER_HOOK, array( $this, 'discover' ) );
	}

	/** Schedule discovery after core returns and persists attachment metadata.
	 *
	 * @param mixed $metadata      Generated attachment metadata.
	 * @param int   $attachment_id Attachment identifier.
	 * @return mixed Unmodified metadata.
	 */
	public function defer_after_metadata( $metadata, int $attachment_id ) {
		if ( get_option( 'jmo_auto_offload', false ) && 'attachment' === get_post_type( $attachment_id ) ) {
			$args = array( $attachment_id );
			if ( ! wp_next_scheduled( self::DISCOVER_HOOK, $args ) ) {
				wp_schedule_single_event( time() + 15, self::DISCOVER_HOOK, $args );
			}
		}
		return $metadata;
	}

	/** Discover persisted media only when automatic queueing remains enabled.
	 *
	 * @param int $attachment_id Attachment identifier.
	 */
	public function discover( int $attachment_id ): void {
		if ( ! get_option( 'jmo_auto_offload', false ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}
		if ( ( new JioReadiness() )->requires_confirmation( $attachment_id ) ) {
			$this->jobs->enqueue( $attachment_id, null, true );
			return;
		}
		$manifest = ( new ManifestBuilder() )->build( $attachment_id );
		if ( null !== $manifest ) {
			$this->jobs->enqueue( $attachment_id, $manifest );
			$this->worker->schedule_immediate();
		}
	}
}
