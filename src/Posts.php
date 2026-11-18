<?php
/**
 * Finding a post by whatever you were handed.
 *
 * @package   SugarCommerce\Support
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Support;

use WP_Post;

/**
 * Class Posts
 *
 * A post arrives as an id from one place, a slug from a URL, a title from a
 * CSV, and a WP_Post from a hook. Core has a different function for each --
 * get_post(), get_page_by_path(), a WP_Query with `title` -- and each returns
 * a different kind of nothing when it misses.
 *
 * This is one way in and one kind of nothing.
 *
 * @since 1.0.0
 */
final class Posts {

	/**
	 * Slug and title lookups already answered this request, as ids.
	 *
	 * @var array<string, int>
	 */
	private static array $memo = array();

	/**
	 * Find a post.
	 *
	 * Tried in order: an object as given, a numeric id, a slug, a title. The
	 * order matters -- a numeric slug is vanishingly rare and a slug that
	 * matches a title is not, so the cheap exact lookups come first.
	 *
	 * The type and status are checked whichever way the post was named, an
	 * id or an object included: `find( 12, 'product' )` is null when post 12
	 * is a page. Leave them as 'any' to take a post whatever it is.
	 *
	 * A slug or title that resolved is remembered for the rest of the
	 * request (see reset()). One that missed is not, so a caller that looks
	 * something up, creates it and looks again finds it the second time.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $identifier A WP_Post, an id, a slug or a title.
	 * @param string|string[] $post_type  The types to accept. 'any' for all.
	 * @param string|string[] $status     The statuses to accept. 'any' for all.
	 *
	 * @return WP_Post|null
	 */
	public static function find( mixed $identifier, string|array $post_type = 'any', string|array $status = 'any' ): ?WP_Post {
		if ( $identifier instanceof WP_Post ) {
			return self::matches( $identifier, $post_type, $status ) ? $identifier : null;
		}

		if ( is_object( $identifier ) && isset( $identifier->ID ) ) {
			return self::find( $identifier->ID, $post_type, $status );
		}

		if ( is_numeric( $identifier ) ) {
			return self::load( (int) $identifier, $post_type, $status );
		}

		if ( ! is_string( $identifier ) || '' === trim( $identifier ) ) {
			return null;
		}

		$identifier = trim( $identifier );
		$memo_key   = (string) json_encode( array( $identifier, (array) $post_type, (array) $status ) );

		if ( isset( self::$memo[ $memo_key ] ) ) {
			$post = self::load( self::$memo[ $memo_key ], $post_type, $status );

			if ( null !== $post ) {
				return $post;
			}

			// Deleted, retyped or unpublished since: look again.
			unset( self::$memo[ $memo_key ] );
		}

		$args = array(
			'post_type'              => $post_type,
			'post_status'            => $status,
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'fields'                 => 'ids',
		);

		// By slug, then by exact title. sanitize_title() on the way in so a
		// title handed here still matches the slug it produced.
		$found = get_posts( $args + array( 'name' => sanitize_title( $identifier ) ) );

		if ( empty( $found ) ) {
			$found = get_posts( $args + array( 'title' => $identifier ) );
		}

		if ( empty( $found ) ) {
			return null;
		}

		$post = self::load( (int) $found[0], $post_type, $status );

		if ( null !== $post ) {
			self::$memo[ $memo_key ] = $post->ID;
		}

		return $post;
	}

	/**
	 * Find several, dropping what does not resolve.
	 *
	 * Keyed on the id as it goes, so the same post named twice -- once by id
	 * and once by slug -- comes back once, where it was first named.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $identifiers  Ids, slugs, titles or objects.
	 * @param string|string[] $post_type    The types to accept. 'any' for all.
	 * @param bool            $with_objects Return WP_Post objects rather than ids.
	 * @param string|string[] $status       The statuses to accept. 'any' for all.
	 *
	 * @return array<int, int|WP_Post>
	 */
	public static function find_many( array $identifiers, string|array $post_type = 'any', bool $with_objects = false, string|array $status = 'any' ): array {
		$found = array();

		foreach ( $identifiers as $identifier ) {
			$post = self::find( $identifier, $post_type, $status );

			if ( null !== $post && ! isset( $found[ $post->ID ] ) ) {
				$found[ $post->ID ] = $post;
			}
		}

		return $with_objects ? array_values( $found ) : array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Does this resolve to a post?
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $identifier A WP_Post, an id, a slug or a title.
	 * @param string|string[] $post_type  The types to accept. 'any' for all.
	 * @param string|string[] $status     The statuses to accept. 'any' for all.
	 *
	 * @return bool
	 */
	public static function exists( mixed $identifier, string|array $post_type = 'any', string|array $status = 'any' ): bool {
		return null !== self::find( $identifier, $post_type, $status );
	}

	/**
	 * The id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $identifier A WP_Post, an id, a slug or a title.
	 * @param string|string[] $post_type  The types to accept. 'any' for all.
	 * @param string|string[] $status     The statuses to accept. 'any' for all.
	 *
	 * @return int|null
	 */
	public static function id( mixed $identifier, string|array $post_type = 'any', string|array $status = 'any' ): ?int {
		return self::find( $identifier, $post_type, $status )?->ID;
	}

	/**
	 * Search published posts, for an autocomplete.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $search    What was typed.
	 * @param string|string[] $post_type Which types to look in.
	 * @param int             $limit     How many at most.
	 *
	 * @return WP_Post[]
	 */
	public static function search( string $search, string|array $post_type = 'any', int $limit = 20 ): array {
		$search = trim( $search );

		// An empty search returns nothing rather than everything. The
		// difference matters where this feeds a dropdown: a blank box should
		// not render every post on the site.
		if ( '' === $search ) {
			return array();
		}

		return get_posts(
			array(
				's'                      => $search,
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, $limit ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Search, shaped for a select control.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $search    What was typed.
	 * @param string|string[] $post_type Which types to look in.
	 * @param int             $limit     How many at most.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public static function search_options( string $search, string|array $post_type = 'any', int $limit = 20 ): array {
		return array_map(
			static fn( WP_Post $post ): array => array(
				'value' => (int) $post->ID,
				// A post with no title is "(no title)" in the admin and an
				// empty option here, which is unpickable.
				'label' => '' !== trim( (string) $post->post_title )
					? $post->post_title
					: sprintf( '#%d', $post->ID ),
			),
			self::search( $search, $post_type, $limit )
		);
	}

	/**
	 * Forget the lookups remembered this request.
	 *
	 * For tests, and for a long-running process -- a CLI import, a queue
	 * worker -- that renames or deletes posts as it goes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * A post by id, if it is one of the types and statuses asked for.
	 *
	 * @param int             $id        The id.
	 * @param string|string[] $post_type The types to accept.
	 * @param string|string[] $status    The statuses to accept.
	 *
	 * @return WP_Post|null
	 */
	private static function load( int $id, string|array $post_type, string|array $status ): ?WP_Post {
		$post = get_post( $id );

		return $post instanceof WP_Post && self::matches( $post, $post_type, $status ) ? $post : null;
	}

	/**
	 * Is the post one of the types and statuses asked for?
	 *
	 * @param WP_Post         $post      The post.
	 * @param string|string[] $post_type The types to accept.
	 * @param string|string[] $status    The statuses to accept.
	 *
	 * @return bool
	 */
	private static function matches( WP_Post $post, string|array $post_type, string|array $status ): bool {
		$types    = (array) $post_type;
		$statuses = (array) $status;

		return ( in_array( 'any', $types, true ) || in_array( $post->post_type, $types, true ) )
			&& ( in_array( 'any', $statuses, true ) || in_array( $post->post_status, $statuses, true ) );
	}
}
