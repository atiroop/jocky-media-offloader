<?php
/**
 * Optional Jocky Image Optimizer metadata detection.
 *
 * @package Jocky\MediaOffloader\Integration
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Integration;

/** Reads public plugin metadata to detect optional JIO installation. */
final class JioDetector {
	/**
	 * Detect JIO from public plugin metadata without requiring JIO classes.
	 *
	 * @return array{active:bool,version:string,plugin:string} Detection result.
	 */
	public function detect(): array {
		$active  = defined( 'JIO_VERSION' );
		$version = $active ? (string) constant( 'JIO_VERSION' ) : '';
		if ( function_exists( 'get_plugins' ) && function_exists( 'is_plugin_active' ) ) {
			$plugins = get_plugins();
			foreach ( $plugins as $file => $metadata ) {
				if ( 'Jocky Image Optimizer' === ( $metadata['Name'] ?? '' ) || 'jocky-image-optimizer' === ( $metadata['TextDomain'] ?? '' ) ) {
					$active  = is_plugin_active( $file );
					$version = $active && defined( 'JIO_VERSION' ) ? (string) constant( 'JIO_VERSION' ) : (string) ( $metadata['Version'] ?? '' );
					return array(
						'active'  => $active,
						'version' => $version,
						'plugin'  => $file,
					);
				}
			}
		}
		return array(
			'active'  => $active,
			'version' => $version,
			'plugin'  => $active ? 'JIO_VERSION' : '',
		);
	}
}
