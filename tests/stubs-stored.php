<?php
/**
 * Stubs for Cache, Cast and Cleanup: an option and transient store kept as
 * core keeps it without an object cache, meta, and a $wpdb that understands
 * LIKE -- including the underscore, which is the point of one of the tests.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

// phpcs:disable

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

/**
 * Forget everything a previous test stored.
 *
 * @return void
 */
function wp_test_reset(): void {
	$GLOBALS['wp_test_options'] = [];
	$GLOBALS['wp_test_meta']    = [];
	$GLOBALS['wpdb']            = new Test_WPDB();
}

/**
 * A $wpdb that answers the prefix queries Cleanup makes.
 */
class Test_WPDB {
	public string $options  = 'wp_options';
	public string $postmeta = 'wp_postmeta';
	public string $prefix   = 'wp_';

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		// The backslashes esc_like() added are kept, so the LIKE can tell an
		// escaped underscore from a wildcard one.
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', "'" . str_replace( '\\', '\\\\', (string) $arg ) . "'", (string) $query, 1 );
		}

		return $query;
	}

	public function get_col( $query ) {
		if ( ! preg_match( "/LIKE '([^']*)'/", (string) $query, $m ) ) {
			return [];
		}

		$source = str_contains( (string) $query, 'meta_key' ) ? $GLOBALS['wp_test_meta'] : $GLOBALS['wp_test_options'];
		$found  = [];

		foreach ( array_keys( $source ) as $name ) {
			if ( self::like( (string) $name, $m[1] ) ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * SQL LIKE, including the part that matters.
	 *
	 * `%` is any run of characters and `_` is any single one. Modelling the
	 * underscore is the whole point: without it a stub cannot tell an escaped
	 * prefix from an unescaped one, and the test that exists to catch
	 * "myplugin_" also matching "mypluginX" passes either way.
	 */
	private static function like( string $subject, string $pattern ): bool {
		$regex = '';

		for ( $i = 0; $i < strlen( $pattern ); $i++ ) {
			$char = $pattern[ $i ];

			if ( '\\' === $char && isset( $pattern[ $i + 1 ] ) ) {
				$regex .= preg_quote( $pattern[ ++$i ], '#' );
				continue;
			}

			$regex .= match ( $char ) {
				'%'     => '.*',
				'_'     => '.',
				default => preg_quote( $char, '#' ),
			};
		}

		return 1 === preg_match( '#^' . $regex . '$#', $subject );
	}
}


/* ─── Options, transients and meta ─────────────────────────────────── */

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['wp_test_options'] ) ? $GLOBALS['wp_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['wp_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
		if ( array_key_exists( $name, $GLOBALS['wp_test_options'] ) ) {
			return false;
		}

		$GLOBALS['wp_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['wp_test_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['wp_test_options'][ $name ] );

		return true;
	}
}

/*
 * Transients are stored as core stores them without an object cache: an
 * option called `_transient_{key}`, so Cleanup's LIKE query can find them.
 */
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['wp_test_options'][ '_transient_' . $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['wp_test_options'][ '_transient_' . $key ] = $value;

		if ( $ttl > 0 ) {
			$GLOBALS['wp_test_options'][ '_transient_timeout_' . $key ] = time() + (int) $ttl;
		}

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		if ( ! array_key_exists( '_transient_' . $key, $GLOBALS['wp_test_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['wp_test_options'][ '_transient_' . $key ], $GLOBALS['wp_test_options'][ '_transient_timeout_' . $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache( $using = null ) {
		return false;
	}
}

if ( ! function_exists( '_get_meta_table' ) ) {
	function _get_meta_table( $type ) {
		// Core's shape: $wpdb->{$type}meta, or false when there is no such
		// property -- the real guard against an arbitrary type reaching an
		// interpolated table name.
		return in_array( $type, [ 'post', 'user', 'term', 'comment' ], true ) ? 'wp_' . $type . 'meta' : false;
	}
}

if ( ! function_exists( 'delete_metadata' ) ) {
	function delete_metadata( $type, $id, $key, $value = '', $all = false ) {
		if ( ! array_key_exists( $key, $GLOBALS['wp_test_meta'] ) ) {
			return false;
		}

		unset( $GLOBALS['wp_test_meta'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
