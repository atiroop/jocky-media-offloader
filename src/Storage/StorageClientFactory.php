<?php
/**
 * Creates storage diagnostics clients.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use Jocky\MediaOffloader\Config\CredentialsProvider;

/** Builds the configured storage diagnostics adapter. */
final class StorageClientFactory {
	/**
	 * Create a factory with a credential resolver.
	 *
	 * @param CredentialsProvider $credentials Credential resolver.
	 */
	public function __construct( private readonly CredentialsProvider $credentials ) {}

	/** Create the R2 diagnostics implementation. */
	public function create(): StorageClientInterface {
		return new R2StorageClient( $this->credentials );
	}
}
