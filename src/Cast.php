<?php
/**
 * Reading a stored value as the type it is meant to be.
 *
 * @package   SugarCommerce\Support
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Support;

/**
 * Class Cast
 *
 * Options and meta come back as strings, because that is how they went into
 * the database. A checkbox saved as `1` reads back as `"1"`, and a setting
 * that has been through JSON somewhere carries `"false"` -- which PHP reads as
 * true, being a non-empty string.
 *
 * @since 1.0.0
 */
final class Cast {

	/**
	 * As a boolean.
	 *
	 * Wider than PHP's own cast: `"false"`, `"no"` and `"off"` are all false
	 * here and all true to PHP.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return bool
	 */
	public static function bool( mixed $value ): bool {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );

			if ( in_array( $value, array( 'false', 'no', 'off', 'null', '' ), true ) ) {
				return false;
			}
		}

		return (bool) $value;
	}

	/**
	 * As an integer.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value    The stored value.
	 * @param int   $fallback What to use when it is not a number.
	 *
	 * @return int
	 */
	public static function int( mixed $value, int $fallback = 0 ): int {
		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * As a float.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value    The stored value.
	 * @param float $fallback What to use when it is not a number.
	 *
	 * @return float
	 */
	public static function float( mixed $value, float $fallback = 0.0 ): float {
		return is_numeric( $value ) ? (float) $value : $fallback;
	}

	/**
	 * As a list.
	 *
	 * A stored list arrives as an array, as a serialised array core has
	 * already unserialised, as a JSON array a custom column holds, or as a
	 * comma-separated string somebody typed into a text field.
	 *
	 * A string that opens with `[` or `{` is read as JSON, so a list stored by
	 * `wp_json_encode()` and one posted by a field read back the same and a
	 * caller does not have to know which side of the round trip it is on. When
	 * that JSON is broken the list is empty: splitting `["a","b"` on commas
	 * would invent values nobody stored.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, mixed>
	 */
	public static function array( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}

		if ( null === $value || '' === $value || false === $value ) {
			return array();
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );

			if ( str_starts_with( $value, '[' ) || str_starts_with( $value, '{' ) ) {
				$decoded = json_decode( $value, true );

				return is_array( $decoded ) ? array_values( $decoded ) : array();
			}

			return array_values(
				array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( $one ) => '' !== $one )
			);
		}

		return array( $value );
	}

	/**
	 * As a list of positive integers.
	 *
	 * What an "which products" setting holds, and the shape a meta query
	 * wants. Zeroes and duplicates are dropped, because neither is an id: nought
	 * is an attachment or post id that resolves to nothing. Entries that are not
	 * scalars are dropped too, rather than read as `1` the way PHP casts a
	 * non-empty array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The stored value: an array, a JSON array or a
	 *                     comma-separated string.
	 *
	 * @return array<int, int>
	 */
	public static function ids( mixed $value ): array {
		$ids = array_map( 'absint', array_filter( self::array( $value ), 'is_scalar' ) );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * As a list of non-blank strings.
	 *
	 * A list of short labels -- feature bullets, event names, the choices of
	 * a question -- read back from any shape `array()` accepts. Each entry is
	 * trimmed, then passed through `$clean` when one is given (for example
	 * `'sanitize_text_field'`), and the blank ones are dropped after cleaning:
	 * an entry nobody typed is not an entry, and one that cleaned away to
	 * nothing is not either. Duplicates are dropped, first one kept.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed         $value The stored value.
	 * @param callable|null $clean Optional cleaner, called with each trimmed
	 *                             entry and returning a string.
	 *
	 * @return array<int, string>
	 */
	public static function strings( mixed $value, ?callable $clean = null ): array {
		$strings = array();

		foreach ( self::array( $value ) as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}

			$entry = trim( (string) $entry );

			if ( null !== $clean ) {
				$entry = trim( (string) $clean( $entry ) );
			}

			if ( '' !== $entry ) {
				$strings[] = $entry;
			}
		}

		return array_values( array_unique( $strings ) );
	}
}
