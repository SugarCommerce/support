<?php
/**
 * Cache or compute.
 *
 * @package   SugarCommerce\Support
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Support;

/**
 * Class Cache
 *
 * The one transient operation core does not have: fetch it, or work it out and
 * keep it.
 *
 * It looks like three lines and is not, because `get_transient()` returns
 * `false` both for "no such transient" and for "the transient holds false".
 * Written the obvious way, a cached `false` is never a hit -- so a lookup that
 * legitimately answers "no" is recomputed on every request, for ever, which is
 * exactly the call somebody wrapped in a cache to avoid.
 *
 * @since 1.0.0
 */
final class Cache {

	/**
	 * Marks a stored value, so a stored `false` is distinguishable from a
	 * missing one.
	 *
	 * The value is wrapped rather than a second "does it exist" transient
	 * being kept: two transients can expire independently, and then the
	 * marker says yes while the value is gone.
	 */
	private const string WRAPPER = '__storage_utils_v1';

	/**
	 * Fetch a value, or work it out and keep it.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $key      Transient key.
	 * @param callable $callback Produces the value when it is not cached.
	 * @param int      $ttl      Seconds to keep it. 0 means until something
	 *                           clears it.
	 *
	 * @return mixed
	 */
	public static function remember( string $key, callable $callback, int $ttl = HOUR_IN_SECONDS ) {
		$stored = get_transient( $key );

		if ( is_array( $stored ) && array_key_exists( self::WRAPPER, $stored ) ) {
			return $stored[ self::WRAPPER ];
		}

		$value = $callback();

		self::set( $key, $value, $ttl );

		return $value;
	}

	/**
	 * A kept value, or a default when there is none.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Transient key.
	 * @param mixed  $default What to return when nothing is kept.
	 *
	 * @return mixed A kept `false` is `false`, not the default.
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$stored = get_transient( $key );

		return is_array( $stored ) && array_key_exists( self::WRAPPER, $stored ) ? $stored[ self::WRAPPER ] : $default;
	}

	/**
	 * Keep a value, whatever it is.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value The value; `false` and `null` are kept too.
	 * @param int    $ttl   Seconds to keep it. 0 means until something
	 *                      clears it.
	 *
	 * @return bool Whether it was kept.
	 */
	public static function set( string $key, mixed $value, int $ttl = HOUR_IN_SECONDS ): bool {
		return (bool) set_transient( $key, array( self::WRAPPER => $value ), max( 0, $ttl ) );
	}

	/**
	 * Whether a key currently holds something.
	 *
	 * Distinct from `false !== get_transient()`, for the same reason
	 * remember() exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Transient key.
	 *
	 * @return bool
	 */
	public static function has( string $key ): bool {
		$stored = get_transient( $key );

		return is_array( $stored ) && array_key_exists( self::WRAPPER, $stored );
	}

	/**
	 * Throw a value away.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Transient key.
	 *
	 * @return bool
	 */
	public static function forget( string $key ): bool {
		return (bool) delete_transient( $key );
	}
}
