<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		if ( 'jmo_settings' === $option && isset( $GLOBALS['jmo_settings'] ) ) {
			return $GLOBALS['jmo_settings'];
		}
		return $GLOBALS['jmo_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, ?string $domain = null ): string {
		return $text;
	}
}
