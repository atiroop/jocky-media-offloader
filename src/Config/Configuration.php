<?php
/**
 * Validated non-secret plugin configuration.
 *
 * @package Jocky\MediaOffloader\Config
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Config;

/**
 * Reads and validates JMO-owned options.
 *
 * @package Jocky\MediaOffloader\Config
 */
final class Configuration {
	public const OPTION         = 'jmo_settings';
	public const DEFAULT_BUCKET = 'jocky-cdn-storage';

	/**
	 * Get validated settings.
	 *
	 * @return array{account_id:string,bucket:string,cdn_domain:string} Validated values.
	 */
	public function all(): array {
		$stored     = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$stored     = is_array( $stored ) ? $stored : array();
		$account_id = $this->string_value( defined( 'JMO_R2_ACCOUNT_ID' ) ? constant( 'JMO_R2_ACCOUNT_ID' ) : getenv( 'JMO_R2_ACCOUNT_ID' ) );
		if ( '' === $account_id ) {
			$account_id = $this->string_value( $stored['account_id'] ?? '' );
		}
		return array(
			'account_id' => $this->account_id( $account_id ),
			'bucket'     => $this->bucket( $this->string_value( $stored['bucket'] ?? self::DEFAULT_BUCKET ) ),
			'cdn_domain' => $this->cdn_domain( $this->string_value( $stored['cdn_domain'] ?? '' ) ),
		);
	}

	/**
	 * Validate submitted settings.
	 *
	 * @param array<string,mixed> $input Submitted values.
	 * @return array<string,string> Validation messages by key.
	 */
	public function validate( array $input ): array {
		$errors = array();
		foreach ( array( 'account_id', 'bucket', 'cdn_domain' ) as $key ) {
			if ( array_key_exists( $key, $input ) && ! is_string( $input[ $key ] ) ) {
				$errors[ $key ] = 'Enter a text value.';
			}
		}
		$account = $this->string_value( $input['account_id'] ?? '' );
		$bucket  = $this->string_value( $input['bucket'] ?? self::DEFAULT_BUCKET );
		$cdn     = $this->string_value( $input['cdn_domain'] ?? '' );
		if ( '' !== $account && '' === $this->account_id( $account ) ) {
			$errors['account_id'] = 'Enter a valid Cloudflare account ID (32 hexadecimal characters).';
		}
		if ( '' !== $bucket && '' === $this->bucket( $bucket ) ) {
			$errors['bucket'] = 'Enter a valid S3 bucket name.';
		}
		if ( '' !== $cdn && '' === $this->cdn_domain( $cdn ) ) {
			$errors['cdn_domain'] = 'Enter a hostname only, without a scheme, path, or port.';
		}
		return $errors;
	}

	/**
	 * Sanitize settings for storage.
	 *
	 * @param array<string,mixed> $input Submitted values.
	 * @return array{account_id:string,bucket:string,cdn_domain:string} Sanitized values.
	 */
	public function sanitize( array $input ): array {
		return array(
			'account_id' => $this->account_id( $this->string_value( $input['account_id'] ?? '' ) ),
			'bucket'     => $this->bucket( $this->string_value( $input['bucket'] ?? self::DEFAULT_BUCKET ) ),
			'cdn_domain' => $this->cdn_domain( $this->string_value( $input['cdn_domain'] ?? '' ) ),
		);
	}

	/** Normalize a scalar admin value without converting arrays to strings.
	 *
	 * @param mixed  $value Submitted or stored value.
	 * @param string $fallback Value used when the input is not a string.
	 * @return string Normalized text.
	 */
	private function string_value( $value, string $fallback = '' ): string {
		return is_string( $value ) ? trim( $value ) : $fallback;
	}

	/**
	 * Validate and normalize a Cloudflare account ID.
	 *
	 * @param string $value Candidate account ID.
	 * @return string Normalized account ID or an empty string.
	 */
	public function account_id( string $value ): string {
		return 1 === preg_match( '/\A[a-f0-9]{32}\z/i', $value ) ? strtolower( $value ) : '';
	}

	/**
	 * Validate an S3-compatible bucket name.
	 *
	 * @param string $value Candidate bucket name.
	 * @return string Valid bucket name or an empty string.
	 */
	public function bucket( string $value ): string {
		if ( strlen( $value ) < 3 || strlen( $value ) > 63 || 1 !== preg_match( '/\A[a-z0-9][a-z0-9.-]*[a-z0-9]\z/', $value ) || str_contains( $value, '..' ) || preg_match( '/\d+\.\d+\.\d+\.\d+/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Validate and normalize a hostname.
	 *
	 * @param string $value Hostname supplied by an administrator.
	 * @return string Valid hostname or an empty string.
	 */
	public function cdn_domain( string $value ): string {
		$value = strtolower( rtrim( $value, '.' ) );
		if ( '' === $value ) {
			return '';
		}
		return strlen( $value ) <= 253 && 1 === preg_match( '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $value ) ? $value : '';
	}

	/** Derive the allowlisted Cloudflare R2 endpoint. */
	public function endpoint(): string {
		$account_id = $this->all()['account_id'];
		return '' === $account_id ? '' : 'https://' . $account_id . '.r2.cloudflarestorage.com';
	}
}
