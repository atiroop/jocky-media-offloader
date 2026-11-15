<?php
/**
 * Administrator controls and status for queued R2 offloads.
 *
 * @package Jocky\MediaOffloader\Admin
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Admin;

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- POST handlers call authorize() before reading input; the only GET field is a sanitized display notice.

use Jocky\MediaOffloader\Integration\JioReadiness;
use Jocky\MediaOffloader\Media\ManifestBuilder;
use Jocky\MediaOffloader\Queue\JobRepository;
use Jocky\MediaOffloader\Queue\QueueWorker;
use Jocky\MediaOffloader\Storage\TransferDiagnostics;

/** Renders safe job controls and sanitized diagnostics. */
final class JobsPage {
	/** Create an admin screen controller.
	 *
	 * @param JobRepository $jobs   Persistent queue repository.
	 * @param QueueWorker   $worker Bounded background worker.
	 */
	public function __construct( private readonly JobRepository $jobs, private readonly QueueWorker $worker ) {}

	/** Register the page and its capability-gated POST handlers. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_jmo_set_auto_offload', array( $this, 'set_auto_offload' ) );
		add_action( 'admin_post_jmo_queue_attachment', array( $this, 'queue_attachment' ) );
		add_action( 'admin_post_jmo_process_queue', array( $this, 'process_queue' ) );
		add_action( 'admin_post_jmo_retry_failed', array( $this, 'retry_failed' ) );
	}

	/** Register the Jobs submenu. */
	public function menu(): void {
		add_submenu_page( 'jmo-settings', __( 'Offload Jobs', 'jocky-media-offloader' ), __( 'Offload Jobs', 'jocky-media-offloader' ), 'manage_options', 'jmo-jobs', array( $this, 'render' ) );
	}

	/** Render the operator console and recent jobs. */
	public function render(): void {
		$this->authorize();
		$counts = $this->jobs->counts();
		$rows   = $this->jobs->recent( 50 );
		$notice = isset( $_GET['jmo_notice'] ) ? sanitize_key( wp_unslash( $_GET['jmo_notice'] ) ) : '';
		echo '<div class="wrap"><h1>' . esc_html__( 'Offload Jobs', 'jocky-media-offloader' ) . '</h1>';
		if ( '' !== $notice ) {
			$messages = array(
				'queued'               => __( 'Attachment queued for background processing.', 'jocky-media-offloader' ),
				'pending-optimization' => __( 'JIO optimization is not confirmed complete. The job is held without a file manifest or upload.', 'jocky-media-offloader' ),
				'dry-run'              => __( 'Dry run complete. No R2 objects were uploaded.', 'jocky-media-offloader' ),
				'processed'            => __( 'The bounded worker batch finished. Remaining work is scheduled.', 'jocky-media-offloader' ),
				'retried'              => __( 'Failed jobs were returned to the queue.', 'jocky-media-offloader' ),
				'saved'                => __( 'Automatic offloading preference saved.', 'jocky-media-offloader' ),
				'error'                => __( 'The requested operation could not be completed. Review the job diagnostics.', 'jocky-media-offloader' ),
			);
			if ( isset( $messages[ $notice ] ) ) {
				echo '<div class="notice notice-info"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
			}
		}
		$this->render_dry_run();
		echo '<h2>' . esc_html__( 'Queue controls', 'jocky-media-offloader' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jmo_set_auto_offload" />';
		wp_nonce_field( 'jmo_set_auto_offload' );
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( (bool) get_option( 'jmo_auto_offload', false ), true, false ) . ' /> ' . esc_html__( 'Automatically queue eligible new media after WordPress persists its metadata (disabled by default)', 'jocky-media-offloader' ) . '</label></p>';
		echo '<p><label for="jmo-multipart-threshold">' . esc_html__( 'Multipart threshold', 'jocky-media-offloader' ) . '</label> <select id="jmo-multipart-threshold" name="multipart_threshold">';
		$threshold = (int) get_option( 'jmo_multipart_threshold', 33554432 );
		foreach ( array(
			16777216  => '16 MB',
			33554432  => '32 MB',
			67108864  => '64 MB',
			134217728 => '128 MB',
		) as $bytes => $label ) {
			echo '<option value="' . esc_attr( (string) $bytes ) . '" ' . selected( $threshold, $bytes, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <span class="description">' . esc_html__( 'Safe range: 8–256 MB; the default is 32 MB.', 'jocky-media-offloader' ) . '</span></p>';
		submit_button( __( 'Save automatic queueing', 'jocky-media-offloader' ), 'secondary' );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jmo_queue_attachment" />';
		wp_nonce_field( 'jmo_queue_attachment' );
		echo '<p><label for="jmo-attachment-id">' . esc_html__( 'Queue one attachment ID', 'jocky-media-offloader' ) . '</label> <input id="jmo-attachment-id" name="attachment_id" type="number" min="1" required /></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" /> ' . esc_html__( 'Dry run only: build and display the manifest without queueing or uploading', 'jocky-media-offloader' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="jio_complete" value="1" /> ' . esc_html__( 'I confirmed JIO finished optimizing this selected JPEG/PNG and its files are stable', 'jocky-media-offloader' ) . '</label></p>';
		submit_button( __( 'Queue selected attachment', 'jocky-media-offloader' ), 'secondary' );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jmo_process_queue" />';
		wp_nonce_field( 'jmo_process_queue' );
		echo '<label for="jmo-batch-size">' . esc_html__( 'Worker steps this request (maximum 3)', 'jocky-media-offloader' ) . '</label> <select id="jmo-batch-size" name="batch_size"><option value="1">1</option><option value="2">2</option><option value="3">3</option></select> ';
		submit_button( __( 'Start / continue processing', 'jocky-media-offloader' ), 'primary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jmo_retry_failed" />';
		wp_nonce_field( 'jmo_retry_failed' );
		submit_button( __( 'Retry failed jobs', 'jocky-media-offloader' ), 'secondary' );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Queue status', 'jocky-media-offloader' ) . '</h2><p>';
		foreach ( $counts as $status => $count ) {
			echo '<strong>' . esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) . ':</strong> ' . esc_html( (string) $count ) . ' &nbsp; ';
		}
		echo '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Job', 'jocky-media-offloader' ) . '</th><th>' . esc_html__( 'Attachment', 'jocky-media-offloader' ) . '</th><th>' . esc_html__( 'Status', 'jocky-media-offloader' ) . '</th><th>' . esc_html__( 'Objects uploaded / verified', 'jocky-media-offloader' ) . '</th><th>' . esc_html__( 'Attempts', 'jocky-media-offloader' ) . '</th><th>' . esc_html__( 'Diagnostic', 'jocky-media-offloader' ) . '</th></tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No offload jobs have been created.', 'jocky-media-offloader' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$total    = is_string( $row['manifest_json'] ) ? count( json_decode( $row['manifest_json'], true )['files'] ?? array() ) : 0;
			$progress = (int) $row['uploaded_count'] . ' / ' . (int) $row['verified_count'] . ' of ' . $total;
			$message  = 'pending_optimization' === $row['status'] ? __( 'Waiting for explicit JIO completion confirmation.', 'jocky-media-offloader' ) : ( new TransferDiagnostics() )->message( (string) ( $row['error_code'] ?? '' ) );
			echo '<tr><td>' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( (string) $row['attachment_id'] ) . '</td><td>' . esc_html( str_replace( '_', ' ', (string) $row['status'] ) ) . '</td><td>' . esc_html( $progress ) . '</td><td>' . esc_html( (string) $row['attempts'] ) . '</td><td>' . esc_html( $message ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Persist the automatic queueing preference (default remains false). */
	public function set_auto_offload(): void {
		$this->authorize( 'jmo_set_auto_offload' );
		update_option( 'jmo_auto_offload', isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ), false );
		$threshold = isset( $_POST['multipart_threshold'] ) ? absint( wp_unslash( $_POST['multipart_threshold'] ) ) : 33554432;
		$allowed   = array( 16777216, 33554432, 67108864, 134217728 );
		update_option( 'jmo_multipart_threshold', in_array( $threshold, $allowed, true ) ? $threshold : 33554432, false );
		$this->redirect( 'saved' );
	}

	/** Dry-run or queue only the selected, authorized attachment. */
	public function queue_attachment(): void {
		$this->authorize( 'jmo_queue_attachment' );
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) {
			$this->redirect( 'error' );
		}
		$is_dry_run = isset( $_POST['dry_run'] );
		$needs_jio  = ( new JioReadiness() )->requires_confirmation( $attachment_id );
		$confirmed  = isset( $_POST['jio_complete'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['jio_complete'] ) );
		if ( $needs_jio && ! $confirmed && ! $is_dry_run ) {
			$this->jobs->enqueue( $attachment_id, null, true );
			$this->redirect( 'pending-optimization' );
		}
		$manifest = ( new ManifestBuilder() )->build( $attachment_id );
		if ( null === $manifest ) {
			$this->redirect( 'error' );
		}
		if ( $is_dry_run ) {
			set_transient( 'jmo_dry_run_' . get_current_user_id(), $manifest, 5 * MINUTE_IN_SECONDS );
			$this->redirect( 'dry-run' );
		}
		if ( $needs_jio && $confirmed ) {
			$pending_id = $this->jobs->pending_optimization_job_id( $attachment_id );
			if ( $pending_id > 0 ) {
				if ( ! $this->jobs->confirm_optimization( $pending_id, $manifest ) ) {
					$this->redirect( 'error' );
				}
				$this->worker->schedule_immediate();
				$this->redirect( 'queued' );
			}
		}
		$job_id = $this->jobs->enqueue( $attachment_id, $manifest );
		if ( $job_id < 1 ) {
			$this->redirect( 'error' );
		}
		$this->worker->schedule_immediate();
		$this->redirect( 'queued' );
	}

	/** Run a bounded, administrator-initiated batch. */
	public function process_queue(): void {
		$this->authorize( 'jmo_process_queue' );
		$size = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 1;
		$this->worker->run_batch( max( 1, min( QueueWorker::MAX_BATCH, $size ) ) );
		$this->worker->schedule_next();
		$this->redirect( 'processed' );
	}

	/** Retry failed jobs using their existing content-addressed manifests. */
	public function retry_failed(): void {
		$this->authorize( 'jmo_retry_failed' );
		$this->jobs->retry_failed();
		$this->worker->schedule_immediate();
		$this->redirect( 'retried' );
	}

	/** Render and consume the current administrator's short-lived dry-run manifest. */
	private function render_dry_run(): void {
		$manifest = get_transient( 'jmo_dry_run_' . get_current_user_id() );
		if ( ! is_array( $manifest ) || empty( $_GET['jmo_notice'] ) || 'dry-run' !== sanitize_key( wp_unslash( $_GET['jmo_notice'] ) ) ) {
			return;
		}
		delete_transient( 'jmo_dry_run_' . get_current_user_id() );
		// translators: %d is the number of local files listed in the dry-run manifest.
		echo '<h2>' . esc_html__( 'Dry-run manifest', 'jocky-media-offloader' ) . '</h2><p>' . esc_html( sprintf( __( '%d files; no upload has been attempted.', 'jocky-media-offloader' ), count( $manifest['files'] ?? array() ) ) ) . '</p><ul>';
		foreach ( $manifest['files'] ?? array() as $file ) {
			echo '<li><code>' . esc_html( $file['relative_path'] ) . '</code> — ' . esc_html( size_format( (int) $file['size'] ) ) . ' — ' . esc_html( $file['mime'] ) . '</li>';
		}
		echo '</ul>';
	}

	/** Enforce administrator capability and, for POST actions, the action nonce.
	 *
	 * @param string $nonce_action Expected WordPress nonce action.
	 */
	private function authorize( string $nonce_action = '' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage offload jobs.', 'jocky-media-offloader' ), '', array( 'response' => 403 ) );
		}
		if ( '' !== $nonce_action ) {
			check_admin_referer( $nonce_action );
		}
	}

	/** Redirect to the Jobs screen with a fixed, non-sensitive notice code.
	 *
	 * @param string $notice Allowlisted notice key.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'jmo-jobs',
					'jmo_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
