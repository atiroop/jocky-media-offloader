<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Unit;

use Jocky\MediaOffloader\Storage\TransferDiagnostics;
use PHPUnit\Framework\TestCase;

final class TransferDiagnosticsTest extends TestCase {
	public function test_operation_and_failure_category_are_explained_without_raw_sdk_text(): void {
		$diagnostics = new TransferDiagnostics();
		$message     = $diagnostics->message( 'r2_put_object_checksum_rejected' );
		self::assertStringContainsString( 'PutObject', $message );
		self::assertStringContainsString( 'checksum', strtolower( $message ) );
		self::assertStringNotContainsString( 'authorization', strtolower( $message ) );
	}

	public function test_legacy_generic_code_warns_that_operation_was_not_retained(): void {
		$message = ( new TransferDiagnostics() )->message( 'r2_transfer_failure' );
		self::assertStringContainsString( 'older result', $message );
		self::assertStringNotContainsString( 'secret', strtolower( $message ) );
	}

	public function test_diagnostic_displays_operation_status_and_allowlisted_s3_code(): void {
		$message = ( new TransferDiagnostics() )->message( 'r2d_put_checksum_400_BadDigest' );
		self::assertStringContainsString( 'PutObject', $message );
		self::assertStringContainsString( 'HTTP status: 400', $message );
		self::assertStringContainsString( 'R2 error code: BadDigest', $message );
		self::assertStringContainsString( 'checksum', strtolower( $message ) );
	}

	public function test_unknown_s3_error_text_is_never_displayed(): void {
		$message = ( new TransferDiagnostics() )->message( 'r2d_put_rejected_502_Unknown' );
		self::assertStringContainsString( 'HTTP status: 502', $message );
		self::assertStringContainsString( 'not provided or unrecognized', $message );
		self::assertStringNotContainsString( 'authorization', strtolower( $message ) );
	}

	public function test_empty_or_null_diagnostic_never_falls_back_to_failure_text(): void {
		$diagnostics = new TransferDiagnostics();
		foreach ( array( null, '', '   ' ) as $empty_code ) {
			self::assertSame( 'No diagnostic recorded.', $diagnostics->message( $empty_code ) );
		}
	}

	public function test_empty_diagnostics_show_each_queue_status_without_false_failure(): void {
		$diagnostics = new TransferDiagnostics();
		$expected    = array(
			'pending_optimization' => 'Waiting for explicit JIO completion confirmation.',
			'pending'              => 'Queued / processing normally.',
			'processing'           => 'Processing.',
			'uploaded'             => 'Upload complete; verification is pending.',
			'verified'             => 'Completed successfully.',
			'failed'               => 'No diagnostic recorded.',
		);
		foreach ( $expected as $status => $message ) {
			foreach ( array( null, '' ) as $empty_code ) {
				$actual = $diagnostics->job_message( $status, $empty_code );
				self::assertSame( $message, $actual, $status . ' should show its current state.' );
				self::assertStringNotContainsString( 'The queue step failed.', $actual );
			}
		}
	}

	public function test_non_failed_jobs_label_stored_diagnostics_as_previous_attempts(): void {
		$diagnostics = new TransferDiagnostics();
		foreach ( array( 'pending', 'processing', 'uploaded', 'verified', 'pending_optimization' ) as $status ) {
			$message = $diagnostics->job_message( $status, 'r2_put_object_permission_denied' );
			self::assertStringContainsString( 'Previous attempt:', $message, $status . ' should identify the stored error as historical.' );
			self::assertStringContainsString( 'R2 denied PutObject', $message );
		}
	}

	public function test_failed_jobs_show_the_sanitized_actionable_diagnostic(): void {
		$message = ( new TransferDiagnostics() )->job_message( 'failed', 'r2_put_object_permission_denied' );
		self::assertStringContainsString( 'R2 denied PutObject', $message );
		self::assertStringNotContainsString( 'Previous attempt:', $message );
	}
}
