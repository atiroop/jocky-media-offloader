<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $option === 'jmo_settings' && isset( $GLOBALS['jmo_settings'] ) ? $GLOBALS['jmo_settings'] : $default;
	}
}
