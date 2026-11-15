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
				defined( 'JMO_R2_ACCESS_KEY_ID' ) && is_string( constant( 'JMO_R2_ACCESS_KEY_ID' ) ) ? constant( 'JMO_R2_ACCESS_KEY_ID' ) : '',
				defined( 'JMO_R2_SECRET_ACCESS_KEY' ) && is_string( constant( 'JMO_R2_SECRET_ACCESS_KEY' ) ) ? constant( 'JMO_R2_SECRET_ACCESS_KEY' ) : '',
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

	/** Return a keyed, non-reversible identity for stale-result detection.
	 *
	 * The credentials themselves are never returned or persisted by this method.
	 */
	public function identity_fingerprint(): ?string {
		$constant_pair = array(
			defined( 'JMO_R2_ACCESS_KEY_ID' ) && is_string( constant( 'JMO_R2_ACCESS_KEY_ID' ) ) ? constant( 'JMO_R2_ACCESS_KEY_ID' ) : '',
			defined( 'JMO_R2_SECRET_ACCESS_KEY' ) && is_string( constant( 'JMO_R2_SECRET_ACCESS_KEY' ) ) ? constant( 'JMO_R2_SECRET_ACCESS_KEY' ) : '',
		);
		if ( '' !== $constant_pair[0] || '' !== $constant_pair[1] ) {
			$source = 'wp-config.php';
			$pair   = $constant_pair;
		} else {
			$source = 'environment';
			$pair   = array( (string) getenv( 'JMO_R2_ACCESS_KEY_ID' ), (string) getenv( 'JMO_R2_SECRET_ACCESS_KEY' ) );
		}
		if ( ! function_exists( 'wp_salt' ) ) {
			return null;
		}
		$identity = $source . "\0" . $pair[0] . "\0" . $pair[1];
		return hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) );
	}
}
