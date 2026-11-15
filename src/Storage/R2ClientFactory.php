<?php
/**
 * Creates an S3-compatible client for Cloudflare R2 transfers.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use Aws\S3\S3Client;
use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Config\CredentialsProvider;

/** Builds clients with bounded timeouts and credentials from the existing provider. */
final class R2ClientFactory {
	/** Create the client factory.
	 *
	 * @param CredentialsProvider $credentials Resolves R2 credentials.
	 */
	public function __construct( private readonly CredentialsProvider $credentials ) {}

	/** Create a configured R2 S3 client, or null when credentials/config are incomplete. */
	public function create(): ?S3Client {
		$credentials   = $this->credentials->get();
		$configuration = new Configuration();
		$config        = $configuration->all();
		$endpoint      = $configuration->endpoint();
		if ( null === $credentials || '' === $endpoint || '' === $config['bucket'] ) {
			return null;
		}
		return new S3Client(
			array(
				'version'                 => 'latest',
				'region'                  => 'auto',
				'endpoint'                => $endpoint,
				'use_path_style_endpoint' => true,
				'credentials'             => array(
					'key'    => $credentials['access_key'],
					'secret' => $credentials['secret_key'],
				),
				'connect_timeout'         => 5,
				'timeout'                 => 15,
				'retries'                 => 0,
			)
		);
	}
}
