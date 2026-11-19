<?php
/**
 * Removing what a plugin left behind.
 *
 * @package   SugarCommerce\Support
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Support;

/**
 * Class Cleanup
 *
 * Uninstalling means deleting every option and transient a plugin created, and
 * core gives you no way to ask for them: `delete_option()` takes one name, and
 * a plugin with forty settings does not know all forty by the time somebody
 * uninstalls it.
 *
 * So these query by prefix, which is the one thing that needs SQL. Everything
 * else in this library is a wrapper somebody else already wrote.
 *
 * @since 1.0.0
 */
final class Cleanup {

	/**
	 * Delete every option whose name begins with a prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix The prefix, e.g. `myplugin_`.
	 *
	 * @return int How many were deleted.
	 */
	public static function options( string $prefix ): int {
		global $wpdb;

		if ( '' === trim( $prefix ) ) {
			return 0;
		}

		// esc_like() first, so an underscore in the prefix is a literal
		// underscore rather than SQL's single-character wildcard. Almost
		// every plugin prefix ends in one, so getting this wrong deletes
		// `myplugin_x` when asked for `myplugin` -- and a good deal more.
		$like = $wpdb->esc_like( $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finding names by prefix has no API; nothing is cached because everything found is deleted next.
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		$deleted = 0;

		foreach ( (array) $names as $name ) {
			// Through delete_option() rather than a DELETE, so the option
			// cache is invalidated and the hooks other plugins rely on fire.
			if ( delete_option( (string) $name ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Delete every transient whose key begins with a prefix.
	 *
	 * Transients are stored as two options apiece -- the value and its expiry
	 * -- so the query looks for the value rows and hands the bare key to
	 * delete_transient(), which removes both.
	 *
	 * Sites with a persistent object cache keep transients out of the options
	 * table entirely, and there is no way to enumerate them. On those, this
	 * deletes whatever did land in the database and returns that count.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix The prefix, e.g. `myplugin_`.
	 *
	 * @return int How many were deleted.
	 */
	public static function transients( string $prefix ): int {
		global $wpdb;

		if ( '' === trim( $prefix ) ) {
			return 0;
		}

		$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finding names by prefix has no API; nothing is cached because everything found is deleted next.
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);

		$deleted = 0;

		foreach ( (array) $names as $name ) {
			$key = substr( (string) $name, strlen( '_transient_' ) );

			if ( delete_transient( $key ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Delete every meta value on a post type whose key begins with a prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix The prefix, e.g. `_myplugin_`.
	 * @param string $type   'post', 'user', 'term' or 'comment'.
	 *
	 * @return int How many were deleted.
	 */
	public static function meta( string $prefix, string $type = 'post' ): int {
		global $wpdb;

		if ( '' === trim( $prefix ) ) {
			return 0;
		}

		// The type is checked against a fixed list before its table name is
		// interpolated. $wpdb->prepare() cannot parameterise a table name, so
		// the only safe interpolation is one where the value provably came
		// from here rather than from a caller.
		if ( ! in_array( $type, array( 'post', 'user', 'term', 'comment' ), true ) ) {
			return 0;
		}

		$table = _get_meta_table( $type );

		if ( ! $table ) {
			return 0;
		}

		$like = $wpdb->esc_like( $prefix ) . '%';

		// delete_metadata() with $delete_all removes every row for the key
		// across every object, and invalidates the caches. The query is only
		// to find the keys.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finding names by prefix has no API; nothing is cached because everything found is deleted next.
		$keys = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from _get_meta_table() after the type was checked against a fixed list above.
			$wpdb->prepare( "SELECT DISTINCT meta_key FROM {$table} WHERE meta_key LIKE %s", $like )
		);

		$deleted = 0;

		foreach ( (array) $keys as $key ) {
			if ( delete_metadata( $type, 0, (string) $key, '', true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}
}
