<?php
/**
 * The array moves every plugin writes again.
 *
 * @package   SugarCommerce\Support
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Support;

use Stringable;

/**
 * Class Arr
 *
 * Reading and writing down dotted paths, picking and dropping keys, sorting,
 * grouping and plucking rows, inserting next to a key, comparing two lists,
 * and turning a key-value map into the `{value, label}` options a select
 * control takes.
 *
 * Pure PHP: nothing here calls a WordPress function, so it can be used and
 * tested anywhere. Every method returns a new array and leaves its input
 * alone.
 *
 * @since 1.0.0
 */
final class Arr {

	/** Selection *************************************************************/

	/**
	 * The first element, or null for an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items The array.
	 *
	 * @return mixed
	 */
	public static function first( array $items ): mixed {
		return array() === $items ? null : reset( $items );
	}

	/**
	 * The last element, or null for an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items The array.
	 *
	 * @return mixed
	 */
	public static function last( array $items ): mixed {
		return array() === $items ? null : end( $items );
	}

	/**
	 * Keep only the listed keys.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items        The array.
	 * @param array $allowed_keys The keys to keep.
	 *
	 * @return array
	 */
	public static function only( array $items, array $allowed_keys ): array {
		return array_intersect_key( $items, array_flip( $allowed_keys ) );
	}

	/**
	 * Drop the listed keys.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items The array.
	 * @param array $keys  The keys to drop.
	 *
	 * @return array
	 */
	public static function except( array $items, array $keys ): array {
		return array_diff_key( $items, array_flip( $keys ) );
	}

	/** Dotted paths **********************************************************/

	/**
	 * Read a value down a dotted path.
	 *
	 * A key that literally contains the dots wins over the path, so a flat
	 * `'user.name'` stays reachable -- and it wins even when its value is
	 * null: a stored null is an answer, not a reason to go and look
	 * somewhere else.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items    The array.
	 * @param string $path     The path, as `a.b.c`.
	 * @param mixed  $fallback What to return when the path is not there.
	 *
	 * @return mixed
	 */
	public static function get( array $items, string $path, mixed $fallback = null ): mixed {
		if ( array_key_exists( $path, $items ) ) {
			return $items[ $path ];
		}

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $items ) || ! array_key_exists( $segment, $items ) ) {
				return $fallback;
			}

			$items = $items[ $segment ];
		}

		return $items;
	}

	/**
	 * Write a value down a dotted path, making the levels it needs.
	 *
	 * A level on the way that holds something other than an array -- a
	 * string, a number, null -- is replaced by an array so the write can go
	 * through. Setting `a.b` on `array( 'a' => 'x' )` gives
	 * `array( 'a' => array( 'b' => … ) )`, and the `'x'` is gone.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items The array.
	 * @param string $path  The path, as `a.b.c`.
	 * @param mixed  $value The value.
	 *
	 * @return array A copy with the value written.
	 */
	public static function set( array $items, string $path, mixed $value ): array {
		$current = &$items;

		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $current ) ) {
				$current = array();
			}

			$current = &$current[ $key ];
		}

		$current = $value;

		return $items;
	}

	/**
	 * Is there a value -- null included -- at a dotted path?
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items The array.
	 * @param string $path  The path, as `a.b.c`.
	 *
	 * @return bool
	 */
	public static function has( array $items, string $path ): bool {
		if ( array_key_exists( $path, $items ) ) {
			return true;
		}

		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $items ) || ! array_key_exists( $key, $items ) ) {
				return false;
			}

			$items = $items[ $key ];
		}

		return true;
	}

	/**
	 * The first of several paths that holds something other than null.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $items    The array.
	 * @param string[] $keys     Paths to try, in order.
	 * @param mixed    $fallback What to return when none of them does.
	 *
	 * @return mixed
	 */
	public static function get_first( array $items, array $keys, mixed $fallback = null ): mixed {
		foreach ( $keys as $key ) {
			$value = self::get( $items, (string) $key );

			if ( null !== $value ) {
				return $value;
			}
		}

		return $fallback;
	}

	/** Sorting ***************************************************************/

	/**
	 * Sort the numbers, dropping anything that is not one.
	 *
	 * Numeric strings are kept and converted. Zero and negatives are numbers
	 * too.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items      The array.
	 * @param bool  $descending Largest first.
	 *
	 * @return array<int|float> The sorted numbers, as a list.
	 */
	public static function sort_numeric( array $items, bool $descending = false ): array {
		$items = array_map(
			static fn( $item ) => $item + 0,
			array_filter( $items, 'is_numeric' )
		);

		if ( $descending ) {
			rsort( $items, SORT_NUMERIC );
		} else {
			sort( $items, SORT_NUMERIC );
		}

		return $items;
	}

	/**
	 * Sort the values, as a list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items      The array.
	 * @param bool  $descending Last first.
	 * @param int   $flags      A `SORT_*` flag.
	 *
	 * @return array
	 */
	public static function sort_alphabetic( array $items, bool $descending = false, int $flags = SORT_REGULAR ): array {
		if ( $descending ) {
			arsort( $items, $flags );
		} else {
			asort( $items, $flags );
		}

		return array_values( $items );
	}

	/**
	 * Sort by key, keeping the keys.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items      The array.
	 * @param bool  $descending Last first.
	 * @param int   $flags      A `SORT_*` flag.
	 *
	 * @return array
	 */
	public static function sort_by_key( array $items, bool $descending = false, int $flags = SORT_REGULAR ): array {
		if ( $descending ) {
			krsort( $items, $flags );
		} else {
			ksort( $items, $flags );
		}

		return $items;
	}

	/**
	 * Sort rows -- arrays or objects -- by one column.
	 *
	 * A row without the column sorts as null, which is before everything.
	 * Building the column with array_column() skipped such rows, leaving it
	 * shorter than the rows, which array_multisort() rejects with a
	 * ValueError.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items      The rows.
	 * @param string $key        The column.
	 * @param bool   $descending Largest first.
	 *
	 * @return array
	 */
	public static function sort_by_column( array $items, string $key, bool $descending = false ): array {
		if ( array() === $items ) {
			return array();
		}

		$column = array_map( static fn( $row ) => self::column( $row, $key ), $items );

		array_multisort( $column, $descending ? SORT_DESC : SORT_ASC, $items );

		return $items;
	}

	/** Rows ******************************************************************/

	/**
	 * Group rows -- arrays or objects -- by one column.
	 *
	 * Rows without the column, or whose value cannot be a key, are grouped
	 * together under `''`.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items The rows.
	 * @param string $key   The column.
	 *
	 * @return array<string|int, array>
	 */
	public static function group_by( array $items, string $key ): array {
		$groups = array();

		foreach ( $items as $item ) {
			$value = self::column( $item, $key );
			$group = is_scalar( $value ) ? (string) $value : '';

			$groups[ $group ][] = $item;
		}

		return $groups;
	}

	/**
	 * One column out of each row, arrays or objects.
	 *
	 * A row without the column gives null, rather than a warning or a gap.
	 * Without an index the rows' own keys are kept; with one, as with
	 * `wp_list_pluck()`, each value is keyed by that column of its row, and
	 * a row whose index is missing or cannot be a key is appended.
	 *
	 * @since 1.0.0
	 *
	 * @param array       $items The rows.
	 * @param string      $key   The column to take.
	 * @param string|null $index The column to key the result by.
	 *
	 * @return array
	 */
	public static function pluck( array $items, string $key, ?string $index = null ): array {
		if ( null === $index ) {
			return array_map( static fn( $item ) => self::column( $item, $key ), $items );
		}

		$plucked = array();

		foreach ( $items as $item ) {
			$at = self::column( $item, $index );

			if ( is_int( $at ) || is_string( $at ) ) {
				$plucked[ $at ] = self::column( $item, $key );
			} else {
				$plucked[] = self::column( $item, $key );
			}
		}

		return $plucked;
	}

	/**
	 * Every leaf value, as one list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items  The array.
	 * @param bool  $unique Drop repeats.
	 *
	 * @return array A list.
	 */
	public static function flatten( array $items, bool $unique = false ): array {
		$flat = array();

		array_walk_recursive(
			$items,
			static function ( $item ) use ( &$flat ): void {
				$flat[] = $item;
			}
		);

		// array_unique() keeps the keys of the survivors, and a list with
		// gaps in it is an object to json_encode().
		return $unique ? array_values( array_unique( $flat ) ) : $flat;
	}

	/** Inserting *************************************************************/

	/**
	 * Insert after a key, or at the end when the key is not there.
	 *
	 * For string-keyed arrays -- a settings tab list, a column list. A key
	 * can only be there once, so when the addition repeats one of the
	 * array's keys, whichever copy comes first in the result is kept: the
	 * array's own before the insertion point, the addition's after it.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items    The array.
	 * @param string $key      The key to insert after.
	 * @param array  $addition What to insert.
	 *
	 * @return array
	 */
	public static function insert_after( array $items, string $key, array $addition ): array {
		$position = array_search( self::normalize_key( $key ), array_keys( $items ), true );
		$position = false === $position ? count( $items ) : $position + 1;

		return array_slice( $items, 0, $position, true ) + $addition + array_slice( $items, $position, null, true );
	}

	/**
	 * Insert before a key, or at the start when the key is not there.
	 *
	 * A repeated key is kept once, as for insert_after().
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items    The array.
	 * @param string $key      The key to insert before.
	 * @param array  $addition What to insert.
	 *
	 * @return array
	 */
	public static function insert_before( array $items, string $key, array $addition ): array {
		$position = array_search( self::normalize_key( $key ), array_keys( $items ), true );
		$position = false === $position ? 0 : $position;

		return array_slice( $items, 0, $position, true ) + $addition + array_slice( $items, $position, null, true );
	}

	/**
	 * Shuffle, as a list.
	 *
	 * PHP's own shuffle(), so `mt_srand()` -- which a seeded faker calls --
	 * makes the order repeat. Not for anything that has to be unguessable.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items The array.
	 *
	 * @return array
	 */
	public static function shuffle( array $items ): array {
		shuffle( $items );

		return $items;
	}

	/** Comparing *************************************************************/

	/**
	 * Are the values of the first array in the second -- all, or any?
	 *
	 * Under "all", every element of the first counts, repeats included, so
	 * `array( 'a', 'a' )` is all in `array( 'a' )`. All of an empty first
	 * array is vacuously present; any of it is not.
	 *
	 * @since 1.0.0
	 *
	 * @param array $array1    What to look for.
	 * @param array $array2    Where to look.
	 * @param bool  $match_all All of them (true) or any of them (false).
	 *
	 * @return bool
	 */
	public static function has_matches( array $array1, array $array2, bool $match_all = true ): bool {
		return $match_all ? self::has_all_matches( $array1, $array2 ) : self::has_any_matches( $array1, $array2 );
	}

	/**
	 * Is every value of the first array in the second?
	 *
	 * @since 1.0.0
	 *
	 * @param array $array1 What to look for.
	 * @param array $array2 Where to look.
	 *
	 * @return bool
	 */
	public static function has_all_matches( array $array1, array $array2 ): bool {
		return count( $array1 ) === count( array_intersect( $array1, $array2 ) );
	}

	/**
	 * Is any value of the first array in the second?
	 *
	 * @since 1.0.0
	 *
	 * @param array $array1 What to look for.
	 * @param array $array2 Where to look.
	 *
	 * @return bool
	 */
	public static function has_any_matches( array $array1, array $array2 ): bool {
		return array() !== array_intersect( $array1, $array2 );
	}

	/**
	 * Is the value in the array, compared strictly?
	 *
	 * @since 1.0.0
	 *
	 * @param array $items  The array.
	 * @param mixed $needle The value.
	 *
	 * @return bool
	 */
	public static function contains( array $items, mixed $needle ): bool {
		return in_array( $needle, $items, true );
	}

	/** Converting ************************************************************/

	/**
	 * Join the values into one delimited string, for a stored setting or a
	 * header.
	 *
	 * Numbers and booleans are cast; a nested array is JSON rather than the
	 * word "Array". For a list a person reads -- "a, b and c" -- use
	 * `SugarCommerce\Text\Text::list()`.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items     The values.
	 * @param string $delimiter Between values.
	 * @param string $wrapper   Around each value.
	 * @param bool   $trim      Trim each value.
	 *
	 * @return string
	 */
	public static function to_string( array $items, string $delimiter = ',', string $wrapper = '', bool $trim = true ): string {
		$parts = array_map(
			static function ( $item ) use ( $wrapper, $trim ): string {
				$item = is_scalar( $item ) || null === $item ? (string) $item : (string) json_encode( $item );
				$item = $trim ? trim( $item ) : $item;

				return $wrapper . $item . $wrapper;
			},
			$items
		);

		return implode( $delimiter, $parts );
	}

	/**
	 * A key-value map as the options a select control takes.
	 *
	 * `array( 'gb' => 'United Kingdom' )` becomes
	 * `array( array( 'value' => 'gb', 'label' => 'United Kingdom' ) )`, the
	 * `{value, label}` shape conditions' and fields' option lists use.
	 *
	 * For rows -- arrays or objects -- name the label's column as
	 * `$label_key`. A label that is not text (an array, an object that cannot
	 * be a string, null, a row without the column) falls back to the key, so
	 * no option ever reads "Array" or is blank.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $items     The map.
	 * @param string $label_key The label's column, for rows.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function to_options( array $items, string $label_key = '' ): array {
		$options = array();

		foreach ( $items as $key => $value ) {
			$label = ( is_array( $value ) || is_object( $value ) ) && '' !== $label_key
				? self::column( $value, $label_key )
				: $value;

			$options[] = array(
				'value' => (string) $key,
				'label' => is_scalar( $label ) || $label instanceof Stringable ? (string) $label : (string) $key,
			);
		}

		return $options;
	}

	/**
	 * Options back into a key-value map. Entries without both a value and a
	 * label are dropped.
	 *
	 * @since 1.0.0
	 *
	 * @param array $options `{value, label}` pairs.
	 *
	 * @return array
	 */
	public static function from_options( array $options ): array {
		$items = array();

		foreach ( $options as $option ) {
			if ( isset( $option['value'], $option['label'] ) ) {
				$items[ $option['value'] ] = $option['label'];
			}
		}

		return $items;
	}

	/** Internals *************************************************************/

	/**
	 * One column of a row that may be an array or an object.
	 *
	 * @param mixed  $row The row.
	 * @param string $key The column.
	 *
	 * @return mixed Null when the row has no such column.
	 */
	private static function column( mixed $row, string $key ): mixed {
		if ( is_array( $row ) ) {
			return $row[ $key ] ?? null;
		}

		if ( is_object( $row ) ) {
			return $row->$key ?? null;
		}

		return null;
	}

	/**
	 * A key the way PHP stores it.
	 *
	 * A key written as '0' is stored as the integer 0, so array_keys()
	 * answers with an int and a strict search for the string misses it.
	 *
	 * @param string $key The key.
	 *
	 * @return int|string
	 */
	private static function normalize_key( string $key ): int|string {
		return ( (string) (int) $key === $key ) ? (int) $key : $key;
	}
}
