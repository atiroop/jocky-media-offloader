<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Unit;

use Jocky\MediaOffloader\Storage\R2ObjectTransfer;
use Jocky\MediaOffloader\Storage\TransferFailure;
use PHPUnit\Framework\TestCase;

final class R2ObjectTransferTest extends TestCase {
	private string $path;
	private string $body;

	protected function setUp(): void {
		$this->path = (string) tempnam( sys_get_temp_dir(), 'jmo-' );
		$this->body = 'JMO safe transfer test';
		file_put_contents( $this->path, $this->body );
		$stat = stat( $this->path );
		$GLOBALS['jmo_transfer_manifest'] = array(
			'object_key'    => 'jmo/v1/test/file',
			'size_bytes'    => strlen( $this->body ),
			'mime_type'     => 'text/plain',
			'sha256'        => hash( 'sha256', $this->body ),
			'file_identity' => implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'], (int) $stat['ctime'] ) ),
		);
	}

	protected function tearDown(): void {
		if ( isset( $this->path ) && is_file( $this->path ) ) {
			unlink( $this->path );
		}
		unset( $GLOBALS['jmo_transfer_manifest'] );
		unset( $GLOBALS['jmo_options']['jmo_multipart_threshold'] );
	}

	public function test_small_object_is_created_once_then_head_verified(): void {
		$client = new class( $this->body ) {
			public ?array $head = null;
			public array $put_args = array();
			public string $uploaded_body = '';
			public function __construct( private string $body ) {}
			public function headObject( array $args ): ?array { return $this->head; }
			public function putObject( array $args ): void {
				$this->put_args = $args;
				$this->uploaded_body = (string) stream_get_contents( $args['Body'] );
				$this->head = array(
					'ContentLength' => $args['ContentLength'],
					'Metadata'      => array( 'jmo-sha256' => $args['Metadata']['jmo-sha256'] ),
					'ETag'          => '"etag"',
				);
			}
		};
		$result = ( new R2ObjectTransfer() )->transfer( $client, $GLOBALS['jmo_transfer_manifest'], $this->path, 'test-bucket' );
		self::assertTrue( $result['complete'] );
		self::assertSame( 'etag', $result['etag'] );
		self::assertSame( $this->body, $client->uploaded_body );
		self::assertSame( '*', $client->put_args['IfNoneMatch'] );
		self::assertSame( base64_encode( md5( $this->body, true ) ), $client->put_args['ContentMD5'] );
	}

	public function test_existing_remote_object_with_wrong_size_is_rejected(): void {
		$client = new class {
			public function headObject( array $args ): array {
				return array( 'ContentLength' => 1, 'Metadata' => array( 'jmo-sha256' => hash( 'sha256', 'else' ) ) );
			}
		};
		$this->expectException( TransferFailure::class );
		$this->expectExceptionMessage( 'remote_size_mismatch' );
		( new R2ObjectTransfer() )->transfer( $client, $GLOBALS['jmo_transfer_manifest'], $this->path, 'test-bucket' );
	}

	public function test_remote_digest_metadata_must_match(): void {
		$transfer = new R2ObjectTransfer();
		$this->expectException( TransferFailure::class );
		$this->expectExceptionMessage( 'remote_checksum_mismatch' );
		$transfer->verify_result(
			array( 'ContentLength' => strlen( $this->body ), 'Metadata' => array( 'jmo-sha256' => str_repeat( '0', 64 ) ) ),
			strlen( $this->body ),
		hash( 'sha256', $this->body )
		);
	}

	public function test_multipart_upload_resumes_from_server_parts_and_verifies_full_object(): void {
		$this->body = str_repeat( 'a', R2ObjectTransfer::PART_SIZE ) . 'b';
		file_put_contents( $this->path, $this->body );
		$stat = stat( $this->path );
		$GLOBALS['jmo_transfer_manifest'] = array(
			'object_key'    => 'jmo/v1/test/video.mp4',
			'size_bytes'    => strlen( $this->body ),
			'mime_type'     => 'video/mp4',
			'sha256'        => hash( 'sha256', $this->body ),
			'file_identity' => implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'], (int) $stat['ctime'] ) ),
		);
		$GLOBALS['jmo_options']['jmo_multipart_threshold'] = R2ObjectTransfer::PART_SIZE;
		$client = new class {
			public ?array $head = null;
			public array $parts = array();
			public array $metadata = array();
			public int $list_calls = 0;
			public function headObject( array $args ): ?array { return $this->head; }
			public function createMultipartUpload( array $args ): array { $this->metadata = $args['Metadata']; return array( 'UploadId' => 'upload-1' ); }
			public function uploadPart( array $args ): array {
				$this->parts[ $args['PartNumber'] ] = array( 'PartNumber' => $args['PartNumber'], 'ETag' => 'part-' . $args['PartNumber'], 'Body' => $args['Body'] );
				return array( 'ETag' => 'part-' . $args['PartNumber'] );
			}
			public function listParts( array $args ): array {
				++$this->list_calls;
				return array( 'Parts' => array_map( static fn ( array $part ): array => array( 'PartNumber' => $part['PartNumber'], 'ETag' => $part['ETag'] ), array_values( $this->parts ) ) );
			}
			public function completeMultipartUpload( array $args ): array {
				$body = implode( '', array_map( static fn ( array $part ): string => $part['Body'], array_values( $this->parts ) ) );
				$this->head = array( 'ContentLength' => strlen( $body ), 'Metadata' => $this->metadata, 'ETag' => '"multipart"' );
				return array();
			}
		};
		$transfer = new R2ObjectTransfer();
		$resume   = array();
		$save     = static function ( string $upload_id, array $parts ) use ( &$resume ): void { $resume = array( 'upload_id' => $upload_id, 'parts' => $parts ); };
		$first    = $transfer->transfer( $client, $GLOBALS['jmo_transfer_manifest'], $this->path, 'test-bucket', array(), $save );
		self::assertFalse( $first['complete'] );
		self::assertCount( 1, $client->parts );
		$second = $transfer->transfer( $client, $GLOBALS['jmo_transfer_manifest'], $this->path, 'test-bucket', $resume, $save );
		self::assertTrue( $second['complete'] );
		self::assertSame( 'multipart', $second['etag'] );
		self::assertCount( 2, $client->parts );
		self::assertSame( 1, $client->list_calls );
		unset( $GLOBALS['jmo_options']['jmo_multipart_threshold'] );
	}
}
