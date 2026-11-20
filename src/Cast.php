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

use DateTimeImmutable;
use Exception;
use WP_Error;

/**
 * Class Cast
 *
 * Options and meta come back as strings, because that is how they went into
 * the database. A checkbox saved as `1` reads back as `"1"`, and a setting
 * that has been through JSON somewhere carries `"false"` -- which PHP reads as
 * true, being a non-empty string.
 *
 * to() is the strict reading of a value somebody typed -- a CSV cell, a form
 * field -- as a declared type: forgiving about how it is written, never
 * guessing what it means. sugarcommerce/importers casts every cell with it.
 *
 * @since 1.0.0
 */
final class Cast {

	/**
	 * Words for yes.
	 *
	 * @var string[]
	 */
	private const array TRUE = [ '1', 'true', 'yes', 'y', 'on', 't' ];

	/**
	 * Words for no.
	 *
	 * @var string[]
	 */
	private const array FALSE = [ '0', 'false', 'no', 'n', 'off', 'f' ];

	/**
	 * The types this class knows how to cast.
	 *
	 * @var string[]
	 */
	public const array SCALARS = [ 'string', 'number', 'integer', 'boolean', 'email', 'url', 'currency', 'country', 'date', 'datetime' ];

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
				// Validated first: a malformed or absurdly nested string is
				// refused without building half a structure to throw away.
				if ( ! json_validate( $value ) ) {
					return array();
				}

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

	/**
	 * Cast one value read as text -- a CSV cell, a form field -- to the type
	 * it is declared as.
	 *
	 * Forgiving about *shape*, strict about *meaning*: `£1,299.00` is
	 * 1299.0, but `banana` is handed back untouched rather than becoming 0,
	 * and a yes/no that is neither is an error rather than a no. A value
	 * handed back unchanged is one the caller should refuse.
	 *
	 *     Cast::to( '£1,299.00', 'number' );                               // 1299.0
	 *     Cast::to( 'Ys', 'boolean' );                                     // WP_Error
	 *     Cast::to( '03/04/2026', 'date', [ 'date_format' => 'd/m/Y' ] );  // '2026-04-03'
	 *
	 * @since 1.1.0
	 *
	 * @param mixed                $value The raw cell.
	 * @param string               $type  The declared type.
	 * @param array<string, mixed> $field The field's declaration.
	 *
	 * @return mixed|WP_Error The value, or an error when it cannot mean what it says.
	 */
	public static function to( mixed $value, string $type, array $field = [] ): mixed {
		if ( null === $value || '' === $value ) {
			return $value;
		}

		return match ( $type ) {
			'number'   => self::number( $value ),
			'integer'  => self::integer( $value ),
			'boolean'  => self::boolean( $value ),
			// A code, and nothing more can be said about it here. Whether it
			// is a code that exists is the caller's question.
			'currency',
			'country'  => strtoupper( trim( (string) $value ) ),
			'date'     => self::date( (string) $value, $field, 'Y-m-d' ),
			'datetime' => self::date( (string) $value, $field, 'Y-m-d H:i:s' ),
			default    => (string) $value,
		};
	}

	/**
	 * A number, however a spreadsheet wrote it.
	 *
	 * Currency symbols, thousands separators and stray spaces come off. What
	 * is left has to be a number — the value is handed back untouched when it
	 * is not, so the caller reports it against the field's own label rather than
	 * this returning 0 and the import storing a price of nothing.
	 *
	 * @param mixed $value The raw cell.
	 *
	 * @return float|mixed
	 */
	private static function number( mixed $value ): mixed {
		$cleaned = preg_replace( '/[^\d.\-+eE]/', '', (string) $value ) ?? '';

		return is_numeric( $cleaned ) ? (float) $cleaned : $value;
	}

	/**
	 * A whole number.
	 *
	 * The decimal point is kept while cleaning, not stripped: a spreadsheet
	 * writes a quantity of twelve as `12.00` often enough that refusing it
	 * would be useless, and stripping the point turns `12.9` into a hundred
	 * and twenty nine.
	 *
	 * So `12.00` is twelve, and `12.9` is handed back untouched for the caller to
	 * report — truncating it silently is the same mistake as reading a typo
	 * in a Yes/No column as No.
	 *
	 * @param mixed $value The raw cell.
	 *
	 * @return int|mixed
	 */
	private static function integer( mixed $value ): mixed {
		$cleaned = preg_replace( '/[^\d.\-+]/', '', (string) $value ) ?? '';

		if ( ! is_numeric( $cleaned ) ) {
			return $value;
		}

		return (float) $cleaned === floor( (float) $cleaned ) ? (int) (float) $cleaned : $value;
	}

	/**
	 * Yes or no, and an error for anything that is neither.
	 *
	 * The old version took a list of words for yes and called everything else
	 * no, so a typo in a Yes/No column imported as No and said nothing. A
	 * cell that means neither is a mistake in the file, and the reader is the
	 * only one who can fix it.
	 *
	 * @param mixed $value The raw cell.
	 *
	 * @return bool|WP_Error
	 */
	private static function boolean( mixed $value ): bool|WP_Error {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$word = strtolower( trim( (string) $value ) );

		if ( in_array( $word, self::TRUE, true ) ) {
			return true;
		}

		if ( in_array( $word, self::FALSE, true ) ) {
			return false;
		}

		return new WP_Error(
			'invalid_boolean',
			sprintf(
				/* translators: 1: the value found in the file, 2: the words that are accepted. */
				__( '"%1$s" is not a yes or a no. Use one of: %2$s.', 'sugarcommerce' ),
				$value,
				implode( ', ', array_merge( self::TRUE, self::FALSE ) )
			)
		);
	}

	/**
	 * A date, normalised.
	 *
	 * In the site's timezone: a value with no timezone is read as site time
	 * and written back as it was, so a file that means local time still
	 * means local time. A value that names its offset is that moment,
	 * written in site time. Parsing in UTC instead turned "today" into
	 * UTC's date, and wrote an offset value in UTC as if it were local.
	 *
	 * @param string               $value  The raw cell.
	 * @param array<string, mixed> $field  The field's declaration.
	 * @param string               $format What to write it back as.
	 *
	 * @return string|mixed
	 */
	private static function date( string $value, array $field, string $format ): mixed {
		$expected = (string) ( $field['date_format'] ?? '' );
		$timezone = wp_timezone();

		// A declared input format is a promise about the file. Anything that
		// does not keep it is handed back for the caller to report, rather than
		// being parsed by a looser reading that happens to work — which is
		// how 03/04/2026 imports as the fourth of March in one file and the
		// third of April in the next.
		if ( '' !== $expected ) {
			$parsed = DateTimeImmutable::createFromFormat( '!' . $expected, $value, $timezone );

			// Round-tripped, because createFromFormat is lenient: it reads
			// 2026-13-45 against Y-m-d and rolls it forward into 2027. A
			// value that does not come back as it went in did not match.
			return false !== $parsed && $parsed->format( $expected ) === $value
				? $parsed->setTimezone( $timezone )->format( $format )
				: $value;
		}

		try {
			$parsed = new DateTimeImmutable( $value, $timezone );
		} catch ( Exception ) {
			return $value;
		}

		return $parsed->setTimezone( $timezone )->format( $format );
	}
}
