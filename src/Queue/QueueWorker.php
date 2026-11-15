<?php
/**
 * Bounded background R2 worker with resumable leases.
 *
 * @package Jocky\MediaOffloader\Queue
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Queue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic queue reads must reflect current database state; all queue writes and lease decisions are handled by JobRepository.

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Media\ManifestBuilder;
use Jocky\MediaOffloader\Storage\R2ClientFactory;
use Jocky\MediaOffloader\Storage\R2ObjectTransfer;
use Jocky\MediaOffloader\Storage\TransferFailure;

/** Leases and transfers one object step per bounded worker iteration. */
final class QueueWorker {
	public const CRON_HOOK   = 'jmo_process_offload_queue';
	public const MAX_BATCH   = 3;
	public const MAX_SECONDS = 45;

	/** Create the queue runner.
	 *
	 * @param JobRepository $jobs Persistent job storage.
	 */
	public function __construct( private readonly JobRepository $jobs ) {}

	/** Register the scheduled worker. */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
	}

	/** Run one conservative cron tick and schedule remaining work. */
	public function run_scheduled(): void {
		$this->schedule_next();
		$this->run_batch( 1 );
		$this->schedule_next();
	}

	/** Process a bounded batch under a strict iteration and wall-clock ceiling.
	 *
	 * @param int $batch_size Maximum object steps for this request.
	 * @return array{claimed:int,uploaded:int,verified:int,failed:int} Step counts.
	 */
	public function run_batch( int $batch_size = 1 ): array {
		$counts     = array(
			'claimed'  => 0,
			'uploaded' => 0,
			'verified' => 0,
			'failed'   => 0,
		);
		$batch_size = max( 1, min( self::MAX_BATCH, $batch_size ) );
		$deadline   = microtime( true ) + self::MAX_SECONDS;
		$step       = 0;
		while ( $step < $batch_size && microtime( true ) < $deadline ) {
			++$step;
			$token = bin2hex( random_bytes( 32 ) );
			$job   = $this->jobs->claim_next( $token, 300 );
			if ( null === $job ) {
				break;
			}
			++$counts['claimed'];
			try {
				$result = $this->process_one( $job, $token );
				if ( isset( $counts[ $result ] ) ) {
					++$counts[ $result ];
				}
			} catch ( TransferFailure $failure ) {
				$phase   = 'verify' === $job['phase'] ? 'verify' : 'upload';
				$status  = 'verify' === $phase ? 'uploaded' : 'pending';
				$attempt = (int) $job['attempts'];
				$delay   = min( 3600, 30 * ( 2 ** min( 7, $attempt ) ) );
				$this->jobs->release( (int) $job['id'], $token, $status, $phase, $failure->safe_code, $delay );
				++$counts['failed'];
			} catch ( \Throwable ) {
				$this->jobs->release( (int) $job['id'], $token, 'pending', 'upload', 'worker_failure', 60 );
				++$counts['failed'];
			}
		}
		return $counts;
	}

	/** Schedule outstanding queue work without adding recurring cron events. */
	public function schedule_next(): void {
		$delay = $this->jobs->next_work_delay();
		if ( null !== $delay && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 5, min( 3600, $delay ) ), self::CRON_HOOK );
		}
	}

	/** Bring the next worker tick forward after authorized work is queued. */
	public function schedule_immediate(): void {
		if ( ! $this->jobs->has_work() ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
	}

	/** Process a single upload or verification step for a valid leased job.
	 *
	 * @param array  $job   Leased job row.
	 * @param string $token Current lease token.
	 * @throws TransferFailure For safe, retryable storage or source failures.
	 */
	private function process_one( array $job, string $token ): string {
		$manifest = json_decode( (string) $job['manifest_json'], true );
		if ( ! is_array( $manifest ) || ! isset( $manifest['files'], $manifest['attachment_fingerprint'] ) || ! $this->attachment_unchanged( (int) $job['attachment_id'], $manifest ) ) {
			$this->jobs->release( (int) $job['id'], $token, 'failed', (string) $job['phase'], 'manifest_changed' );
			return 'failed';
		}
		$configuration = new Configuration();
		$config        = $configuration->all();
		$client        = ( new R2ClientFactory( new CredentialsProvider() ) )->create();
		if ( null === $client ) {
			throw new TransferFailure( 'r2_not_configured' );
		}
		$uploads = wp_upload_dir();
		$root    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $root ) {
			throw new TransferFailure( 'uploads_unavailable' );
		}
		$transfer = new R2ObjectTransfer();
		if ( 'verify' === $job['phase'] ) {
			$objects = $this->jobs->objects( (int) $job['id'], 'uploaded' );
			if ( array() === $objects ) {
				$this->jobs->release( (int) $job['id'], $token, 'verified', 'verify' );
				return 'verified';
			}
			$object = $objects[0];
			$transfer->verify_remote( $client, $config['bucket'], $object );
			$this->jobs->update_object( (int) $object['id'], 'verified', null, null, (string) $object['remote_etag'] );
			$remaining = $this->jobs->objects( (int) $job['id'], 'uploaded' );
			$status    = array() === $remaining ? 'verified' : 'uploaded';
			$this->jobs->release( (int) $job['id'], $token, $status, 'verify' );
			return 'verified';
		}
		$objects = $this->jobs->objects( (int) $job['id'], 'pending' );
		if ( array() === $objects ) {
			$this->jobs->release( (int) $job['id'], $token, 'uploaded', 'verify' );
			return 'uploaded';
		}
		$object = $objects[0];
		$file   = $manifest['files'][ (int) $object['object_index'] ] ?? null;
		if ( ! is_array( $file ) || (string) $file['relative_path'] !== (string) $object['relative_path'] || ! hash_equals( (string) $object['sha256'], (string) $file['sha256'] ) ) {
			throw new TransferFailure( 'manifest_invalid' );
		}
		$path = ( new ManifestBuilder() )->confined_file( $root, $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) $object['relative_path'] ) );
		if ( null === $path ) {
			throw new TransferFailure( 'source_unavailable' );
		}
		$parts  = json_decode( (string) $object['multipart_parts'], true );
		$resume = array(
			'upload_id' => (string) $object['multipart_upload_id'],
			'parts'     => is_array( $parts ) ? $parts : array(),
		);
		$result = $transfer->transfer(
			$client,
			$object,
			$path,
			$config['bucket'],
			$resume,
			function ( string $upload_id, array $uploaded_parts ) use ( $object ): void {
				$this->jobs->update_object( (int) $object['id'], 'pending', $upload_id, $uploaded_parts );
			}
		);
		if ( ! $result['complete'] ) {
			$this->jobs->release( (int) $job['id'], $token, 'pending', 'upload' );
			return 'uploaded';
		}
		$this->jobs->update_object( (int) $object['id'], 'uploaded', null, null, $result['etag'] );
		$remaining = $this->jobs->objects( (int) $job['id'], 'pending' );
		$status    = array() === $remaining ? 'uploaded' : 'pending';
		$this->jobs->release( (int) $job['id'], $token, $status, array() === $remaining ? 'verify' : 'upload' );
		return 'uploaded';
	}

	/** Compare stored attachment identity and metadata without hashing every file per part. */
	/** Compare current core metadata with the snapshot that created the job.
	 *
	 * @param int   $attachment_id Attachment identifier.
	 * @param array $manifest      Persisted file manifest.
	 */
	private function attachment_unchanged( int $attachment_id, array $manifest ): bool {
		$current = hash( 'sha256', (string) wp_json_encode( array( get_post_meta( $attachment_id, '_wp_attached_file', true ), wp_get_attachment_metadata( $attachment_id, true ), get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) ) ) );
		return hash_equals( (string) $manifest['attachment_fingerprint'], $current );
	}
}
