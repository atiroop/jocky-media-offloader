<?php
/**
 * Secure credential source resolution.
 *
 * @package Jocky\MediaOffloader\Config
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Config;

/**
 * Resolves credentials without persisting or displaying secrets.
 *
 * @package Jocky\MediaOffloader\Config
 */
final class CredentialsProvider {
	/**
	 * Resolve a complete credential pair using configured precedence.
	 *
	 * @return array{access_key:string,secret_key:string,source:string}|null Credential pair or null.
	 */
	public function get(): ?array {
		$sources = array(
			'wp-config.php' => array(
				defined( 'JMO_R2_ACCESS_KEY_ID' ) ? (string) constant( 'JMO_R2_ACCESS_KEY_ID' ) : '',
				defined( 'JMO_R2_SECRET_ACCESS_KEY' ) ? (string) constant( 'JMO_R2_SECRET_ACCESS_KEY' ) : '',
			),
			'environment'   => array( (string) getenv( 'JMO_R2_ACCESS_KEY_ID' ), (string) getenv( 'JMO_R2_SECRET_ACCESS_KEY' ) ),
		);
		foreach ( $sources as $source => $pair ) {
			if ( '' !== $pair[0] || '' !== $pair[1] ) {
				if ( '' === $pair[0] || '' === $pair[1] ) {
					return null;
				}
				return array(
					'access_key' => $pair[0],
					'secret_key' => $pair[1],
					'source'     => $source,
				);
			}
		}
		return null;
	}

	/** Return the non-sensitive name of the active credential source. */
	public function source(): string {
		$credentials = $this->get();
		return null === $credentials ? 'Not configured' : $credentials['source'];
	}
}
