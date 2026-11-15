<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Integration\JioDetector;
use Jocky\MediaOffloader\Integration\JioReadiness;
use Jocky\MediaOffloader\Plugin;
use Jocky\MediaOffloader\Queue\JobRepository;
use Jocky\MediaOffloader\Queue\QueueSchema;
use WP_UnitTestCase;

final class OffloadQueueTest extends WP_UnitTestCase {
	public function test_queue_persists_duplicate_suppresses_and_lease_is_exclusive(): void {
		global $wpdb;
		Plugin::activate();
		$wpdb->query( 'DELETE FROM ' . QueueSchema::objects_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::jobs_table() );
		$jobs     = new JobRepository();
		$manifest = $this->manifest();
		$job_id   = $jobs->enqueue( 77, $manifest );
		self::assertGreaterThan( 0, $job_id );
		self::assertSame( $job_id, $jobs->enqueue( 77, $manifest ) );
		self::assertSame( 'pending', $jobs->get( $job_id )['status'] );
		$claimed = $jobs->claim_next( str_repeat( 'a', 64 ) );
		self::assertSame( $job_id, (int) $claimed['id'] );
		self::assertNull( $jobs->claim_next( str_repeat( 'b', 64 ) ) );
	}

	public function test_expired_lease_is_recovered_before_next_claim(): void {
		global $wpdb;
		Plugin::activate();
		$wpdb->query( 'DELETE FROM ' . QueueSchema::objects_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::jobs_table() );
		$jobs   = new JobRepository();
		$job_id = $jobs->enqueue( 78, $this->manifest() );
		$jobs->claim_next( str_repeat( 'c', 64 ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . QueueSchema::jobs_table() . ' SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = %d', $job_id ) );
		$recovered = $jobs->claim_next( str_repeat( 'd', 64 ) );
		self::assertSame( $job_id, (int) $recovered['id'] );
		self::assertSame( 1, (int) $recovered['attempts'] );
		self::assertSame( 'processing', $recovered['status'] );
	}

	public function test_failed_attempt_is_delayed_and_diagnostic_is_persisted(): void {
		global $wpdb;
		Plugin::activate();
		$wpdb->query( 'DELETE FROM ' . QueueSchema::objects_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::jobs_table() );
		$jobs   = new JobRepository();
		$job_id = $jobs->enqueue( 79, $this->manifest() );
		$token  = str_repeat( 'e', 64 );
		$jobs->claim_next( $token );
		self::assertTrue( $jobs->release( $job_id, $token, 'pending', 'upload', 'r2_network_failure', 60 ) );
		$job = $jobs->get( $job_id );
		self::assertSame( 'r2_network_failure', $job['error_code'] );
		self::assertSame( 1, (int) $job['attempts'] );
		self::assertGreaterThan( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),available_at) FROM ' . QueueSchema::jobs_table() . ' WHERE id = %d', $job_id ) ) );
		self::assertNull( $jobs->claim_next( str_repeat( 'f', 64 ) ) );
	}

	public function test_automatic_failures_stop_at_the_maximum_attempt_count(): void {
		global $wpdb;
		Plugin::activate();
		$wpdb->query( 'DELETE FROM ' . QueueSchema::objects_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::jobs_table() );
		$jobs   = new JobRepository();
		$job_id = $jobs->enqueue( 80, $this->manifest() );

		for ( $attempt = 1; $attempt <= JobRepository::MAX_ATTEMPTS; ++$attempt ) {
			$token   = str_pad( (string) $attempt, 64, 'a' );
			$claimed = $jobs->claim_next( $token );
			self::assertSame( $job_id, (int) $claimed['id'] );
			self::assertTrue( $jobs->release( $job_id, $token, 'pending', 'upload', 'r2_put_object_request_rejected', 0 ) );
		}

		$failed = $jobs->get( $job_id );
		self::assertSame( 'failed', $failed['status'] );
		self::assertSame( JobRepository::MAX_ATTEMPTS, (int) $failed['attempts'] );
		self::assertSame( 'r2_put_object_request_rejected', $failed['error_code'] );
		self::assertNull( $jobs->claim_next( str_repeat( 'f', 64 ) ) );
	}

	public function test_jio_confirmation_is_required_only_for_supported_images(): void {
		$attachment_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$detector      = new JioDetector(
			static fn (): array => array(
				'jocky-image-optimizer/jocky-image-optimizer.php' => array(
					'Name'       => 'Jocky Image Optimizer',
					'TextDomain' => 'jocky-image-optimizer',
					'Version'    => '0.6.1-beta.1',
				),
			),
			static fn ( string $file ): bool => 'jocky-image-optimizer/jocky-image-optimizer.php' === $file
		);
		$readiness     = new JioReadiness( $detector );
		self::assertTrue( $readiness->requires_confirmation( $attachment_id ) );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		self::assertFalse( $readiness->requires_confirmation( $attachment_id ) );
	}

	/** Return a minimal valid one-object manifest for database tests. */
	private function manifest(): array {
		return array(
			'version'                => 1,
			'attachment_fingerprint' => str_repeat( 'f', 64 ),
			'hash'                   => str_repeat( 'e', 64 ),
			'files'                  => array(
				array(
					'relative_path' => '2026/11/sample.pdf',
					'size'          => 6,
					'mime'          => 'application/pdf',
					'sha256'        => str_repeat( 'a', 64 ),
					'file_identity' => '1:2:6:100:100',
					'object_key'    => 'jmo/v1/test/77/sample.pdf',
				),
			),
		);
	}
}
