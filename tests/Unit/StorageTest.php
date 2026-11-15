<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Unit;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Storage\ConnectionResult;
use Jocky\MediaOffloader\Storage\R2StorageClient;
use Jocky\MediaOffloader\Storage\StorageClientFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StorageTest extends TestCase {
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

	public function test_factory_returns_read_only_storage_client(): void {
		$factory = new StorageClientFactory( new CredentialsProvider() );
		self::assertInstanceOf( R2StorageClient::class, $factory->create() );
		self::assertSame( array(), array_diff( get_class_methods( R2StorageClient::class ), array( '__construct', 'test_connection' ) ) );
	}

	public function test_successful_mocked_connection_confirms_bucket_access(): void {
		$seen   = array();
		$client = new R2StorageClient(
			new CredentialsProvider(),
			static function ( array $options ) use ( &$seen ): object {
				$seen = $options;
				return new class() {
					public function headBucket( array $args ): \Aws\Result {
						if ( 'jocky-cdn-storage' !== $args['Bucket'] ) {
							throw new RuntimeException( 'Unexpected bucket.' );
						}
						return new \Aws\Result( array( '@metadata' => array( 'statusCode' => 200 ) ) );
					}
				};
			}
		);
		$result = $client->test_connection( new Configuration() );
		self::assertSame( 'connected', $result->status );
		self::assertTrue( $result->bucket_access );
		self::assertSame( 'auto', $seen['region'] );
		self::assertStringContainsString( str_repeat( 'a', 32 ) . '.r2.cloudflarestorage.com', $seen['endpoint'] );
		self::assertSame( 10, $seen['http']['timeout'] );
	}

	public function test_missing_configuration_or_credentials_fails_before_client_creation(): void {
		$GLOBALS['jmo_settings']['account_id'] = 'invalid';
		$client                                = new R2StorageClient(
			new CredentialsProvider(),
			static function (): object {
				throw new RuntimeException( 'Must not create.' );
			}
		);
		self::assertSame( 'invalid_configuration', $client->test_connection( new Configuration() )->status );
		$GLOBALS['jmo_settings']['account_id'] = str_repeat( 'a', 32 );
		putenv( 'JMO_R2_ACCESS_KEY_ID' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY' );
		self::assertSame( 'missing_credentials', $client->test_connection( new Configuration() )->status );
	}

	public function test_authentication_and_permission_errors_are_sanitized(): void {
		$command = $this->createMock( CommandInterface::class );
		foreach ( array(
			'InvalidAccessKeyId' => 'authentication_failure',
			'AccessDenied'       => 'insufficient_permissions',
			'NoSuchBucket'       => 'bucket_not_found',
		) as $code => $expected ) {
			$client = new R2StorageClient(
				new CredentialsProvider(),
				static function () use ( $command, $code ): object {
					return new class($command, $code) {
						public function __construct( private CommandInterface $command, private string $code ) {}
						public function headBucket( array $args ): void {
							throw new AwsException( 'sensitive=test-secret-key', $this->command, array( 'code' => $this->code ) ); }
					};
				}
			);
			$result = $client->test_connection( new Configuration() );
			self::assertSame( $expected, $result->status );
			self::assertStringNotContainsString( 'test-secret-key', $result->message );
		}
	}

	public function test_timeout_and_unexpected_errors_return_safe_results(): void {
		$command = $this->createMock( CommandInterface::class );
		foreach ( array(
			'cURL error 28: timed out'    => 'network_failure',
			'untrusted diagnostic detail' => 'connection_failure',
		) as $message => $expected ) {
			$client = new R2StorageClient(
				new CredentialsProvider(),
				static function () use ( $command, $message ): object {
					return new class($command, $message) {
						public function __construct( private CommandInterface $command, private string $message ) {}
						public function headBucket( array $args ): void {
							throw new AwsException( $this->message, $this->command ); }
					};
				}
			);
			$result = $client->test_connection( new Configuration() );
			self::assertSame( $expected, $result->status );
			self::assertStringNotContainsString( 'untrusted diagnostic detail', $result->message );
		}
	}

	public function test_unexpected_client_exception_is_sanitized_and_no_mutating_methods_exist(): void {
		$client = new R2StorageClient(
			new CredentialsProvider(),
			static function (): object {
				return new class() {
					public function headBucket( array $args ): void {
						throw new RuntimeException( 'secret and request data' ); }
				};
			}
		);
		$result = $client->test_connection( new Configuration() );
		self::assertSame( 'client_failure', $result->status );
		self::assertStringNotContainsString( 'secret', $result->message );
		self::assertSame( array(), array_diff( get_class_methods( R2StorageClient::class ), array( '__construct', 'test_connection' ) ) );
	}

	public function test_unexpected_api_response_is_not_reported_as_connected(): void {
		$client = new R2StorageClient(
			new CredentialsProvider(),
			static function (): object {
				return new class() {
					public function headBucket( array $args ): object {
						return new \stdClass();
					}
				};
			}
		);
		self::assertSame( 'unexpected_response', $client->test_connection( new Configuration() )->status );
	}

	public function test_structured_result_contains_only_safe_fields(): void {
		$data = ( new ConnectionResult( 'connected', 'Safe result', true ) )->to_array();
		self::assertSame( array( 'status', 'message', 'bucket_access', 'tested_at' ), array_keys( $data ) );
		self::assertArrayNotHasKey( 'credentials', $data );
	}
}
