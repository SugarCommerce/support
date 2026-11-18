<?php
/**
 * Search tests.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

namespace SugarCommerce\Support\Tests;

use SugarCommerce\Support\Posts;
use SugarCommerce\Support\Terms;
use SugarCommerce\Support\Users;
use PHPUnit\Framework\TestCase;

/**
 * The other half of what the four libraries this replaces were for: feeding an
 * autocomplete.
 *
 * The shape is the contract -- a list of value and label pairs, ready for a
 * select control -- and the labels are where the thought goes. A blank option
 * cannot be picked, and two customers called John Smith cannot be told apart.
 */
final class SearchTest extends TestCase {

	/**
	 * Fill the stubbed database.
	 */
	protected function setUp(): void {
		ou_reset();

		ou_add_post( [ 'ID' => 10, 'post_name' => 'blue-shirt', 'post_title' => 'Blue Shirt', 'post_type' => 'product' ] );
		ou_add_post( [ 'ID' => 11, 'post_name' => 'blue-hat', 'post_title' => 'Blue Hat', 'post_type' => 'product' ] );
		ou_add_post( [ 'ID' => 12, 'post_name' => 'red-shoes', 'post_title' => 'Red Shoes', 'post_type' => 'product' ] );

		ou_add_term( [ 'term_id' => 20, 'slug' => 'shoes', 'name' => 'Shoes', 'taxonomy' => 'product_cat' ] );
		ou_add_term( [ 'term_id' => 21, 'slug' => 'shirts', 'name' => 'Shirts', 'taxonomy' => 'product_cat' ] );

		ou_add_user( [ 'ID' => 30, 'user_login' => 'ada', 'user_email' => 'ada@example.test', 'user_nicename' => 'ada-l', 'display_name' => 'Ada Lovelace' ] );
		ou_add_user( [ 'ID' => 31, 'user_login' => 'grace', 'user_email' => 'grace@example.test', 'user_nicename' => 'grace-h', 'display_name' => 'Grace Hopper' ] );
	}

	/**
	 * An empty search returns nothing, not everything.
	 *
	 * The difference that matters: this feeds a dropdown, and a blank box
	 * should not render every product on the site.
	 */
	public function test_an_empty_search_returns_nothing(): void {
		foreach ( [ '', '   ' ] as $nothing ) {
			$this->assertSame( [], Posts::search( $nothing ) );
			$this->assertSame( [], Posts::search_options( $nothing ) );
			$this->assertSame( [], Terms::search( $nothing, 'product_cat' ) );
			$this->assertSame( [], Users::search( $nothing ) );
		}
	}

	/**
	 * Options come back as value and label, in that order.
	 *
	 * The shape a select control wants, and the reason these methods exist
	 * rather than the caller mapping over the objects itself.
	 */
	public function test_options_come_back_in_the_select_shape(): void {
		$options = Posts::search_options( 'blue', 'product' );

		$this->assertCount( 2, $options );
		$this->assertSame( [ 'value', 'label' ], array_keys( $options[0] ) );
		$this->assertIsInt( $options[0]['value'] );
		$this->assertSame( 'Blue Shirt', $options[0]['label'] );

		$this->assertSame( [ 'value', 'label' ], array_keys( Terms::search_options( 'sho', 'product_cat' )[0] ) );
		$this->assertSame( [ 'value', 'label' ], array_keys( Users::search_options( 'ada' )[0] ) );
	}

	/**
	 * A post with no title gets a label that can be picked.
	 *
	 * An empty label renders an empty option, which a person can see but not
	 * choose. The admin shows "(no title)" for the same reason.
	 */
	public function test_an_untitled_post_still_gets_a_label(): void {
		ou_add_post( [ 'ID' => 13, 'post_name' => 'blue-nothing', 'post_title' => '', 'post_type' => 'product' ] );

		$labels = array_column( Posts::search_options( 'blue', 'product' ), 'label', 'value' );

		$this->assertArrayHasKey( 13, $labels );
		$this->assertNotSame( '', $labels[13] );
		$this->assertStringContainsString( '13', $labels[13] );
	}

	/**
	 * A user option carries the email as well as the name.
	 *
	 * Two customers called "John Smith" are indistinguishable otherwise, and
	 * picking the wrong one attaches an order to a stranger.
	 */
	public function test_a_user_option_names_the_email(): void {
		$label = Users::search_options( 'ada' )[0]['label'];

		$this->assertStringContainsString( 'Ada Lovelace', $label );
		$this->assertStringContainsString( 'ada@example.test', $label );
	}

	/**
	 * Searching a user looks across every column somebody might type.
	 *
	 * Which of them a person is typing is not knowable.
	 */
	public function test_a_user_is_found_by_any_of_its_names(): void {
		foreach ( [ 'ada', 'Ada Lovelace', 'ada@example.test', 'ada-l' ] as $typed ) {
			$this->assertCount( 1, Users::search( $typed ), sprintf( '"%s" found nobody.', $typed ) );
		}
	}

	/**
	 * The limit is honoured, and cannot be zero.
	 *
	 * A limit of nought passed through to get_posts() means *unlimited*,
	 * which is the opposite of what the caller asked for and would render
	 * every product into a dropdown.
	 */
	public function test_the_limit_is_honoured_and_never_unlimited(): void {
		$this->assertCount( 1, Posts::search( 'blue', 'product', 1 ) );
		$this->assertCount( 1, Terms::search( 's', 'product_cat', 1 ) );
		$this->assertCount( 1, Users::search( 'e', 1 ) );

		$this->assertCount( 1, Posts::search( 'blue', 'product', 0 ) );
		$this->assertCount( 1, Users::search( 'e', -5 ) );
	}

	/**
	 * A search in an unregistered taxonomy is empty, not an error.
	 *
	 * get_terms() hands back a WP_Error there, and a WP_Error is not an array
	 * -- so a caller looping the result fatals rather than showing nothing.
	 */
	public function test_an_unknown_taxonomy_searches_to_nothing(): void {
		$this->assertSame( [], Terms::search( 'shoes', 'no_such_taxonomy' ) );
		$this->assertSame( [], Terms::search_options( 'shoes', 'no_such_taxonomy' ) );
		$this->assertSame( [], Terms::search( 'shoes', '' ) );
	}

	/**
	 * A post search is narrowed by type.
	 */
	public function test_a_post_search_is_narrowed_by_type(): void {
		ou_add_post( [ 'ID' => 14, 'post_name' => 'blue-post', 'post_title' => 'Blue Article', 'post_type' => 'post' ] );

		$this->assertCount( 2, Posts::search( 'blue', 'product' ) );
		$this->assertCount( 3, Posts::search( 'blue', 'any' ) );
	}
}
