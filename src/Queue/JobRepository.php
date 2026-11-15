<?php
/**
 * Durable job storage, object state and atomic leases.
 *
 * @package Jocky\MediaOffloader\Queue
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Queue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- queue transitions require atomic SQL; identifiers are derived from the trusted table prefix.

/** Persists jobs and claims them with a single conditional UPDATE. */
final class JobRepository {
	public const MAX_ATTEMPTS = 5;
	public const MAX_OBJECTS  = 250;

	/** Persist a manifest or a pending-optimization placeholder idempotently.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $manifest      Validated file manifest.
	 * @param bool       $pending_jio   Whether JIO completion must be confirmed.
	 * @return int Job identifier, or zero on database failure.
	 */
	public function enqueue( int $attachment_id, ?array $manifest, bool $pending_jio = false ): int {
		global $wpdb;
		if ( $attachment_id < 1 || ( null === $manifest && ! $pending_jio ) ) {
			return 0;
		}
		$table = QueueSchema::jobs_table();
		$hash  = null === $manifest ? '' : (string) ( $manifest['hash'] ?? '' );
		if ( '' !== $hash && 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) {
			return 0;
		}
		$key      = hash( 'sha256', $attachment_id . '|' . ( $pending_jio ? 'pending-optimization' : $hash ) );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_key = %s LIMIT 1", $key ) );
		if ( $existing > 0 ) {
			return $existing;
		}
		$json = null === $manifest ? null : wp_json_encode( $manifest );
		if ( null !== $json && ( ! is_string( $json ) || strlen( $json ) > 1048576 || count( $manifest['files'] ?? array() ) > self::MAX_OBJECTS ) ) {
			return 0;
		}
		$status = $pending_jio ? 'pending_optimization' : 'pending';
		$wpdb->query( 'START TRANSACTION' );
		$insert = $wpdb->insert(
			$table,
			array(
				'attachment_id'    => $attachment_id,
				'job_key'          => $key,
				'manifest_version' => $manifest['version'] ?? 1,
				'manifest_hash'    => $hash,
				'manifest_json'    => $json,
				'status'           => $status,
				'phase'            => 'upload',
				'attempts'         => 0,
				'available_at'     => current_time( 'mysql', true ),
				'created_at'       => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $insert ) {
			$wpdb->query( 'ROLLBACK' );
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE job_key = %s LIMIT 1", $key ) );
		}
		$job_id = (int) $wpdb->insert_id;
		if ( ! $pending_jio && ! $this->insert_objects( $job_id, $manifest['files'] ?? array() ) ) {
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}
		$wpdb->query( 'COMMIT' );
		return $job_id;
	}

	/** Update a pending JIO job only after an administrator confirms completion.
	 *
	 * @param int   $job_id   Pending JIO job ID.
	 * @param array $manifest Current, validated file manifest.
	 */
	public function confirm_optimization( int $job_id, array $manifest ): bool {
		global $wpdb;
		$hash = (string) ( $manifest['hash'] ?? '' );
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) {
			return false;
		}
		$json = wp_json_encode( $manifest );
		if ( ! is_string( $json ) || strlen( $json ) > 1048576 || count( $manifest['files'] ?? array() ) > self::MAX_OBJECTS ) {
			return false;
		}
		$wpdb->query( 'START TRANSACTION' );
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . QueueSchema::jobs_table() . ' WHERE id = %d FOR UPDATE', $job_id ), ARRAY_A );
		if ( ! is_array( $job ) || 'pending_optimization' !== $job['status'] ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$new_key = hash( 'sha256', (int) $job['attachment_id'] . '|' . $hash );
		$dupe    = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . QueueSchema::jobs_table() . ' WHERE job_key = %s LIMIT 1', $new_key ) );
		if ( null !== $dupe && (int) $dupe !== $job_id ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . QueueSchema::jobs_table() . " SET job_key = %s, manifest_version = %d, manifest_hash = %s, manifest_json = %s, status = 'pending', phase = 'upload', error_code = NULL, available_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'pending_optimization'",
				$new_key,
				(int) ( $manifest['version'] ?? 1 ),
				$hash,
				$json,
				$job_id
			)
		);
		if ( 1 !== $result || ! $this->insert_objects( $job_id, $manifest['files'] ?? array() ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/** Atomically lease the oldest available pending/uploaded job.
	 *
	 * @param string $token         Random ownership token.
	 * @param int    $lease_seconds Lease duration, clamped to safe bounds.
	 */
	public function claim_next( string $token, int $lease_seconds = 300 ): ?array {
		global $wpdb;
		$table = QueueSchema::jobs_table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = IF(attempts + 1 >= %d,'failed',IF('verify' = phase,'uploaded','pending')), attempts = attempts + 1, lease_token = NULL, lease_expires_at = NULL, available_at = UTC_TIMESTAMP(), error_code = 'lease_expired', updated_at = UTC_TIMESTAMP() WHERE status = 'processing' AND lease_expires_at < UTC_TIMESTAMP()",
				self::MAX_ATTEMPTS,
			)
		);
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', lease_token = %s, lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), error_code = NULL, updated_at = UTC_TIMESTAMP() WHERE status IN ('pending','uploaded') AND available_at <= UTC_TIMESTAMP() AND attempts < %d ORDER BY id ASC LIMIT 1",
				$token,
				max( 60, min( 900, $lease_seconds ) ),
				self::MAX_ATTEMPTS
			)
		);
		if ( 1 !== $changed ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'processing' AND lease_token = %s LIMIT 1", $token ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Return objects in stable order for the requested job state.
	 *
	 * @param int    $job_id Job identifier.
	 * @param string $status Pending or uploaded.
	 */
	public function objects( int $job_id, string $status ): array {
		global $wpdb;
		if ( ! in_array( $status, array( 'pending', 'uploaded' ), true ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . QueueSchema::objects_table() . ' WHERE job_id = %d AND status = %s ORDER BY object_index ASC LIMIT %d', $job_id, $status, self::MAX_OBJECTS ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Persist object state and resumable multipart progress.
	 *
	 * @param int         $object_id Object row identifier.
	 * @param string      $status    New state.
	 * @param string|null $upload_id R2 multipart upload identifier.
	 * @param array|null  $parts     Successfully uploaded parts.
	 * @param string|null $etag      Remote entity tag.
	 * @param string|null $error     Sanitized error code.
	 */
	public function update_object( int $object_id, string $status, ?string $upload_id = null, ?array $parts = null, ?string $etag = null, ?string $error = null ): bool {
		global $wpdb;
		if ( ! in_array( $status, array( 'pending', 'uploaded', 'verified', 'failed' ), true ) ) {
			return false;
		}
		$parts_json = null === $parts ? null : wp_json_encode( $parts );
		return 1 === $wpdb->update(
			QueueSchema::objects_table(),
			array(
				'status'              => $status,
				'multipart_upload_id' => $upload_id,
				'multipart_parts'     => $parts_json,
				'remote_etag'         => $etag,
				'error_code'          => $error,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $object_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Release the lease while retaining progress and phase.
	 *
	 * @param int         $job_id Job identifier.
	 * @param string      $token  Current lease token.
	 * @param string      $status Next persisted state.
	 * @param string      $phase  Upload or verification phase.
	 * @param string|null $error  Sanitized failure code, if any.
	 * @param int         $delay  Retry delay in seconds.
	 */
	public function release( int $job_id, string $token, string $status, string $phase, ?string $error = null, int $delay = 0 ): bool {
		global $wpdb;
		if ( ! in_array( $status, array( 'pending', 'uploaded', 'verified', 'failed' ), true ) || ! in_array( $phase, array( 'upload', 'verify' ), true ) ) {
			return false;
		}
		return 1 === $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . QueueSchema::jobs_table() . ' SET status = IF(%d = 1 AND attempts + 1 >= %d, \'failed\', %s), attempts = IF(%d = 1, attempts + 1, 0), phase = %s, error_code = %s, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), lease_token = NULL, lease_expires_at = NULL, uploaded_count = (SELECT COUNT(*) FROM ' . QueueSchema::objects_table() . ' o WHERE o.job_id = %d AND o.status IN (\'uploaded\',\'verified\')), verified_count = (SELECT COUNT(*) FROM ' . QueueSchema::objects_table() . ' o WHERE o.job_id = %d AND o.status = \'verified\'), updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = \'processing\' AND lease_token = %s',
				null !== $error ? 1 : 0,
				self::MAX_ATTEMPTS,
				$status,
				null !== $error ? 1 : 0,
				$phase,
				$error,
				max( 0, min( 86400, $delay ) ),
				$job_id,
				$job_id,
				$job_id,
				$token
			)
		);
	}

	/** Retry all failed jobs after an authorized admin action. */
	public function retry_failed(): int {
		global $wpdb;
		return (int) $wpdb->query( 'UPDATE ' . QueueSchema::jobs_table() . " SET status = 'pending', phase = 'upload', attempts = 0, available_at = UTC_TIMESTAMP(), lease_token = NULL, lease_expires_at = NULL, error_code = NULL, updated_at = UTC_TIMESTAMP() WHERE status = 'failed'" );
	}

	/** Fetch one job row.
	 *
	 * @param int $job_id Job identifier.
	 */
	public function get( int $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . QueueSchema::jobs_table() . ' WHERE id = %d LIMIT 1', $job_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Return the pending-optimization job for an attachment, if one exists.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function pending_optimization_job_id( int $attachment_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . QueueSchema::jobs_table() . " WHERE attachment_id = %d AND status = 'pending_optimization' ORDER BY id DESC LIMIT 1", $attachment_id ) );
	}

	/** List recent admin-visible jobs with uploaded and verified counts.
	 *
	 * @param int $limit Maximum rows.
	 */
	public function recent( int $limit = 25 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT j.*, (SELECT COUNT(*) FROM ' . QueueSchema::objects_table() . ' o WHERE o.job_id = j.id AND o.status IN (\'uploaded\',\'verified\')) AS uploaded_count, (SELECT COUNT(*) FROM ' . QueueSchema::objects_table() . ' o WHERE o.job_id = j.id AND o.status = \'verified\') AS verified_count FROM ' . QueueSchema::jobs_table() . ' j ORDER BY j.id DESC LIMIT %d',
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** Queue counts keyed by known job status. */
	public function counts(): array {
		global $wpdb;
		$out  = array_fill_keys( array( 'pending', 'processing', 'uploaded', 'verified', 'failed', 'pending_optimization' ), 0 );
		$rows = $wpdb->get_results( 'SELECT status,COUNT(*) AS total FROM ' . QueueSchema::jobs_table() . ' GROUP BY status', ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['total'];
			}
		}
		return $out;
	}

	/** Check whether any queued job needs a worker tick.
	 *
	 * @return bool True when work remains.
	 */
	public function has_work(): bool {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . QueueSchema::jobs_table() . " WHERE status IN ('pending','uploaded','processing')" ) > 0;
	}

	/** Return seconds until the next pending job can be claimed.
	 *
	 * @return int|null Null when there are no claimable job states.
	 */
	public function next_work_delay(): ?int {
		global $wpdb;
		$value = $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),MIN(CASE WHEN status = \'processing\' THEN lease_expires_at ELSE available_at END)) FROM ' . QueueSchema::jobs_table() . " WHERE status IN ('pending','uploaded','processing')" );
		return null === $value ? null : max( 1, (int) $value );
	}

	/** Replace manifest children for a newly created job.
	 *
	 * @param int   $job_id Job identifier.
	 * @param array $files  Validated manifest files.
	 */
	private function insert_objects( int $job_id, array $files ): bool {
		global $wpdb;
		if ( empty( $files ) || count( $files ) > self::MAX_OBJECTS ) {
			return false;
		}
		$table = QueueSchema::objects_table();
		foreach ( $files as $index => $file ) {
			if ( ! is_array( $file ) || ! isset( $file['object_key'], $file['relative_path'], $file['size'], $file['mime'], $file['sha256'], $file['file_identity'] ) || ! is_string( $file['object_key'] ) || strlen( $file['object_key'] ) > 1024 || ! is_string( $file['relative_path'] ) || strlen( $file['relative_path'] ) > 512 || ! is_int( $file['size'] ) || $file['size'] < 0 || ! is_string( $file['mime'] ) || strlen( $file['mime'] ) > 128 || ! is_string( $file['file_identity'] ) || strlen( $file['file_identity'] ) > 160 || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', (string) $file['sha256'] ) ) {
				return false;
			}
			$object_hash = hash( 'sha256', $file['object_key'] );
			$result      = $wpdb->insert(
				$table,
				array(
					'job_id'          => $job_id,
					'object_index'    => (int) $index,
					'object_key_hash' => $object_hash,
					'object_key'      => $file['object_key'],
					'relative_path'   => $file['relative_path'],
					'size_bytes'      => $file['size'],
					'mime_type'       => $file['mime'],
					'sha256'          => $file['sha256'],
					'file_identity'   => $file['file_identity'],
					'status'          => 'pending',
					'created_at'      => current_time( 'mysql', true ),
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $result ) {
				return false;
			}
		}
		return true;
	}
}
