<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Storage\CdnReadiness;
use Jocky\MediaOffloader\Storage\ConnectionResult;
use WP_UnitTestCase;

final class CdnReadinessTest extends WP_UnitTestCase {
	private array $saved_options = array();

	public function set_up(): void {
		parent::set_up();
		$this->saved_options['jmo_settings'] = get_option( 'jmo_settings', null );
		$this->saved_options[ CdnReadiness::OPTION ] = get_option( CdnReadiness::OPTION, null );
		update_option(
			'jmo_settings',
			array(
				'account_id' => str_repeat( 'a', 32 ),
				'bucket'     => 'jocky-cdn-storage',
				'cdn_domain' => 'cdn.example.com',
			)
		);
		delete_option( CdnReadiness::OPTION );
	}

	public function tear_down(): void {
		foreach ( $this->saved_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		parent::tear_down();
	}

	public function test_public_object_is_checked_for_status_type_and_length_and_result_stales_on_config_change(): void {
		$service = new CdnReadiness(
			new Configuration(),
			new CredentialsProvider(),
			static fn (): ConnectionResult => new ConnectionResult( 'connected', 'Connected.', true ),
			static fn (): array => array(
				'object_key' => 'jmo/v1/site/1/hash/000-photo.pdf',
				'size_bytes' => 123,
				'mime_type'  => 'application/pdf',
			),
			static fn ( string $url, array $args ): array => array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(
					'content-length' => '123',
					'content-type'   => 'application/pdf',
				),
			)
		);
		$result  = $service->test();
		self::assertSame( 'ready', $result['status'] );
		self::assertFalse( $service->current()['stale'] );
		update_option(
			'jmo_settings',
			array(
				'account_id' => str_repeat( 'a', 32 ),
				'bucket'     => 'jocky-cdn-storage',
				'cdn_domain' => 'other.example.com',
			)
		);
		self::assertTrue( $service->current()['stale'] );
	}

	public function test_auth_failure_missing_object_and_public_http_errors_are_classified_without_raw_errors(): void {
		$auth = new CdnReadiness( new Configuration(), new CredentialsProvider(), static fn (): ConnectionResult => new ConnectionResult( 'insufficient_permissions', 'Bucket access denied.' ), static fn (): ?array => null );
		self::assertSame( 'r2_insufficient_permissions', $auth->test()['status'] );
		$missing = new CdnReadiness( new Configuration(), new CredentialsProvider(), static fn (): ConnectionResult => new ConnectionResult( 'connected', 'Connected.', true ), static fn (): ?array => null );
		self::assertSame( 'no_verified_object', $missing->test()['status'] );
		$forbidden = new CdnReadiness(
			new Configuration(),
			new CredentialsProvider(),
			static fn (): ConnectionResult => new ConnectionResult( 'connected', 'Connected.', true ),
			static fn (): array => array(
				'object_key' => 'key',
				'size_bytes' => 1,
				'mime_type'  => 'image/png',
			),
			static fn ( string $url, array $args ): array => array(
				'response' => array( 'code' => 403 ),
				'headers'  => array(),
			)
		);
		self::assertSame( 'public_forbidden', $forbidden->test()['status'] );
		$not_found = new CdnReadiness(
			new Configuration(),
			new CredentialsProvider(),
			static fn (): ConnectionResult => new ConnectionResult( 'connected', 'Connected.', true ),
			static fn (): array => array(
				'object_key' => 'key',
				'size_bytes' => 1,
				'mime_type'  => 'image/png',
			),
			static fn ( string $url, array $args ): array => array(
				'response' => array( 'code' => 404 ),
				'headers'  => array(),
			)
		);
		self::assertSame( 'public_not_found', $not_found->test()['status'] );
		$mime_mismatch = new CdnReadiness(
			new Configuration(),
			new CredentialsProvider(),
			static fn (): ConnectionResult => new ConnectionResult( 'connected', 'Connected.', true ),
			static fn (): array => array(
				'object_key' => 'key',
				'size_bytes' => 1,
				'mime_type'  => 'image/png',
			),
			static fn ( string $url, array $args ): array => array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(
					'content-length' => '1',
					'content-type'   => 'text/plain',
				),
			)
		);
		self::assertSame( 'mime_mismatch', $mime_mismatch->test()['status'] );
		$timeout = new CdnReadiness(
			new Configuration(),
			new CredentialsProvider(),
			static fn (): ConnectionResult => new ConnectionResult( 'connected', 'Connected.', true ),
			static fn (): array => array(
				'object_key' => 'key',
				'size_bytes' => 1,
				'mime_type'  => 'image/png',
			),
			static fn ( string $url, array $args ): \WP_Error => new \WP_Error( 'http_request_failed', 'secret header must not be shown' )
		);
		$result  = $timeout->test();
		self::assertSame( 'dns_tls_or_network_failure', $result['status'] );
		self::assertStringNotContainsString( 'secret header', $result['message'] );
	}
}
