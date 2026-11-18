<?php
/**
 * A small WordPress, enough to look things up in.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

// phpcs:disable

class WP_Post {
	public int $ID = 0;
	public string $post_name = '';
	public string $post_title = '';
	public string $post_type = 'post';
	public string $post_status = 'publish';

	public function __construct( array $fields = [] ) {
		foreach ( $fields as $k => $v ) {
			$this->$k = $v;
		}
	}
}

class WP_Term {
	public int $term_id = 0;
	public string $slug = '';
	public string $name = '';
	public string $taxonomy = '';

	public function __construct( array $fields = [] ) {
		foreach ( $fields as $k => $v ) {
			$this->$k = $v;
		}
	}
}

class WP_User {
	public int $ID = 0;
	public string $user_login = '';
	public string $user_email = '';
	public string $user_nicename = '';
	public string $display_name = '';

	public function __construct( array $fields = [] ) {
		foreach ( $fields as $k => $v ) {
			$this->$k = $v;
		}
	}
}

class WP_Error {
	public function __construct( public string $code = 'error', public string $message = '' ) {}
}

/**
 * The stubbed database.
 */
function ou_reset(): void {
	$GLOBALS['ou_posts'] = [];
	$GLOBALS['ou_terms'] = [];
	$GLOBALS['ou_users'] = [];
	$GLOBALS['ou_queries'] = 0;

	// The lookups the library remembers for the rest of a request.
	\SugarCommerce\Support\Posts::reset();
	\SugarCommerce\Support\Terms::reset();
	\SugarCommerce\Support\Users::reset();
}

ou_reset();

function ou_add_post( array $fields ): WP_Post {
	$post = new WP_Post( $fields );
	$GLOBALS['ou_posts'][ $post->ID ] = $post;
	return $post;
}

function ou_add_term( array $fields ): WP_Term {
	$term = new WP_Term( $fields );
	$GLOBALS['ou_terms'][ $term->term_id ] = $term;
	return $term;
}

function ou_add_user( array $fields ): WP_User {
	$user = new WP_User( $fields );
	$GLOBALS['ou_users'][ $user->ID ] = $user;
	return $user;
}

function get_post( $id = null ) {
	return $GLOBALS['ou_posts'][ (int) $id ] ?? null;
}

function get_posts( array $args = [] ) {
	$GLOBALS['ou_queries']++;

	$types   = (array) ( $args['post_type'] ?? 'any' );
	$status  = (array) ( $args['post_status'] ?? 'any' );
	$matched = [];

	foreach ( $GLOBALS['ou_posts'] as $post ) {
		if ( ! in_array( 'any', $types, true ) && ! in_array( $post->post_type, $types, true ) ) {
			continue;
		}
		if ( ! in_array( 'any', $status, true ) && ! in_array( $post->post_status, $status, true ) ) {
			continue;
		}
		if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) {
			continue;
		}
		if ( isset( $args['title'] ) && $post->post_title !== $args['title'] ) {
			continue;
		}
		// Core's `s` searches title, excerpt and content. The stub searches
		// title and slug, which is enough to exercise the callers and lets a
		// post with no title still be findable -- which is the case the
		// option labels care about.
		if ( isset( $args['s'] ) ) {
			$needle   = strtolower( (string) $args['s'] );
			$haystack = strtolower( $post->post_title . ' ' . $post->post_name );

			if ( ! str_contains( $haystack, $needle ) ) {
				continue;
			}
		}
		if ( isset( $args['s'] ) ) {
			$matched[] = $post;
			continue;
		}
		return [ $post->ID ];
	}

	// A search returns objects; a name or title lookup returns one id.
	if ( isset( $args['s'] ) ) {
		$limit = (int) ( $args['posts_per_page'] ?? 0 );

		return $limit > 0 ? array_slice( $matched, 0, $limit ) : $matched;
	}

	return [];
}

function get_term( $id, $taxonomy = '' ) {
	$term = $GLOBALS['ou_terms'][ (int) $id ] ?? null;

	if ( null === $term ) {
		// Core hands back a WP_Error here, which is truthy.
		return new WP_Error( 'invalid_term' );
	}

	return ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ? $term : new WP_Error( 'invalid_taxonomy' );
}

function get_term_by( $field, $value, $taxonomy = '' ) {
	$GLOBALS['ou_queries']++;

	foreach ( $GLOBALS['ou_terms'] as $term ) {
		if ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) {
			continue;
		}
		if ( 'slug' === $field && $term->slug === $value ) {
			return $term;
		}
		if ( 'name' === $field && $term->name === $value ) {
			return $term;
		}
		if ( 'id' === $field && $term->term_id === (int) $value ) {
			return $term;
		}
	}

	return false;
}

function get_userdata( $id ) {
	return $GLOBALS['ou_users'][ (int) $id ] ?? false;
}

function get_user_by( $field, $value ) {
	$GLOBALS['ou_queries']++;

	foreach ( $GLOBALS['ou_users'] as $user ) {
		if ( 'id' === $field && $user->ID === (int) $value ) {
			return $user;
		}
		if ( 'email' === $field && $user->user_email === $value ) {
			return $user;
		}
		if ( 'login' === $field && $user->user_login === $value ) {
			return $user;
		}
		if ( 'slug' === $field && $user->user_nicename === $value ) {
			return $user;
		}
	}

	return false;
}

function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}

function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = preg_replace( '/[^a-z0-9\s-]/', '', $title );
	$title = preg_replace( '/[\s-]+/', '-', (string) $title );

	return trim( (string) $title, '-' );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_terms( array $args = [] ) {
	$taxonomy = $args['taxonomy'] ?? '';
	$search   = strtolower( (string) ( $args['search'] ?? '' ) );
	$number   = (int) ( $args['number'] ?? 0 );

	if ( ! in_array( $taxonomy, array_map( fn( $t ) => $t->taxonomy, $GLOBALS['ou_terms'] ), true ) ) {
		return new WP_Error( 'invalid_taxonomy' );
	}

	$found = [];

	foreach ( $GLOBALS['ou_terms'] as $term ) {
		if ( $term->taxonomy !== $taxonomy ) {
			continue;
		}
		if ( '' !== $search
			&& ! str_contains( strtolower( $term->name ), $search )
			&& ! str_contains( strtolower( $term->slug ), $search ) ) {
			continue;
		}
		$found[] = $term;
		if ( $number > 0 && count( $found ) >= $number ) {
			break;
		}
	}

	return $found;
}

function get_users( array $args = [] ) {
	$search = trim( (string) ( $args['search'] ?? '' ), '*' );
	$search = strtolower( $search );
	$number = (int) ( $args['number'] ?? 0 );

	$found = [];

	foreach ( $GLOBALS['ou_users'] as $user ) {
		$haystack = strtolower( $user->user_login . ' ' . $user->user_nicename . ' ' . $user->user_email . ' ' . ( $user->display_name ?? '' ) );

		if ( '' !== $search && ! str_contains( $haystack, $search ) ) {
			continue;
		}

		$found[] = $user;

		if ( $number > 0 && count( $found ) >= $number ) {
			break;
		}
	}

	return $found;
}
