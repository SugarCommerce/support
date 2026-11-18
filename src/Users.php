<?php
/**
 * Finding a user by whatever you were handed.
 *
 * @package   SugarCommerce\Support
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Support;

use WP_User;

/**
 * Class Users
 *
 * A user arrives as an id from a post, an email from a checkout, a login from
 * a form, and a WP_User from a hook. get_user_by() wants to be told which.
 *
 * The email case is the one worth having: an order or a subscription is keyed
 * by email far more often than by id, and asking get_user_by( 'login', … ) for
 * an email address quietly finds nothing.
 *
 * @since 1.0.0
 */
final class Users {

	/**
	 * Email, login and slug lookups already answered this request, as ids.
	 *
	 * @var array<string, int>
	 */
	private static array $memo = array();

	/**
	 * Find a user.
	 *
	 * Tried in order: an object as given, a numeric id, an email, a login, a
	 * nicename. Something shaped like an email is looked up as one and only
	 * as one. Zero is nobody, not the current user. An email, login or
	 * nicename that resolved is remembered for the rest of the request; one
	 * that missed is not.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $identifier A WP_User, an id, an email, a login or a slug.
	 *
	 * @return WP_User|null
	 */
	public static function find( mixed $identifier ): ?WP_User {
		if ( $identifier instanceof WP_User ) {
			return $identifier;
		}

		if ( is_object( $identifier ) && isset( $identifier->ID ) ) {
			return self::find( $identifier->ID );
		}

		if ( is_numeric( $identifier ) ) {
			// get_userdata( 0 ) returns false, and that is the honest answer
			// -- a caller who means "whoever is logged in" should say so
			// rather than pass a falsy id and hope.
			return self::clean( get_userdata( (int) $identifier ) );
		}

		if ( ! is_string( $identifier ) || '' === trim( $identifier ) ) {
			return null;
		}

		$identifier = trim( $identifier );

		if ( isset( self::$memo[ $identifier ] ) ) {
			$user = self::clean( get_userdata( self::$memo[ $identifier ] ) );

			if ( null !== $user ) {
				return $user;
			}

			unset( self::$memo[ $identifier ] );
		}

		$user = is_email( $identifier )
			? self::clean( get_user_by( 'email', $identifier ) )
			: ( self::clean( get_user_by( 'login', $identifier ) ) ?? self::clean( get_user_by( 'slug', $identifier ) ) );

		if ( null !== $user ) {
			self::$memo[ $identifier ] = (int) $user->ID;
		}

		return $user;
	}

	/**
	 * Find several, dropping what does not resolve.
	 *
	 * Keyed on the id as it goes, so a user named twice -- by id and by
	 * email -- comes back once, where they were first named.
	 *
	 * @since 1.0.0
	 *
	 * @param array $identifiers  Ids, emails, logins or objects.
	 * @param bool  $with_objects Return WP_User objects rather than ids.
	 *
	 * @return array<int, int|WP_User>
	 */
	public static function find_many( array $identifiers, bool $with_objects = false ): array {
		$found = array();

		foreach ( $identifiers as $identifier ) {
			$user = self::find( $identifier );

			if ( null !== $user && ! isset( $found[ $user->ID ] ) ) {
				$found[ $user->ID ] = $user;
			}
		}

		return $with_objects ? array_values( $found ) : array_map( 'intval', array_keys( $found ) );
	}

	/**
	 * Does this resolve to a user?
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $identifier A WP_User, an id, an email, a login or a slug.
	 *
	 * @return bool
	 */
	public static function exists( mixed $identifier ): bool {
		return null !== self::find( $identifier );
	}

	/**
	 * The id, or null.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $identifier A WP_User, an id, an email, a login or a slug.
	 *
	 * @return int|null
	 */
	public static function id( mixed $identifier ): ?int {
		return self::find( $identifier )?->ID;
	}

	/**
	 * Search, for an autocomplete.
	 *
	 * Across login, nicename, email and display name, because which of them a
	 * person is typing is not knowable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search What was typed.
	 * @param int    $limit  How many at most.
	 *
	 * @return WP_User[]
	 */
	public static function search( string $search, int $limit = 20 ): array {
		$search = trim( $search );

		if ( '' === $search ) {
			return array();
		}

		return get_users(
			array(
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
				'number'         => max( 1, $limit ),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Search, shaped for a select control.
	 *
	 * The label carries the email as well as the name: two customers called
	 * "John Smith" are indistinguishable otherwise, and picking the wrong one
	 * attaches an order to a stranger.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search What was typed.
	 * @param int    $limit  How many at most.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	public static function search_options( string $search, int $limit = 20 ): array {
		return array_map(
			static function ( WP_User $user ): array {
				$name = trim( (string) ( $user->display_name ?? '' ) ) ?: $user->user_login;

				return array(
					'value' => (int) $user->ID,
					'label' => '' !== trim( (string) $user->user_email )
						? sprintf( '%s (%s)', $name, $user->user_email )
						: $name,
				);
			},
			self::search( $search, $limit )
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
	 * @param mixed $user Whatever came back.
	 *
	 * @return WP_User|null
	 */
	private static function clean( mixed $user ): ?WP_User {
		return $user instanceof WP_User ? $user : null;
	}
}
