<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Storage\ConnectionResult;
use Jocky\MediaOffloader\Storage\ConnectionStatus;
use WP_UnitTestCase;

final class ConnectionStatusTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		update_option( Configuration::OPTION, array(
			'account_id' => str_repeat( 'a', 32 ),
			'bucket' => 'jocky-cdn-storage',
			'cdn_domain' => '',
		) );
		putenv( 'JMO_R2_ACCESS_KEY_ID=jmo-test-access-id' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY=jmo-test-secret-key' );
	}

	protected function tearDown(): void {
		delete_option( ConnectionStatus::OPTION );
		putenv( 'JMO_R2_ACCESS_KEY_ID' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY' );
		parent::tearDown();
	}

	public function test_successful_result_is_persisted_and_restored_as_current(): void {
		$status = new ConnectionStatus( new Configuration(), new CredentialsProvider() );
		$result = $status->record( new ConnectionResult( 'connected', 'Do not persist untrusted result text.', true ) );

		self::assertSame( 'connected', $result['status'] );
		self::assertTrue( $result['bucket_access'] );
		self::assertFalse( $result['stale'] );
		self::assertNotSame( '', $result['tested_at'] );
		self::assertSame( $result, $status->current() );
		$saved = get_option( ConnectionStatus::OPTION );
		self::assertArrayNotHasKey( 'message', $saved );
		self::assertStringNotContainsString( 'jmo-test-secret-key', wp_json_encode( $saved ) );
	}

	public function test_settings_and_credential_identity_changes_mark_success_stale(): void {
		$status = new ConnectionStatus( new Configuration(), new CredentialsProvider() );
		$status->record( new ConnectionResult( 'connected', 'Safe', true ) );

		$settings = get_option( Configuration::OPTION );
		$settings['bucket'] = 'another-bucket';
		update_option( Configuration::OPTION, $settings );
		$changed_bucket = $status->current();
		self::assertTrue( $changed_bucket['stale'] );
		self::assertFalse( $changed_bucket['bucket_access'] );

		$settings['bucket'] = 'jocky-cdn-storage';
		update_option( Configuration::OPTION, $settings );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY=rotated-test-secret-key' );
		$changed_credentials = $status->current();
		self::assertTrue( $changed_credentials['stale'] );
		self::assertFalse( $changed_credentials['bucket_access'] );
	}

	public function test_unrecognized_and_legacy_results_cannot_be_current_successes(): void {
		update_option( ConnectionStatus::OPTION, array( 'status' => 'connected', 'tested_at' => gmdate( 'c' ) ) );
		$status = new ConnectionStatus( new Configuration(), new CredentialsProvider() );
		self::assertTrue( $status->current()['stale'] );
		self::assertFalse( $status->current()['bucket_access'] );

		delete_option( ConnectionStatus::OPTION );
		set_transient( ConnectionStatus::OPTION, array( 'status' => 'connected', 'tested_at' => gmdate( 'c' ) ), MINUTE_IN_SECONDS );
		self::assertTrue( $status->current()['stale'] );
		self::assertFalse( $status->current()['bucket_access'] );
	}
}
