<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Unit;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;
use Jocky\MediaOffloader\Support\Requirements;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase {
	public function test_defaults_and_derived_endpoint(): void {
		$config = new Configuration();
		self::assertSame( Configuration::DEFAULT_BUCKET, $config->all()['bucket'] );
		self::assertSame( '', $config->endpoint() );
	}

	public function test_validates_account_bucket_and_hostname_without_accepting_urls(): void {
		$config = new Configuration();
		self::assertSame(
			array(),
			$config->validate(
				array(
					'account_id' => str_repeat( 'a', 32 ),
					'bucket'     => 'jocky-cdn-storage',
					'cdn_domain' => 'cdn.example.com',
				)
			)
		);
		self::assertArrayHasKey(
			'account_id',
			$config->validate(
				array(
					'account_id' => 'attacker.example',
					'bucket'     => 'jocky-cdn-storage',
				)
			)
		);
		self::assertArrayHasKey(
			'bucket',
			$config->validate(
				array(
					'account_id' => '',
					'bucket'     => '../private',
				)
			)
		);
		self::assertArrayHasKey( 'cdn_domain', $config->validate( array( 'cdn_domain' => 'https://example.com/path' ) ) );
		self::assertSame( '', $config->account_id( 'https://169.254.169.254' ) );
	}

	public function test_sanitizes_non_sensitive_settings(): void {
		$config = new Configuration();
		self::assertSame(
			array(
				'account_id' => str_repeat( 'a', 32 ),
				'bucket'     => 'jocky-cdn-storage',
				'cdn_domain' => 'cdn.example.com',
			),
			$config->sanitize(
				array(
					'account_id' => strtoupper( str_repeat( 'A', 32 ) ),
					'bucket'     => 'jocky-cdn-storage',
					'cdn_domain' => 'CDN.EXAMPLE.COM.',
				)
			)
		);
	}

	public function test_account_id_configuration_precedence_is_constant_environment_then_option(): void {
		$GLOBALS['jmo_settings'] = array(
			'account_id' => str_repeat( 'b', 32 ),
			'bucket'     => 'jocky-cdn-storage',
			'cdn_domain' => '',
		);
		putenv( 'JMO_R2_ACCOUNT_ID=' . str_repeat( 'c', 32 ) );
		self::assertSame( str_repeat( 'c', 32 ), ( new Configuration() )->all()['account_id'] );
		putenv( 'JMO_R2_ACCOUNT_ID' );
		self::assertSame( str_repeat( 'b', 32 ), ( new Configuration() )->all()['account_id'] );
		unset( $GLOBALS['jmo_settings'] );
	}

	public function test_credentials_use_environment_and_never_persist_in_options(): void {
		putenv( 'JMO_R2_ACCESS_KEY_ID=test-access-key' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY=test-secret-key' );
		$credentials = ( new CredentialsProvider() )->get();
		self::assertSame( 'environment', $credentials['source'] );
		self::assertSame( 'test-access-key', $credentials['access_key'] );
		putenv( 'JMO_R2_ACCESS_KEY_ID' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY' );
	}

	public function test_missing_or_partial_credentials_are_not_resolved(): void {
		putenv( 'JMO_R2_ACCESS_KEY_ID=partial-test-key' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY' );
		self::assertNull( ( new CredentialsProvider() )->get() );
		putenv( 'JMO_R2_ACCESS_KEY_ID' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_config_constants_take_precedence_over_environment(): void {
		define( 'JMO_R2_ACCESS_KEY_ID', 'constant-access-key' );
		define( 'JMO_R2_SECRET_ACCESS_KEY', 'constant-secret-key' );
		putenv( 'JMO_R2_ACCESS_KEY_ID=environment-access-key' );
		putenv( 'JMO_R2_SECRET_ACCESS_KEY=environment-secret-key' );
		$credentials = ( new CredentialsProvider() )->get();
		self::assertSame( 'wp-config.php', $credentials['source'] );
		self::assertSame( 'constant-access-key', $credentials['access_key'] );
	}

	public function test_requirement_checks_match_declared_runtime_floor(): void {
		global $wp_version;
		$wp_version = '6.6';
		self::assertSame(
			array(
				'php'       => version_compare( PHP_VERSION, '8.3.0', '>=' ),
				'wordpress' => true,
			),
			Requirements::check()
		);
		$wp_version = '6.5';
		self::assertFalse( Requirements::supported() );
		$wp_version = '6.6';
	}
}
