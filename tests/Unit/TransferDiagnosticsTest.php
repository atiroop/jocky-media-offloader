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
}
