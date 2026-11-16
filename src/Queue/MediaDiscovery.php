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
	 * @param JioReadiness  $jio_readiness JIO public completion-contract reader.
	 */
	public function __construct(
		private readonly JobRepository $jobs,
		private readonly QueueWorker $worker,
		private readonly JioReadiness $jio_readiness = new JioReadiness()
	) {}

	/** Register deferred discovery and its cron callback. */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'defer_after_metadata' ), PHP_INT_MAX, 2 );
		add_action( self::DISCOVER_HOOK, array( $this, 'discover' ) );
		add_action( 'jio_attachment_optimization_terminal_v1', array( $this, 'jio_terminal' ) );
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
		$jio_before = $this->jio_readiness->state( $attachment_id );
		if ( $this->jio_readiness->requires_confirmation( $attachment_id ) ) {
			$this->jobs->enqueue( $attachment_id, null, true );
			return;
		}
		$manifest = ( new ManifestBuilder( $this->jio_readiness ) )->build( $attachment_id );
		if ( null !== $manifest && $this->jio_readiness->snapshot_still_current( $attachment_id, $jio_before ) ) {
			$pending_id = $this->jobs->pending_optimization_job_id( $attachment_id );
			if ( $pending_id > 0 ) {
				$this->jobs->confirm_optimization( $pending_id, $manifest );
			} else {
				$this->jobs->enqueue( $attachment_id, $manifest );
			}
			$this->worker->schedule_immediate();
		}
	}

	/** Queue a fresh generation only after JIO publishes terminal success and auto-queueing is enabled.
	 *
	 * @param array $state Versioned JIO terminal result.
	 */
	public function jio_terminal( array $state ): void {
		if ( ! get_option( 'jmo_auto_offload', false ) || ! JioReadiness::is_terminal_eligible( $state ) ) {
			return;
		}
		$attachment_id = absint( $state['attachment_id'] ?? 0 );
		if ( $attachment_id < 1
			|| ! $this->jio_readiness->has_compatible_contract( $attachment_id )
			|| ! $this->jio_readiness->snapshot_still_current( $attachment_id, $state )
		) {
			return;
		}
		$this->discover( $attachment_id );
	}
}
