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
}
