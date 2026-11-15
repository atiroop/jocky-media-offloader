<?php
/**
 * Storage diagnostics contract.
 *
 * @package Jocky\MediaOffloader\Storage
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Storage;

use Jocky\MediaOffloader\Config\Configuration;

interface StorageClientInterface {
	/**
	 * Perform a read-only connection diagnostic.
	 *
	 * @param Configuration $configuration Validated non-secret configuration.
	 * @return ConnectionResult Safe structured diagnostic.
	 */
	public function test_connection( Configuration $configuration ): ConnectionResult;
}
