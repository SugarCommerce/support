<?php
/**
 * Finding a term by whatever you were handed.
 *
 * @package   SugarCommerce\Support
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Support;

use WP_Term;

/**
 * Class Terms
 *
 * The same problem as posts and a sharper edge: get_term_by() takes the field
 * as an argument, so calling it means deciding first whether the thing in your
 * hand is an id, a slug or a name. A CSV column headed "category" contains all
 * three.
 *
 * It also returns false, null *or* a WP_Error depending on how it fails, which
 * is three things to check at every call site.
 *
 * @since 1.0.0
 */
final class Terms {

	/**
	 * Slug and name lookups already answered this request, as ids.
	 *
	 * @var array<string, int>
	 */
	private static array $memo = array();

	/**
	 * Find a term.
	 *
	 * Tried in order: an object as given (refused when it is from another
	 * taxonomy), a numeric id, a slug, a name. A slug or name that resolved
	 * is remembered for the rest of the request; one that missed is not.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $identifier A WP_Term, an id, a slug or a name.
	 * @param string $taxonomy   The taxonomy to look in.
	 *
	 * @return WP_Term|null
	 */
	public static function find( mixed $identifier, string $taxonomy ): ?WP_Term {
		if ( '' === trim( $taxonomy ) ) {
			return null;
		}

		if ( $identifier instanceof WP_Term ) {
			return $identifier->taxonomy === $taxonomy ? $identifier : null;
		}

		if ( is_object( $identifier ) && isset( $identifier->term_id ) ) {
			return self::find( $identifier->term_id, $taxonomy );
		}

		if ( is_numeric( $identifier ) ) {
			return self::clean( get_term( (int) $identifier, $taxonomy ) );
		}

		if ( ! is_string( $identifier ) || '' === trim( $identifier ) ) {
			return null;
		}

		$identifier = trim( $identifier );
		$memo_key   = $taxonomy . "\0" . $identifier;

		if ( isset( self::$memo[ $memo_key ] ) ) {
			$term = self::clean( get_term( self::$memo[ $memo_key ], $taxonomy ) );

			if ( null !== $term ) {
				return $term;
			}

			unset( self::$memo[ $memo_key ] );
		}

		$term = self::clean( get_term_by( 'slug', $identifier, $taxonomy ) )
			?? self::clean( get_term_by( 'name', $identifier, $taxonomy ) );

		if ( null !== $term ) {
			self::$memo[ $memo_key ] = (int) $term->term_id;
		}

		return $term;
	}

	/**
	 * Find several, dropping what does not resolve.
	 *
	 * Keyed on the id as it goes, so a term named twice comes back once,
	 * where it was first named.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $identifiers  Ids, slugs, names or objects.
	 * @param string $taxonomy     The taxonomy to look in.
	 * @param bool   $with_objects Return WP_Term objects rather than ids.
	 *
	 * @return array<int, int|WP_Term>
	 */
	public static function find_many( array $identifiers, string $taxonomy, bool $with_objects = false ): array {
		$found = array();

		foreach ( $identifiers as $identifier ) {
			$term = self::find( $identifier, $taxonomy );

			if ( null !== $term && ! isset( $found[ $term->term_id ] ) ) {
				$found[ $term->term_id ] = $term;
			}
		}

		return $with_objects ? array_values( $found ) : array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Does this resolve to a term?
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $identifier A WP_Term, an id, a slug or a name.
	 * @param string $taxonomy   The taxonomy to look in.
	 *
	 * @return bool
	 */
	public static function exists( mixed $identifier, string $taxonomy ): bool {
		return null !== self::find( $identifier, $taxonomy );
	}

	/**
	 * The id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $identifier A WP_Term, an id, a slug or a name.
	 * @param string $taxonomy   The taxonomy to look in.
	 *
	 * @return int|null
	 */
	public static function id( mixed $identifier, string $taxonomy ): ?int {
		return self::find( $identifier, $taxonomy )?->term_id;
	}

	/**
	 * Search within a taxonomy, for an autocomplete.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search   What was typed.
	 * @param string $taxonomy The taxonomy to look in.
	 * @param int    $limit    How many at most.
	 *
	 * @return WP_Term[]
	 */
	public static function search( string $search, string $taxonomy, int $limit = 20 ): array {
		$search = trim( $search );

		if ( '' === $search || '' === trim( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'search'     => $search,
				'hide_empty' => false,
				'number'     => max( 1, $limit ),
			)
		);

		// get_terms() returns a WP_Error for an unregistered taxonomy, which
		// is truthy and is not an array.
		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ) => $t instanceof WP_Term ) ) : array();
	}

	/**
	 * Search, shaped for a select control.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search   What was typed.
	 * @param string $taxonomy The taxonomy to look in.
	 * @param int    $limit    How many at most.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public static function search_options( string $search, string $taxonomy, int $limit = 20 ): array {
		return array_map(
			static fn( WP_Term $term ): array => array(
				'value' => (int) $term->term_id,
				'label' => $term->name,
			),
			self::search( $search, $taxonomy, $limit )
		);
	}

	/**
	 * Forget the lookups remembered this request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * One kind of nothing.
	 *
	 * The term functions return false, null or a WP_Error depending on how
	 * they fail, and a WP_Error is truthy -- so `if ( $term )` is true for a
	 * term that was not found.
	 *
	 * @param mixed $term Whatever came back.
	 *
	 * @return WP_Term|null
	 */
	private static function clean( mixed $term ): ?WP_Term {
		return $term instanceof WP_Term ? $term : null;
	}
}
