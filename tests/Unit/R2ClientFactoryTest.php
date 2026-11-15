<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Unit;

use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Storage\R2ClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class R2ClientFactoryTest extends TestCase {
	protected function setUp(): void {
		putenv( 'JMO_R2_ACCESS_KEY_ID=test-access-key' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY=test-secret-key' );
		$GLOBALS['jmo_settings'] = array(
			'account_id' => str_repeat( 'a', 32 ),
			'bucket'     => 'jocky-cdn-storage',
			'cdn_domain' => '',
		);
	}

	protected function tearDown(): void {
		putenv( 'JMO_R2_ACCESS_KEY_ID' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY' );
		unset( $GLOBALS['jmo_settings'] );
	}

	public function test_put_object_uses_r2_supported_content_md5_without_implicit_crc32(): void {
		$requests = array();
		$handler  = static function ( CommandInterface $command, RequestInterface $request ) use ( &$requests ): FulfilledPromise {
			$requests[] = $request;
			return new FulfilledPromise( new Result( array() ) );
		};
		$client   = ( new R2ClientFactory( new CredentialsProvider() ) )->create( $handler );
		self::assertNotNull( $client );
		self::assertSame( 'when_required', $client->getConfig( 'request_checksum_calculation' ) );
		$body = 'test payload';
		$client->putObject(
			array(
				'Bucket'        => 'jocky-cdn-storage',
				'Key'           => 'jmo/v1/test/object',
				'Body'          => $body,
				'ContentLength' => strlen( $body ),
				'ContentMD5'    => base64_encode( md5( $body, true ) ),
				'IfNoneMatch'   => '*',
			)
		);

		self::assertCount( 1, $requests );
		$request = $requests[0];
		self::assertTrue( $request->hasHeader( 'Content-MD5' ) );
		self::assertSame( '*', $request->getHeaderLine( 'If-None-Match' ) );
		self::assertFalse( $request->hasHeader( 'x-amz-checksum-crc32' ) );
		self::assertStringContainsString( 'AWS4-HMAC-SHA256', $request->getHeaderLine( 'Authorization' ) );
	}
}
