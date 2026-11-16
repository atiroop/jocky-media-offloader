<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Integration\JioDetector;
use Jocky\MediaOffloader\Integration\JioReadiness;
use Jocky\MediaOffloader\Media\ManifestBuilder;
use Jocky\MediaOffloader\Plugin;
use Jocky\MediaOffloader\Queue\JobRepository;
use Jocky\MediaOffloader\Queue\MediaDiscovery;
use Jocky\MediaOffloader\Queue\QueueSchema;
use Jocky\MediaOffloader\Queue\QueueWorker;
use WP_UnitTestCase;

/** Contract fixtures model the documented JIO v1 public result shape. */
final class JioReadinessTest extends WP_UnitTestCase {
	private int $attachment_id = 0;
	private string $source_path = '';
	private string $source_directory = '';
	private ?array $contract_state = null;
	private array $saved_options = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'jmo_auto_offload', 'jmo_site_key' ) as $option ) {
			$this->saved_options[ $option ] = get_option( $option, null );
		}
		Plugin::activate();
		$uploads          = wp_upload_dir();
		$this->source_directory = trailingslashit( $uploads['basedir'] ) . 'jmo-readiness-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->source_directory );
		$this->source_path = trailingslashit( $this->source_directory ) . 'source.jpg';
		self::assertNotFalse( file_put_contents( $this->source_path, "local source fixture\n" ) );
		$this->attachment_id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_title'     => 'JMO JIO readiness fixture',
			),
			$this->source_path,
			0,
			true
		);
		update_attached_file( $this->attachment_id, $this->source_path );
		wp_update_attachment_metadata(
			$this->attachment_id,
			array(
				'file'       => ltrim( str_replace( wp_normalize_path( $uploads['basedir'] ) . '/', '', wp_normalize_path( $this->source_path ) ), '/' ),
				'sizes'      => array(),
				'image_meta' => array(),
			)
		);
		$this->contract_state = $this->state_fixture( 'completed' );
	}

	public function tear_down(): void {
		global $wpdb;
		if ( $this->attachment_id > 0 ) {
			$job_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . QueueSchema::jobs_table() . ' WHERE attachment_id = %d', $this->attachment_id ) );
			foreach ( $job_ids as $job_id ) {
				$wpdb->delete( QueueSchema::objects_table(), array( 'job_id' => (int) $job_id ), array( '%d' ) );
			}
			$wpdb->delete( QueueSchema::jobs_table(), array( 'attachment_id' => $this->attachment_id ), array( '%d' ) );
			wp_delete_attachment( $this->attachment_id, true );
		}
		if ( '' !== $this->source_path && ( is_file( $this->source_path ) || is_link( $this->source_path ) ) ) {
			unlink( $this->source_path );
		}
		if ( '' !== $this->source_directory && is_dir( $this->source_directory ) ) {
			rmdir( $this->source_directory );
		}
		wp_clear_scheduled_hook( QueueWorker::CRON_HOOK );
		foreach ( $this->saved_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		parent::tear_down();
	}

	public function test_completed_and_terminal_skipped_states_are_eligible(): void {
		foreach ( array( '0.6.2', '0.6.3' ) as $version ) {
			$readiness = $this->readiness( true, $version );
			self::assertFalse( $readiness->requires_confirmation( $this->attachment_id ), $version );
			self::assertTrue( $readiness->can_snapshot( $this->attachment_id ), $version );
			self::assertTrue( $readiness->snapshot_still_current( $this->attachment_id, $this->contract_state ), $version );

			$this->contract_state = $this->state_fixture( 'skipped', array( 'full|image/webp' ), array() );
			self::assertFalse( $readiness->requires_confirmation( $this->attachment_id ), $version );
			self::assertTrue( $readiness->can_snapshot( $this->attachment_id ), $version );
			self::assertTrue( $readiness->snapshot_still_current( $this->attachment_id, $this->contract_state ), $version );
			$this->contract_state = $this->state_fixture( 'completed' );
		}
	}

	public function test_mixed_completed_and_skipped_contract_outcome_remains_blocked_as_partial(): void {
		$this->contract_state = $this->state_fixture( 'partial', array( 'full|image/webp', 'medium|image/webp' ), array( 'full|image/webp' ) );
		$readiness            = $this->readiness();
		self::assertTrue( $readiness->requires_confirmation( $this->attachment_id ) );
		self::assertFalse( $readiness->can_snapshot( $this->attachment_id ) );
		self::assertNull( ( new ManifestBuilder( $readiness ) )->build( $this->attachment_id ) );
	}

	public function test_pending_running_failed_invalidated_and_unknown_states_are_blocked(): void {
		$readiness = $this->readiness();		$expected  = array( 'full|image/webp' );
		$cases     = array(
			$this->state_fixture( 'pending', $expected, array(), false ),
			$this->state_fixture( 'running', $expected, array(), false ),
			$this->state_fixture( 'failed', $expected, array() ),
			$this->state_fixture( 'invalidated', $expected, array() ),
		);
		foreach ( $cases as $state ) {
			$this->contract_state = $state;
			self::assertTrue( $readiness->requires_confirmation( $this->attachment_id ), (string) $state['outcome'] );
			self::assertFalse( $readiness->can_snapshot( $this->attachment_id ), (string) $state['outcome'] );
			self::assertNull( ( new ManifestBuilder( $readiness ) )->build( $this->attachment_id ), (string) $state['outcome'] );
		}

		$this->contract_state = $this->state_fixture( 'unknown', $expected, array(), false );
		self::assertNull( $readiness->state( $this->attachment_id ) );
		self::assertTrue( $readiness->requires_confirmation( $this->attachment_id ) );
		self::assertFalse( $readiness->can_snapshot( $this->attachment_id ), 'a malformed supported contract cannot use manual confirmation as a bypass' );
		self::assertNull( ( new ManifestBuilder( $readiness ) )->build( $this->attachment_id ) );

		$this->contract_state = $this->state_fixture( 'skipped', $expected, array(), false );
		self::assertNull( $readiness->state( $this->attachment_id ), 'a skipped result must carry the contract terminal marker' );
		self::assertFalse( $readiness->can_snapshot( $this->attachment_id ) );

		$this->contract_state = $this->state_fixture( 'completed', array( 'full|image/webp', 'medium|image/webp' ), array( 'full|image/webp' ) );
		self::assertNull( $readiness->state( $this->attachment_id ), 'completed requires every expected item to be completed' );
		self::assertFalse( $readiness->can_snapshot( $this->attachment_id ) );
	}

	public function test_stale_generation_revision_or_outcome_invalidates_snapshot(): void {
		$readiness = $this->readiness();
		$before    = $this->contract_state;
		$this->contract_state['generation'] = str_repeat( 'b', 64 );
		self::assertFalse( $readiness->snapshot_still_current( $this->attachment_id, $before ) );

		$this->contract_state = $before;
		$before               = $this->contract_state;
		$this->contract_state['revision'] = str_repeat( 'c', 64 );
		self::assertFalse( $readiness->snapshot_still_current( $this->attachment_id, $before ) );

		$this->contract_state = $before;
		$before               = $this->contract_state;
		$this->contract_state = $this->state_fixture( 'skipped', array( 'full|image/webp' ), array() );
		self::assertFalse( $readiness->snapshot_still_current( $this->attachment_id, $before ), 'an outcome transition is rejected even if other identifiers are unchanged' );
	}

	public function test_missing_or_incompatible_jio_keeps_the_manual_fallback_and_optional_behavior(): void {
		$missing = $this->readiness( false, '0.0.0' );
		self::assertFalse( $missing->requires_confirmation( $this->attachment_id ) );
		self::assertTrue( $missing->can_snapshot( $this->attachment_id ) );

		$old = $this->readiness( true, '0.6.1' );
		self::assertTrue( $old->requires_confirmation( $this->attachment_id ) );
		self::assertTrue( $old->can_snapshot( $this->attachment_id ), 'manual confirmation remains available only for an absent or old contract' );
	}

	public function test_state_change_during_manifest_build_prevents_use_of_the_snapshot(): void {
		$readiness = $this->readiness();
		$before    = $readiness->state( $this->attachment_id );
		$changed    = false;
		// Use a named callback so the one-shot filter can be removed on its first invocation.
		$filter = function ( array $uploads ) use ( &$changed, &$filter ): array {
			if ( ! $changed ) {
				$changed              = true;
				$this->contract_state = $this->state_fixture( 'pending', array( 'full|image/webp' ), array(), false );
			}
			remove_filter( 'wp_upload_dir', $filter, 1000 );
			return $uploads;
		};
		add_filter( 'wp_upload_dir', $filter, 1000 );
		$manifest = ( new ManifestBuilder( $readiness ) )->build( $this->attachment_id );
		self::assertNull( $manifest, 'the builder rejects a readiness transition that occurs during file hashing' );
		self::assertFalse( $readiness->snapshot_still_current( $this->attachment_id, $before ) );
		self::assertTrue( $changed );
	}

	public function test_skipped_manifest_contains_only_existing_files_and_never_invents_derivatives(): void {
		$readiness            = $this->readiness();
		$completed_manifest   = ( new ManifestBuilder( $readiness ) )->build( $this->attachment_id );
		$this->contract_state = $this->state_fixture( 'skipped', array( 'full|image/webp' ), array() );
		$missing_relative     = 'jmo-readiness-missing/missing.jio.webp';
		add_post_meta(
			$this->attachment_id,
			'_jio_derivative_' . str_repeat( 'a', 32 ),
			array(
				'derivative_relative_path' => $missing_relative,
				'derivative_sha256'        => str_repeat( 'd', 64 ),
				'output_format'            => 'image/webp',
			)
		);
		$manifest = ( new ManifestBuilder( $readiness ) )->build( $this->attachment_id );
		self::assertNotNull( $manifest );
		self::assertNotNull( $completed_manifest );
		self::assertNotSame( $completed_manifest['hash'], $manifest['hash'], 'the terminal outcome remains part of the manifest identity' );
		self::assertSame( 'skipped', $manifest['jio_outcome'] );
		self::assertSame( 1, count( $manifest['files'] ) );
		self::assertSame( basename( $this->source_path ), basename( $manifest['files'][0]['relative_path'] ) );
		self::assertNotContains( $missing_relative, array_column( $manifest['files'], 'relative_path' ) );
		self::assertFileExists( $this->source_path );
	}

	public function test_terminal_skipped_event_confirms_only_a_current_pending_optimization_job(): void {
		global $wpdb;
		Plugin::activate();
		$this->contract_state = $this->state_fixture( 'skipped', array( 'full|image/webp' ), array() );
		$readiness            = $this->readiness();
		$jobs                 = new JobRepository();
		$pending_id           = $jobs->enqueue( $this->attachment_id, null, true );
		self::assertGreaterThan( 0, $pending_id );
		update_option( 'jmo_auto_offload', true, false );
		$discovery = new MediaDiscovery( $jobs, new QueueWorker(), $readiness );
		$discovery->jio_terminal( $this->contract_state );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status,manifest_json FROM ' . QueueSchema::jobs_table() . ' WHERE id = %d', $pending_id ), ARRAY_A );
		self::assertIsArray( $row );
		self::assertSame( 'pending', $row['status'] );
		$manifest = json_decode( (string) $row['manifest_json'], true );
		self::assertIsArray( $manifest );
		self::assertSame( 'skipped', $manifest['jio_outcome'] );
		self::assertSame( 1, count( $manifest['files'] ) );
		self::assertFileExists( $this->source_path );
	}

	public function test_stale_skipped_terminal_event_does_not_confirm_pending_job(): void {
		global $wpdb;
		Plugin::activate();
		$event_state          = $this->state_fixture( 'skipped', array( 'full|image/webp' ), array() );
		$this->contract_state = $event_state;
		$readiness            = $this->readiness();
		$jobs                 = new JobRepository();
		$pending_id           = $jobs->enqueue( $this->attachment_id, null, true );
		update_option( 'jmo_auto_offload', true, false );
		$this->contract_state['revision'] = str_repeat( 'e', 64 );
		( new MediaDiscovery( $jobs, new QueueWorker(), $readiness ) )->jio_terminal( $event_state );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status,manifest_json FROM ' . QueueSchema::jobs_table() . ' WHERE id = %d', $pending_id ), ARRAY_A );
		self::assertSame( 'pending_optimization', $row['status'] );
		self::assertNull( $row['manifest_json'] );
	}

	private function readiness( bool $active = true, string $version = '0.6.3' ): JioReadiness {
		$detector = new JioDetector(
			static fn (): array => array(
				'jocky-image-optimizer/jocky-image-optimizer.php' => array(
					'Name'       => 'Jocky Image Optimizer',
					'TextDomain' => 'jocky-image-optimizer',
					'Version'    => $version,
				),
			),
			static fn ( string $file ): bool => $active && 'jocky-image-optimizer/jocky-image-optimizer.php' === $file
		);
		return new JioReadiness( $detector, function ( int $attachment_id ): ?array {
			return $attachment_id === $this->attachment_id ? $this->contract_state : null;
		} );
	}

	/** Build a v1 public-contract fixture with valid hashes and status lists. */
	private function state_fixture( string $outcome, array $expected = array( 'full|image/webp' ), array $completed = array( 'full|image/webp' ), bool $terminal = true ): array {
		return array(
			'contract_version' => 1,
			'attachment_id'    => $this->attachment_id,
			'generation'       => str_repeat( 'a', 64 ),
			'expected'         => $expected,
			'completed'        => $completed,
			'outcome'          => $outcome,
			'terminal'         => $terminal,
			'revision'         => str_repeat( 'b', 64 ),
		);
	}
}
