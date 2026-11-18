<?php
/**
 * Array helpers.
 *
 * These reach six plugins through wp-conditions, so the edge cases matter
 * more than the line count suggests -- particularly the empty-array answers,
 * which decide whether a rule with nothing configured matches everything or
 * nothing.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

namespace SugarCommerce\Support\Tests;

use SugarCommerce\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class ArrTest
 */
final class ArrTest extends TestCase {

	/**
	 * A nested fixture.
	 *
	 * @return array
	 */
	private function nested(): array {
		return [
			'user' => [
				'name'    => 'Dave',
				'address' => [ 'city' => 'Dublin', 'country' => 'IE' ],
				'tags'    => [ 'a', 'b' ],
			],
			'top'  => 1,
		];
	}

	/**
	 * A dot path reads a nested value.
	 */
	public function test_a_dot_path_reads_a_nested_value(): void {
		$data = $this->nested();

		$this->assertSame( 'Dave', Arr::get( $data, 'user.name' ) );
		$this->assertSame( 'Dublin', Arr::get( $data, 'user.address.city' ) );
		$this->assertSame( 1, Arr::get( $data, 'top' ) );
	}

	/**
	 * A missing path returns the default.
	 */
	public function test_a_missing_path_returns_the_default(): void {
		$data = $this->nested();

		$this->assertNull( Arr::get( $data, 'user.missing' ) );
		$this->assertSame( 'fallback', Arr::get( $data, 'user.missing', 'fallback' ) );
		$this->assertSame( 'fallback', Arr::get( $data, 'nothing.at.all', 'fallback' ) );
	}

	/**
	 * Walking into a non-array stops rather than erroring.
	 */
	public function test_walking_past_a_leaf_returns_the_default(): void {
		$this->assertSame( 'fallback', Arr::get( $this->nested(), 'top.deeper', 'fallback' ) );
	}

	/**
	 * A literal key containing a dot wins over the path reading.
	 *
	 * Otherwise a key like "user.name" stored flat becomes unreachable.
	 */
	public function test_a_literal_dotted_key_wins(): void {
		$data = [ 'user.name' => 'literal', 'user' => [ 'name' => 'nested' ] ];

		$this->assertSame( 'literal', Arr::get( $data, 'user.name' ) );
	}

	/**
	 * A stored null is returned, not mistaken for missing.
	 */
	public function test_a_stored_null_is_not_a_missing_key(): void {
		$this->assertNull( Arr::get( [ 'a' => null ], 'a', 'fallback' ) );
	}

	/**
	 * has() answers for present and absent paths.
	 */
	public function test_has_answers_for_paths(): void {
		$data = $this->nested();

		$this->assertTrue( Arr::has( $data, 'user.address.country' ) );
		$this->assertFalse( Arr::has( $data, 'user.address.postcode' ) );
		$this->assertFalse( Arr::has( $data, 'nothing' ) );
	}

	/**
	 * set() writes down a path and leaves the original alone.
	 */
	public function test_set_writes_a_path_without_mutating(): void {
		$data = $this->nested();
		$out  = Arr::set( $data, 'user.address.postcode', 'D02' );

		$this->assertSame( 'D02', Arr::get( $out, 'user.address.postcode' ) );
		$this->assertFalse( Arr::has( $data, 'user.address.postcode' ), 'The input was mutated.' );
	}

	/**
	 * set() creates the intermediate levels.
	 */
	public function test_set_creates_missing_levels(): void {
		$out = Arr::set( [], 'a.b.c', 'deep' );

		$this->assertSame( 'deep', Arr::get( $out, 'a.b.c' ) );
	}

	/**
	 * has_all_matches wants every element present.
	 */
	public function test_has_all_matches(): void {
		$this->assertTrue( Arr::has_all_matches( [ 'a', 'b' ], [ 'a', 'b', 'c' ] ) );
		$this->assertFalse( Arr::has_all_matches( [ 'a', 'z' ], [ 'a', 'b', 'c' ] ) );
	}

	/**
	 * has_any_matches wants one.
	 */
	public function test_has_any_matches(): void {
		$this->assertTrue( Arr::has_any_matches( [ 'a', 'z' ], [ 'a', 'b' ] ) );
		$this->assertFalse( Arr::has_any_matches( [ 'y', 'z' ], [ 'a', 'b' ] ) );
	}

	/**
	 * An empty needle matches everything under "all", nothing under "any".
	 *
	 * Worth pinning rather than discovering: these decide whether a rule with
	 * nothing configured applies to every product or to none, and the two
	 * helpers answer that question oppositely.
	 */
	public function test_the_empty_needle_answers(): void {
		$this->assertTrue( Arr::has_all_matches( [], [ 'a' ] ), 'All of nothing is vacuously present.' );
		$this->assertFalse( Arr::has_any_matches( [], [ 'a' ] ), 'None of nothing is present.' );
	}

	/**
	 * An empty haystack matches nothing either way.
	 */
	public function test_the_empty_haystack_answers(): void {
		$this->assertFalse( Arr::has_all_matches( [ 'a' ], [] ) );
		$this->assertFalse( Arr::has_any_matches( [ 'a' ], [] ) );
	}

	/**
	 * pluck lifts a column out.
	 */
	public function test_pluck_lifts_a_column(): void {
		$rows = [ [ 'id' => 1, 'name' => 'a' ], [ 'id' => 2, 'name' => 'b' ] ];

		$this->assertSame( [ 1, 2 ], Arr::pluck( $rows, 'id' ) );
	}

	/**
	 * only and except are complements.
	 */
	public function test_only_and_except_are_complements(): void {
		$data = [ 'a' => 1, 'b' => 2, 'c' => 3 ];

		$this->assertSame( [ 'a' => 1, 'c' => 3 ], Arr::only( $data, [ 'a', 'c' ] ) );
		$this->assertSame( [ 'b' => 2 ], Arr::except( $data, [ 'a', 'c' ] ) );
	}

	/**
	 * first and last handle an empty array.
	 */
	public function test_first_and_last_survive_empty(): void {
		$this->assertSame( 1, Arr::first( [ 1, 2, 3 ] ) );
		$this->assertSame( 3, Arr::last( [ 1, 2, 3 ] ) );
		$this->assertNull( Arr::first( [] ) );
		$this->assertNull( Arr::last( [] ) );
	}

	/**
	 * flatten collapses nesting.
	 */
	public function test_flatten_collapses_nesting(): void {
		$this->assertSame( [ 1, 2, 3, 4 ], Arr::flatten( [ 1, [ 2, [ 3, 4 ] ] ] ) );
	}

	/**
	 * to_options and from_options round-trip.
	 */
	public function test_options_round_trip(): void {
		$map = [ 'a' => 'Apple', 'b' => 'Banana' ];

		$options = Arr::to_options( $map );

		$this->assertSame( 'a', $options[0]['value'] );
		$this->assertSame( 'Apple', $options[0]['label'] );
		$this->assertSame( $map, Arr::from_options( $options ) );
	}

	/**
	 * group_by buckets rows.
	 */
	public function test_group_by_buckets_rows(): void {
		$rows = [
			[ 'type' => 'x', 'id' => 1 ],
			[ 'type' => 'y', 'id' => 2 ],
			[ 'type' => 'x', 'id' => 3 ],
		];

		$grouped = Arr::group_by( $rows, 'type' );

		$this->assertCount( 2, $grouped['x'] );
		$this->assertCount( 1, $grouped['y'] );
	}

	/**
	 * contains finds a value.
	 */
	public function test_contains(): void {
		$this->assertTrue( Arr::contains( [ 'a', 'b' ], 'a' ) );
		$this->assertFalse( Arr::contains( [ 'a', 'b' ], 'z' ) );
		$this->assertFalse( Arr::contains( [], 'a' ) );
	}

	/**
	 * insert_after places an element after a named key.
	 */
	public function test_insert_after_places_an_element(): void {
		$out = Arr::insert_after( [ 'a' => 1, 'c' => 3 ], 'a', [ 'b' => 2 ] );

		$this->assertSame( [ 'a', 'b', 'c' ], array_keys( $out ) );
	}

	/**
	 * insert_after works on a numeric key.
	 *
	 * PHP stores a key written as '0' as the integer 0, so array_keys()
	 * answers with an int. A strict search for the string would miss it and
	 * the element would land at the end instead of after the key -- which is
	 * silently wrong rather than an error.
	 */
	public function test_insert_after_handles_a_numeric_key(): void {
		$out = Arr::insert_after( [ '0' => 'zero', '1' => 'one' ], '0', [ 'half' => 0.5 ] );

		$this->assertSame( [ 0, 'half', 1 ], array_keys( $out ), 'The element did not land after the numeric key.' );
	}

	/**
	 * An unknown key appends rather than losing the element.
	 */
	public function test_insert_after_an_unknown_key_appends(): void {
		$out = Arr::insert_after( [ 'a' => 1 ], 'nope', [ 'b' => 2 ] );

		$this->assertSame( [ 'a', 'b' ], array_keys( $out ) );
	}
	/**
	 * to_string casts what it is given.
	 *
	 * The elements were handed to trim() as they came, and under strict
	 * types an int is a TypeError there -- so a list of ids, the commonest
	 * thing to join, could not be joined.
	 */
	public function test_to_string_accepts_numbers(): void {
		$this->assertSame( '1,2,3', Arr::to_string( [ 1, 2, 3 ] ) );
		$this->assertSame( '"a", "b"', Arr::to_string( [ ' a ', 'b' ], ', ', '"' ) );
		$this->assertSame( '1.5,1,', Arr::to_string( [ 1.5, true, null ] ) );
		$this->assertSame( '[1,2]', Arr::to_string( [ [ 1, 2 ] ] ) );
	}

	/**
	 * sort_by_column tolerates a row without the column.
	 *
	 * array_column() skipped such rows, which left the sort column shorter
	 * than the array and array_multisort() throwing a ValueError -- for a
	 * result set where one row happened to lack a field.
	 */
	public function test_sort_by_column_tolerates_a_missing_key(): void {
		$rows = [ [ 'a' => 2 ], [ 'b' => 1 ], [ 'a' => 1 ] ];

		$this->assertSame( [ [ 'b' => 1 ], [ 'a' => 1 ], [ 'a' => 2 ] ], Arr::sort_by_column( $rows, 'a' ) );
		$this->assertSame( [ [ 'a' => 2 ], [ 'a' => 1 ], [ 'b' => 1 ] ], Arr::sort_by_column( $rows, 'a', true ) );
		$this->assertSame( [], Arr::sort_by_column( [], 'a' ) );
	}

	/**
	 * sort_by_column and pluck read objects as well as arrays.
	 */
	public function test_columns_are_read_from_objects(): void {
		$rows = [ (object) [ 'id' => 2 ], (object) [ 'id' => 1 ] ];

		$this->assertSame( [ 2, 1 ], Arr::pluck( $rows, 'id' ) );
		$this->assertSame( [ 1, 2 ], Arr::pluck( Arr::sort_by_column( $rows, 'id' ), 'id' ) );
	}

	/**
	 * pluck gives null for a missing key, not a warning.
	 */
	public function test_pluck_gives_null_for_a_missing_key(): void {
		$this->assertSame( [ 1, null ], Arr::pluck( [ [ 'a' => 1 ], [ 'b' => 2 ] ], 'a' ) );
	}

	/**
	 * Rows without the key group together, and an unkeyable value does not fatal.
	 *
	 * A missing key produced null, and null as an array offset is deprecated
	 * in PHP 8.5; an array value was a TypeError.
	 */
	public function test_group_by_buckets_rows_without_the_key_together(): void {
		$rows = [ [ 'a' => 1 ], [ 'b' => 2 ], [ 'a' => [ 'nested' ] ], (object) [ 'a' => 1 ] ];

		$grouped = Arr::group_by( $rows, 'a' );

		$this->assertCount( 2, $grouped[1] );
		$this->assertCount( 2, $grouped[''] );
	}

	/**
	 * flatten with unique gives a list.
	 *
	 * array_unique() keeps the keys of the survivors, so the result had gaps
	 * and json_encode() turned it into an object.
	 */
	public function test_flatten_unique_returns_a_list(): void {
		$this->assertSame( [ 1, 2, 3 ], Arr::flatten( [ [ 1, 2 ], [ 2, 3 ] ], true ) );
	}

	/**
	 * contains compares strictly, and copes with anything.
	 *
	 * Flipping the array to look the needle up as a key only works for
	 * values that can be keys. A float was silently false, a bool or null a
	 * deprecation, an array a TypeError.
	 */
	public function test_contains_compares_strictly(): void {
		$this->assertTrue( Arr::contains( [ 1.5 ], 1.5 ) );
		$this->assertTrue( Arr::contains( [ null ], null ) );
		$this->assertTrue( Arr::contains( [ [ 1 ] ], [ 1 ] ) );
		$this->assertFalse( Arr::contains( [ 'a' ], null ) );
		$this->assertFalse( Arr::contains( [ 1, 2 ], [] ) );
		$this->assertFalse( Arr::contains( [ '1' ], 1 ), 'A string and an int are not the same value.' );
	}

	/**
	 * sort_numeric keeps zero and negatives, and drops what is not a number.
	 *
	 * It ran everything through absint(), which turned -2 into 2 and threw 0
	 * away -- and was a WordPress function in a library with no other
	 * WordPress dependency, so it could not even be called here.
	 */
	public function test_sort_numeric_keeps_zero_and_negatives(): void {
		$this->assertSame( [ -2, 0, 1.5, 3 ], Arr::sort_numeric( [ 3, 'x', 0, -2, '1.5', null ] ) );
		$this->assertSame( [ 3, 1.5, 0, -2 ], Arr::sort_numeric( [ 3, 'x', 0, -2, '1.5' ], true ) );
	}

	/**
	 * A nested value with no label falls back to its key.
	 *
	 * Casting an array to string is the word "Array" and a warning, which
	 * is what every option in a select read when the label key was wrong.
	 */
	public function test_to_options_falls_back_to_the_key_for_a_nested_value(): void {
		$options = Arr::to_options( [ 'a' => [ 'name' => 'Apple' ] ], 'label' );

		$this->assertSame( 'a', $options[0]['label'] );

		$options = Arr::to_options( [ 'a' => [ 'name' => 'Apple' ] ], 'name' );

		$this->assertSame( 'Apple', $options[0]['label'] );
	}

	/**
	 * A literal dotted key holding null is an answer, not a miss.
	 *
	 * It was checked with isset(), so a stored null fell through to the path
	 * walk and answered from a different branch of the array.
	 */
	public function test_a_literal_dotted_key_holding_null_wins(): void {
		$data = [ 'user.name' => null, 'user' => [ 'name' => 'nested' ] ];

		$this->assertNull( Arr::get( $data, 'user.name', 'fallback' ) );
		$this->assertTrue( Arr::has( [ 'a.b' => null ], 'a.b' ) );
		$this->assertTrue( Arr::has( [ 'a' => [ 'b' => null ] ], 'a.b' ), 'A nested null is there too.' );
	}

	/**
	 * set() replaces a scalar in the way, as its docblock says.
	 */
	public function test_set_replaces_a_scalar_in_the_way(): void {
		$this->assertSame( [ 'a' => [ 'b' => 1 ] ], Arr::set( [ 'a' => 'x' ], 'a.b', 1 ) );
	}

	/**
	 * get_first takes the first path holding something other than null.
	 */
	public function test_get_first_skips_nulls(): void {
		$data = [ 'a' => null, 'b' => [ 'c' => 0 ] ];

		$this->assertSame( 0, Arr::get_first( $data, [ 'a', 'missing', 'b.c' ] ) );
		$this->assertSame( 'none', Arr::get_first( $data, [ 'a', 'missing' ], 'none' ) );
	}

	/**
	 * A label that is not text falls back to the key.
	 *
	 * (string) on an array was "Array" and a warning, and on a plain object
	 * a fatal error.
	 */
	public function test_to_options_never_writes_array_or_fatals(): void {
		$options = Arr::to_options(
			[
				'a' => [ 'label' => [ 'nested' ] ],
				'b' => new \stdClass(),
				'c' => null,
				'd' => (object) [ 'label' => 'Dee' ],
				'e' => 5,
			],
			'label'
		);

		$this->assertSame( [ 'a', 'b', 'c', 'Dee', '5' ], array_column( $options, 'label' ) );
		$this->assertSame( [ 'a', 'b', 'c', 'd', 'e' ], array_column( $options, 'value' ) );

		$stringable = new class() {
			public function __toString(): string {
				return 'Stringy';
			}
		};

		$this->assertSame( 'Stringy', Arr::to_options( [ 'x' => $stringable ] )[0]['label'] );
		$this->assertSame( 'k', Arr::to_options( [ 'k' => [ 'v' ] ] )[0]['label'], 'An array value with no label key read "Array".' );
	}

	/**
	 * pluck can key the result by another column, as wp_list_pluck() does.
	 */
	public function test_pluck_with_an_index(): void {
		$rows = [
			[ 'id' => 7, 'name' => 'a' ],
			(object) [ 'id' => 'x', 'name' => 'b' ],
			[ 'name' => 'c' ],
			[ 'id' => [ 1 ], 'name' => 'd' ],
		];

		$this->assertSame( [ 7 => 'a', 'x' => 'b', 8 => 'c', 9 => 'd' ], Arr::pluck( $rows, 'name', 'id' ) );
		$this->assertSame( [ 'k' => 1 ], Arr::pluck( [ 'k' => [ 'a' => 1 ] ], 'a' ), 'Without an index the keys are kept.' );
	}

	/**
	 * Repeats in the first array all count under "all".
	 */
	public function test_has_matches_with_repeats(): void {
		$this->assertTrue( Arr::has_matches( [ 'a', 'a' ], [ 'a' ] ) );
		$this->assertFalse( Arr::has_matches( [ 'a', 'a', 'b' ], [ 'a' ] ) );
		$this->assertTrue( Arr::has_matches( [ 'a', 'a', 'b' ], [ 'a' ], false ) );
		$this->assertFalse( Arr::has_matches( [ 'b' ], [ 'a' ], false ) );
	}

	/**
	 * A seeded shuffle repeats.
	 *
	 * Fake data seeds mt_srand() and expects the same answers each run.
	 */
	public function test_a_seeded_shuffle_repeats(): void {
		$items = range( 1, 20 );

		mt_srand( 42 );
		$first = Arr::shuffle( $items );
		mt_srand( 42 );
		$second = Arr::shuffle( $items );
		mt_srand();

		$this->assertSame( $first, $second );
		$this->assertNotSame( $items, $first );
		$this->assertSame( $items, Arr::sort_numeric( $first ) );
	}

	/**
	 * insert_before places an element before a key, or at the start.
	 */
	public function test_insert_before(): void {
		$this->assertSame( [ 'a', 'b', 'c' ], array_keys( Arr::insert_before( [ 'a' => 1, 'c' => 3 ], 'c', [ 'b' => 2 ] ) ) );
		$this->assertSame( [ 'b', 'a' ], array_keys( Arr::insert_before( [ 'a' => 1 ], 'nope', [ 'b' => 2 ] ) ) );
		// A repeated key is kept once: whichever copy comes first.
		$this->assertSame( [ 'a' => 9, 'b' => 2 ], Arr::insert_before( [ 'a' => 1, 'b' => 2 ], 'a', [ 'a' => 9 ] ) );
		$this->assertSame( [ 'a' => 1, 'b' => 2 ], Arr::insert_before( [ 'a' => 1, 'b' => 2 ], 'b', [ 'a' => 9 ] ) );
		$this->assertSame( [ 'a' => 1, 'c' => 9 ], Arr::insert_after( [ 'a' => 1, 'c' => 3 ], 'a', [ 'c' => 9 ] ) );
	}

	/**
	 * The plain sorts.
	 */
	public function test_the_plain_sorts(): void {
		$this->assertSame( [ 'a', 'b', 'c' ], Arr::sort_alphabetic( [ 'x' => 'b', 'y' => 'c', 'z' => 'a' ] ) );
		$this->assertSame( [ 'c', 'b', 'a' ], Arr::sort_alphabetic( [ 'b', 'c', 'a' ], true ) );
		$this->assertSame( [ 'a' => 1, 'b' => 2 ], Arr::sort_by_key( [ 'b' => 2, 'a' => 1 ] ) );
		$this->assertSame( [ 'b' => 2, 'a' => 1 ], Arr::sort_by_key( [ 'a' => 1, 'b' => 2 ], true ) );
	}
}
