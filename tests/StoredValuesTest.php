<?php
/**
 * Cache, Cast and Cleanup tests.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

namespace SugarCommerce\Support\Tests;

use SugarCommerce\Support\Cache;
use SugarCommerce\Support\Cast;
use SugarCommerce\Support\Cleanup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Three operations, because three is what WordPress does not already have.
 *
 * This library replaces wp-option-utils, wp-transient-utils and
 * wp-meta-utils -- 2,781 lines between them, most of it get_option() with a
 * default and update_option() with a check core already performs.
 */
final class StoredValuesTest extends TestCase {

	/**
	 * Empty the stubbed store.
	 */
	protected function setUp(): void {
		wp_test_reset();
	}

	/**
	 * A value is computed once and kept.
	 */
	public function test_a_value_is_computed_once(): void {
		$calls = 0;

		$compute = function () use ( &$calls ) {
			++$calls;

			return 'expensive';
		};

		$this->assertSame( 'expensive', Cache::remember( 'k', $compute ) );
		$this->assertSame( 'expensive', Cache::remember( 'k', $compute ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * A cached false is a hit, not a miss.
	 *
	 * The reason this class exists. get_transient() returns false both for
	 * "no such transient" and for "the transient holds false", so the obvious
	 * three-line version recomputes a legitimate "no" on every request, for
	 * ever -- which is exactly the call somebody wrapped in a cache to avoid.
	 *
	 * @param mixed $value What the callback returns.
	 */
	#[DataProvider( 'falsyProvider' )]
	public function test_a_falsy_value_is_still_cached( mixed $value ): void {
		$calls = 0;

		$compute = function () use ( &$calls, $value ) {
			++$calls;

			return $value;
		};

		$this->assertSame( $value, Cache::remember( 'k', $compute ) );
		$this->assertSame( $value, Cache::remember( 'k', $compute ) );
		$this->assertSame( 1, $calls, 'The value was recomputed, so it was never a hit.' );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function falsyProvider(): array {
		return array(
			'false'          => array( false ),
			'null'           => array( null ),
			'nought'         => array( 0 ),
			'an empty string'=> array( '' ),
			'an empty array' => array( array() ),
		);
	}

	/**
	 * Whether a key holds something is a different question from what it
	 * holds.
	 */
	public function test_presence_is_distinct_from_value(): void {
		$this->assertFalse( Cache::has( 'k' ) );

		Cache::remember( 'k', static fn() => false );

		$this->assertTrue( Cache::has( 'k' ), 'A cached false reads as absent.' );

		Cache::forget( 'k' );

		$this->assertFalse( Cache::has( 'k' ) );
	}

	/**
	 * Options are deleted by prefix, which is what uninstalling needs.
	 */
	public function test_options_are_deleted_by_prefix(): void {
		update_option( 'myplugin_one', 1 );
		update_option( 'myplugin_two', 2 );
		update_option( 'otherplugin_one', 3 );

		$this->assertSame( 2, Cleanup::options( 'myplugin_' ) );
		$this->assertFalse( get_option( 'myplugin_one' ) );
		$this->assertSame( 3, get_option( 'otherplugin_one' ), 'Another plugin lost an option.' );
	}

	/**
	 * An underscore in a prefix is a literal underscore.
	 *
	 * SQL's LIKE reads `_` as "any single character", so an unescaped
	 * `myplugin_` also matches `mypluginX`. Almost every plugin prefix ends
	 * in an underscore, so getting this wrong deletes other people's options
	 * during your uninstall.
	 */
	public function test_an_underscore_in_a_prefix_is_literal(): void {
		update_option( 'myplugin_one', 1 );
		update_option( 'mypluginXone', 2 );

		Cleanup::options( 'myplugin_' );

		$this->assertSame( 2, get_option( 'mypluginXone' ), 'The underscore matched as a wildcard.' );
	}

	/**
	 * An empty prefix deletes nothing.
	 *
	 * The guard that matters most in this file: without it, `LIKE '%'`
	 * matches every option on the site and the uninstall takes the whole
	 * installation with it.
	 */
	public function test_an_empty_prefix_deletes_nothing(): void {
		update_option( 'anything', 1 );

		$this->assertSame( 0, Cleanup::options( '' ) );
		$this->assertSame( 0, Cleanup::options( '   ' ) );
		$this->assertSame( 0, Cleanup::transients( '' ) );
		$this->assertSame( 0, Cleanup::meta( '' ) );

		$this->assertSame( 1, get_option( 'anything' ) );
	}

	/**
	 * Transients are deleted by prefix, both halves of each.
	 */
	public function test_transients_are_deleted_by_prefix(): void {
		set_transient( 'myplugin_a', 'x' );
		set_transient( 'myplugin_b', 'y' );
		set_transient( 'other_c', 'z' );

		$this->assertSame( 2, Cleanup::transients( 'myplugin_' ) );
		$this->assertFalse( get_transient( 'myplugin_a' ) );
		$this->assertSame( 'z', get_transient( 'other_c' ) );
	}

	/**
	 * A stored value reads back as the type it is meant to be.
	 *
	 * Options and meta come back as strings, because that is how they went
	 * in. The one that catches people is "false" -- a non-empty string, so
	 * PHP reads it as true.
	 *
	 * @param mixed $stored What is in the database.
	 * @param bool  $expect What it means.
	 */
	#[DataProvider( 'boolProvider' )]
	public function test_a_stored_boolean_reads_back_correctly( mixed $stored, bool $expect ): void {
		$this->assertSame( $expect, Cast::bool( $stored ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function boolProvider(): array {
		return array(
			'the string one'   => array( '1', true ),
			'yes'              => array( 'yes', true ),
			'on'               => array( 'on', true ),
			'the string zero'  => array( '0', false ),
			'empty'            => array( '', false ),
			'null'             => array( null, false ),

			// PHP reads all four of these as true.
			'the string false' => array( 'false', false ),
			'the string no'    => array( 'no', false ),
			'the string off'   => array( 'off', false ),
			'the string null'  => array( 'null', false ),
		);
	}

	/**
	 * A list reads back from an array or from what somebody typed.
	 */
	public function test_a_list_reads_back_from_either_shape(): void {
		$this->assertSame( array( 'a', 'b' ), Cast::array( array( 'a', 'b' ) ) );
		$this->assertSame( array( 'a', 'b' ), Cast::array( 'a, b' ) );
		$this->assertSame( array( 'a', 'b' ), Cast::array( ' a , b , ' ) );
		$this->assertSame( array(), Cast::array( '' ) );
		$this->assertSame( array(), Cast::array( null ) );
		$this->assertSame( array(), Cast::array( false ) );
	}

	/**
	 * A list of ids drops what is not one.
	 *
	 * Nought is not an id, and a duplicate makes a meta query do the same
	 * work twice.
	 */
	public function test_a_list_of_ids_is_cleaned(): void {
		$this->assertSame( array( 5, 7 ), Cast::ids( array( 5, '7', 0, '', 5, null ) ) );
		$this->assertSame( array( 5, 7 ), Cast::ids( '5,7,0,5' ) );
		$this->assertSame( array(), Cast::ids( '' ) );
	}

	/**
	 * A JSON list reads back the same as the array that was encoded.
	 *
	 * A custom column holds `wp_json_encode()` output, and a field posts the
	 * array; both sides of that round trip must read back alike.
	 */
	public function test_a_json_list_reads_back_as_the_array(): void {
		$this->assertSame( array( 'a', 'b' ), Cast::array( '["a","b"]' ) );
		$this->assertSame( array( 'a', 'b' ), Cast::array( '  ["a","b"] ' ) );
		$this->assertSame( array( 1, 2 ), Cast::array( '{"x":1,"y":2}' ) );
		$this->assertSame( array(), Cast::array( '[]' ) );
	}

	/**
	 * Broken JSON is an empty list, not a comma split of the brackets.
	 */
	public function test_broken_json_is_an_empty_list(): void {
		$this->assertSame( array(), Cast::array( '["a","b"' ) );
		$this->assertSame( array(), Cast::ids( '[5, 7' ) );
	}

	/**
	 * Ids read back from a JSON column the same as from an array.
	 */
	public function test_ids_read_back_from_json(): void {
		$this->assertSame( array( 5, 7 ), Cast::ids( '[5,"7",0,5,null,-7]' ) );
		$this->assertSame( array( 12 ), Cast::ids( '[12]' ) );
		$this->assertSame( array(), Cast::ids( 'null' ) );
	}

	/**
	 * A nested array is not an id.
	 *
	 * PHP casts a non-empty array to 1, which would read as post 1.
	 */
	public function test_a_nested_array_is_not_an_id(): void {
		$this->assertSame( array( 3 ), Cast::ids( array( array( 9 ), 3 ) ) );
		$this->assertSame( array(), Cast::ids( '[[9]]' ) );
	}

	/**
	 * A list of strings is trimmed, deduplicated and has no blanks.
	 */
	public function test_a_list_of_strings_is_cleaned(): void {
		$this->assertSame( array( 'Fast', 'Free' ), Cast::strings( '[" Fast ","","Free","Fast",null]' ) );
		$this->assertSame( array( 'a', 'b' ), Cast::strings( array( 'a', ' ', 'b', array( 'c' ) ) ) );
		$this->assertSame( array( '1', '2' ), Cast::strings( array( 1, 2 ) ) );
		$this->assertSame( array(), Cast::strings( '' ) );
		$this->assertSame( array(), Cast::strings( '{broken' ) );
	}

	/**
	 * A cleaner runs before the blank check, so what cleans away is dropped.
	 */
	public function test_a_cleaner_runs_before_the_blank_check(): void {
		$strip = static fn( string $one ): string => strip_tags( $one );

		$this->assertSame( array( 'Bold', 'x' ), Cast::strings( array( '<b>Bold</b>', '<i></i>', '<b> </b>', '<b> x</b>' ), $strip ) );
		$this->assertSame( array( 'A' ), Cast::strings( array( 'a', 'A' ), 'strtoupper' ) );
	}

	/**
	 * Numbers read back as numbers, and nonsense reads back as the fallback.
	 */
	public function test_numbers_read_back_with_a_fallback(): void {
		$this->assertSame( 5, Cast::int( '5' ) );
		$this->assertSame( 0, Cast::int( 'abc' ) );
		$this->assertSame( -1, Cast::int( 'abc', -1 ) );

		$this->assertSame( 1.5, Cast::float( '1.5' ) );
		$this->assertSame( 0.0, Cast::float( array() ) );
	}

	/**
	 * A meta type outside the known set deletes nothing.
	 *
	 * The table name is interpolated -- $wpdb->prepare() cannot parameterise
	 * one -- so the only safe interpolation is one where the value provably
	 * came from this library rather than from a caller.
	 */
	public function test_an_unknown_meta_type_deletes_nothing(): void {
		$GLOBALS['wp_test_meta']['_myplugin_a'] = 'x';

		$this->assertSame( 0, Cleanup::meta( '_myplugin_', 'wp_users; DROP TABLE' ) );
		$this->assertSame( 0, Cleanup::meta( '_myplugin_', 'nonsense' ) );
		$this->assertArrayHasKey( '_myplugin_a', $GLOBALS['wp_test_meta'] );

		// And the known one works.
		$this->assertSame( 1, Cleanup::meta( '_myplugin_', 'post' ) );
	}

	/**
	 * A transient something else wrote under the same key is a miss, not a
	 * value: only what remember() wrapped is read back.
	 */
	public function test_an_unwrapped_transient_is_recomputed(): void {
		set_transient( 'shared', 'written by somebody else' );

		$this->assertFalse( Cache::has( 'shared' ) );
		$this->assertSame( 'fresh', Cache::remember( 'shared', static fn(): string => 'fresh' ) );
		$this->assertTrue( Cache::has( 'shared' ) );
	}

	/**
	 * get() and set() read and write the same wrapper remember() does, so a
	 * kept false is false and nothing kept is the default.
	 */
	public function test_get_and_set_tell_a_kept_false_from_nothing(): void {
		$this->assertSame( 'none', Cache::get( 'k', 'none' ) );

		$this->assertTrue( Cache::set( 'k', false ) );
		$this->assertFalse( Cache::get( 'k', 'none' ) );
		$this->assertFalse( Cache::remember( 'k', static fn(): string => 'recomputed' ) );

		set_transient( 'raw', 'not wrapped' );
		$this->assertSame( 'none', Cache::get( 'raw', 'none' ), 'A value somebody else wrote is not ours.' );
	}
}
