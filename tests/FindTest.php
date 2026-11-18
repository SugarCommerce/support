<?php
/**
 * Resolver tests.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

namespace SugarCommerce\Support\Tests;

use SugarCommerce\Support\Posts;
use SugarCommerce\Support\Terms;
use SugarCommerce\Support\Users;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * One way in, and one kind of nothing.
 *
 * The whole library is that sentence. Core has a different function per field
 * -- get_post(), get_page_by_path(), get_term_by( 'slug', … ) -- so calling
 * one means deciding first what kind of thing you are holding, which is
 * exactly what a CSV column headed "category" will not tell you.
 *
 * And they fail differently. get_term() returns a WP_Error, which is truthy,
 * so `if ( $term )` is true for a term that was not found.
 */
final class FindTest extends TestCase {

	/**
	 * Empty the stubbed database.
	 */
	protected function setUp(): void {
		ou_reset();

		ou_add_post( [ 'ID' => 10, 'post_name' => 'hello-world', 'post_title' => 'Hello World', 'post_type' => 'post' ] );
		ou_add_post( [ 'ID' => 11, 'post_name' => 'a-product', 'post_title' => 'A Product', 'post_type' => 'product' ] );
		ou_add_post( [ 'ID' => 12, 'post_name' => 'draft-one', 'post_title' => 'Draft One', 'post_type' => 'post', 'post_status' => 'draft' ] );

		ou_add_term( [ 'term_id' => 20, 'slug' => 'shoes', 'name' => 'Shoes', 'taxonomy' => 'product_cat' ] );
		ou_add_term( [ 'term_id' => 21, 'slug' => 'hats', 'name' => 'Hats', 'taxonomy' => 'product_cat' ] );
		ou_add_term( [ 'term_id' => 22, 'slug' => 'shoes', 'name' => 'Shoes', 'taxonomy' => 'post_tag' ] );

		ou_add_user( [ 'ID' => 30, 'user_login' => 'ada', 'user_email' => 'ada@example.test', 'user_nicename' => 'ada-l' ] );
	}

	/**
	 * A post resolves from an id, a slug, a title or itself.
	 */
	public function test_a_post_resolves_from_anything(): void {
		$this->assertSame( 10, Posts::find( 10 )?->ID );
		$this->assertSame( 10, Posts::find( '10' )?->ID, 'A numeric string is an id.' );
		$this->assertSame( 10, Posts::find( 'hello-world' )?->ID );
		$this->assertSame( 10, Posts::find( 'Hello World' )?->ID );

		$post = get_post( 10 );
		$this->assertSame( $post, Posts::find( $post ), 'An object is handed straight back.' );

		// A plain object carrying an ID, which is what $wpdb gives you.
		$this->assertSame( 10, Posts::find( (object) [ 'ID' => 10 ] )?->ID );
	}

	/**
	 * A title resolves through its slug, in one query.
	 *
	 * "Hello World" is not the slug, but sanitize_title() turns it into one on
	 * the way in, so the slug lookup finds it and the title query never runs.
	 * Without that step the answer is the same and the cost is doubled --
	 * which is why the assertion is on the query count rather than the result.
	 */
	public function test_a_title_resolves_through_its_slug_in_one_query(): void {
		$GLOBALS['ou_queries'] = 0;

		$this->assertSame( 10, Posts::find( 'Hello World' )?->ID );
		$this->assertSame( 1, $GLOBALS['ou_queries'], 'The title fell through to a second query.' );
	}

	/**
	 * A title whose slug no longer matches still resolves.
	 *
	 * A post renamed after publication keeps its old slug, so the slug lookup
	 * misses and the title query is what finds it. Both passes earn their
	 * place.
	 */
	public function test_a_title_resolves_when_the_slug_has_diverged(): void {
		ou_add_post( [ 'ID' => 13, 'post_name' => 'renamed', 'post_title' => 'Original Title', 'post_type' => 'post' ] );

		$GLOBALS['ou_queries'] = 0;

		$this->assertSame( 13, Posts::find( 'Original Title' )?->ID );
		$this->assertSame( 2, $GLOBALS['ou_queries'], 'The title should be found by the second pass.' );
	}

	/**
	 * The post type narrows every lookup, the id one included.
	 *
	 * `find( $id, 'product' )` used to hand back whatever post had that id --
	 * a page, an order, a draft -- so a product picker that stored a bare id
	 * would quietly resolve to a page. 'any' keeps the old answer.
	 */
	public function test_the_post_type_narrows_every_lookup(): void {
		$this->assertSame( 11, Posts::find( 'a-product', 'product' )?->ID );
		$this->assertNull( Posts::find( 'a-product', 'post' ) );

		$this->assertNull( Posts::find( 10, 'product' ), 'A post resolved as a product by id.' );
		$this->assertNull( Posts::find( '10', 'product' ) );
		$this->assertNull( Posts::find( (object) array( 'ID' => 10 ), 'product' ) );
		$this->assertNull( Posts::find( get_post( 10 ), 'product' ), 'A WP_Post of another type was passed through.' );
		$this->assertSame( 11, Posts::find( 11, 'product' )?->ID );
		$this->assertSame( 11, Posts::find( 11, array( 'post', 'product' ) )?->ID );
		$this->assertSame( 11, Posts::find( 11 )?->ID, "'any' takes a post whatever its type." );
	}

	/**
	 * The status narrows the id lookup too.
	 */
	public function test_the_status_narrows_the_id_lookup(): void {
		$this->assertNull( Posts::find( 12, 'any', 'publish' ), 'A draft resolved as published by id.' );
		$this->assertNull( Posts::find( get_post( 12 ), 'any', 'publish' ) );
		$this->assertSame( 12, Posts::find( 12, 'any', array( 'draft', 'publish' ) )?->ID );
		$this->assertSame( 12, Posts::find( 12 )?->ID );
		$this->assertFalse( Posts::exists( 12, 'post', 'publish' ) );
		$this->assertSame( array( 10 ), Posts::find_many( array( 10, 12 ), 'post', false, 'publish' ) );
	}

	/**
	 * A slug or title that resolved costs nothing the second time.
	 */
	public function test_a_resolved_name_is_remembered(): void {
		$this->assertSame( 10, Posts::id( 'Hello World' ) );

		$GLOBALS['ou_queries'] = 0;

		$this->assertSame( 10, Posts::id( 'Hello World' ) );
		$this->assertSame( 0, $GLOBALS['ou_queries'], 'A remembered post was looked up again.' );

		// 'Shoes' misses as a slug and is found as a name: two lookups, once.
		$this->assertSame( 20, Terms::id( 'Shoes', 'product_cat' ) );
		$this->assertSame( 20, Terms::id( 'Shoes', 'product_cat' ) );
		$this->assertSame( 2, $GLOBALS['ou_queries'], 'A remembered term was looked up again.' );

		$GLOBALS['ou_queries'] = 0;

		$this->assertSame( 30, Users::id( 'ada' ) );
		$this->assertSame( 30, Users::id( 'ada' ) );
		$this->assertSame( 1, $GLOBALS['ou_queries'], 'A remembered user was looked up again.' );
	}

	/**
	 * A remembered answer is still checked, and a miss is not remembered.
	 *
	 * An importer looks a term up, creates it when it is missing and looks
	 * again -- so a remembered miss would make it create the term twice. And
	 * a post deleted since it was found must not come back.
	 */
	public function test_the_memo_never_answers_stale(): void {
		$this->assertNull( Terms::find( 'boots', 'product_cat' ) );
		ou_add_term( array( 'term_id' => 23, 'slug' => 'boots', 'name' => 'Boots', 'taxonomy' => 'product_cat' ) );
		$this->assertSame( 23, Terms::id( 'boots', 'product_cat' ), 'A miss was remembered.' );

		$this->assertSame( 10, Posts::id( 'hello-world' ) );
		unset( $GLOBALS['ou_posts'][10] );
		$this->assertNull( Posts::find( 'hello-world' ), 'A deleted post came back from the memo.' );

		$this->assertSame( 30, Users::id( 'ada@example.test' ) );
		unset( $GLOBALS['ou_users'][30] );
		$this->assertNull( Users::find( 'ada@example.test' ) );

		unset( $GLOBALS['ou_terms'][23] );
		$this->assertNull( Terms::find( 'boots', 'product_cat' ) );
	}

	/**
	 * A remembered post still has to be the type and status asked for.
	 */
	public function test_a_remembered_post_is_checked_again(): void {
		$this->assertSame( 10, Posts::id( 'hello-world', 'post', 'publish' ) );

		$GLOBALS['ou_posts'][10]->post_status = 'trash';

		$this->assertNull( Posts::find( 'hello-world', 'post', 'publish' ) );
	}

	/**
	 * reset() forgets.
	 */
	public function test_reset_forgets(): void {
		Posts::id( 'hello-world' );
		Terms::id( 'shoes', 'product_cat' );
		Users::id( 'ada' );

		Posts::reset();
		Terms::reset();
		Users::reset();

		$GLOBALS['ou_queries'] = 0;

		Posts::id( 'hello-world' );
		Terms::id( 'shoes', 'product_cat' );
		Users::id( 'ada' );

		$this->assertSame( 3, $GLOBALS['ou_queries'] );
	}

	/**
	 * Something shaped like an email is looked up as an email, and only so.
	 *
	 * A login can be an email address. Asked for "bob@example.test", the
	 * account whose email that is wins over one that took it as a login.
	 */
	public function test_an_email_is_looked_up_before_a_login(): void {
		ou_add_user( array( 'ID' => 31, 'user_login' => 'bob@example.test', 'user_email' => 'other@example.test', 'user_nicename' => 'squatter' ) );
		ou_add_user( array( 'ID' => 32, 'user_login' => 'bob', 'user_email' => 'bob@example.test', 'user_nicename' => 'bob' ) );

		$this->assertSame( 32, Users::id( 'bob@example.test' ) );
	}

	/**
	 * A draft resolves, because 'any' means any.
	 */
	public function test_status_is_not_filtered_by_default(): void {
		$this->assertSame( 12, Posts::find( 'draft-one' )?->ID );
		$this->assertNull( Posts::find( 'draft-one', 'any', 'publish' ) );
	}

	/**
	 * A term resolves from an id, a slug, a name or itself.
	 */
	public function test_a_term_resolves_from_anything(): void {
		$this->assertSame( 20, Terms::find( 20, 'product_cat' )?->term_id );
		$this->assertSame( 20, Terms::find( 'shoes', 'product_cat' )?->term_id );
		$this->assertSame( 20, Terms::find( 'Shoes', 'product_cat' )?->term_id );

		$term = get_term( 20, 'product_cat' );
		$this->assertSame( $term, Terms::find( $term, 'product_cat' ) );
	}

	/**
	 * The taxonomy is honoured, including for an object.
	 *
	 * Two taxonomies can both have a "shoes" term, and handing back the wrong
	 * one attaches a product to a tag.
	 */
	public function test_the_taxonomy_is_honoured(): void {
		$this->assertSame( 20, Terms::find( 'shoes', 'product_cat' )?->term_id );
		$this->assertSame( 22, Terms::find( 'shoes', 'post_tag' )?->term_id );

		$this->assertNull( Terms::find( 20, 'post_tag' ), 'An id from another taxonomy resolved.' );

		// And an object from the wrong taxonomy is refused rather than passed
		// through, which is the case a plain instanceof check would miss.
		$this->assertNull( Terms::find( get_term( 20, 'product_cat' ), 'post_tag' ) );
	}

	/**
	 * A term that does not exist is null, not a WP_Error.
	 *
	 * This is the one that bites: get_term() returns a WP_Error for a missing
	 * term, and a WP_Error is an object, so `if ( $term )` is true and the
	 * next line reads ->term_id off an error.
	 */
	public function test_a_missing_term_is_null_not_an_error(): void {
		$this->assertNull( Terms::find( 999, 'product_cat' ) );
		$this->assertNull( Terms::find( 'nothing', 'product_cat' ) );
		$this->assertNull( Terms::find( 20, 'no_such_taxonomy' ) );
		$this->assertNull( Terms::find( 'shoes', '' ) );
	}

	/**
	 * A user resolves from an id, an email, a login, a nicename or itself.
	 *
	 * The email case is the one worth having: an order is keyed by email far
	 * more often than by id.
	 */
	public function test_a_user_resolves_from_anything(): void {
		$this->assertSame( 30, Users::find( 30 )?->ID );
		$this->assertSame( 30, Users::find( 'ada@example.test' )?->ID );
		$this->assertSame( 30, Users::find( 'ada' )?->ID );
		$this->assertSame( 30, Users::find( 'ada-l' )?->ID );

		$user = get_userdata( 30 );
		$this->assertSame( $user, Users::find( $user ) );
	}

	/**
	 * A zero user id is nobody, not the current user.
	 *
	 * A caller who means "whoever is logged in" should say so. Treating a
	 * falsy id as the current user turns a missing customer on an order into
	 * whoever happens to be looking at it.
	 */
	public function test_a_zero_user_id_is_nobody(): void {
		$this->assertNull( Users::find( 0 ) );
		$this->assertNull( Users::find( '0' ) );
	}

	/**
	 * Nothing in gives nothing out, for all three.
	 *
	 * @param mixed $identifier What arrived.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'nothingProvider' )]
	public function test_nothing_in_gives_nothing_out( mixed $identifier ): void {
		$this->assertNull( Posts::find( $identifier ) );
		$this->assertNull( Terms::find( $identifier, 'product_cat' ) );
		$this->assertNull( Users::find( $identifier ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function nothingProvider(): array {
		return [
			'null'        => [ null ],
			'empty'       => [ '' ],
			'whitespace'  => [ '   ' ],
			'false'       => [ false ],
			'an array'    => [ [ 1, 2 ] ],
			'unknown id'  => [ 99999 ],
			'unknown name'=> [ 'nothing-here' ],
		];
	}

	/**
	 * An array is refused rather than fataling.
	 *
	 * property_exists() and method_exists() both throw a TypeError when handed
	 * an array in PHP 8, so the object check has to come first.
	 */
	public function test_an_array_does_not_fatal(): void {
		$this->assertNull( Posts::find( [ 'ID' => 10 ] ) );
		$this->assertNull( Terms::find( [ 'term_id' => 20 ], 'product_cat' ) );
		$this->assertNull( Users::find( [ 'ID' => 30 ] ) );
	}

	/**
	 * Several at once, deduplicated by id.
	 *
	 * The same post named twice -- once by id, once by slug -- is one post.
	 */
	public function test_several_resolve_and_deduplicate(): void {
		$this->assertSame( [ 10, 11 ], Posts::find_many( [ 10, 'a-product', 'hello-world', 'nothing' ] ) );
		$this->assertSame( [ 20, 21 ], Terms::find_many( [ 'shoes', 21, 'Shoes', 'nothing' ], 'product_cat' ) );
		$this->assertSame( [ 30 ], Users::find_many( [ 30, 'ada@example.test', 'nobody' ] ) );
	}

	/**
	 * And can hand back the objects instead.
	 */
	public function test_several_can_come_back_as_objects(): void {
		$posts = Posts::find_many( [ 10, 11 ], 'any', true );

		$this->assertContainsOnlyInstancesOf( WP_Post::class, $posts );
		$this->assertCount( 2, $posts );
		$this->assertSame( [ 0, 1 ], array_keys( $posts ), 'The list is not reindexed.' );

		$this->assertContainsOnlyInstancesOf( WP_Term::class, Terms::find_many( [ 20 ], 'product_cat', true ) );
		$this->assertContainsOnlyInstancesOf( WP_User::class, Users::find_many( [ 30 ], true ) );
	}

	/**
	 * The id and existence shorthands agree with find().
	 */
	public function test_the_shorthands_agree(): void {
		$this->assertSame( 10, Posts::id( 'hello-world' ) );
		$this->assertTrue( Posts::exists( 'hello-world' ) );
		$this->assertNull( Posts::id( 'nothing' ) );
		$this->assertFalse( Posts::exists( 'nothing' ) );

		$this->assertSame( 20, Terms::id( 'shoes', 'product_cat' ) );
		$this->assertTrue( Terms::exists( 'Shoes', 'product_cat' ) );
		$this->assertFalse( Terms::exists( 'shoes', 'no_such_taxonomy' ) );

		$this->assertSame( 30, Users::id( 'ada@example.test' ) );
		$this->assertTrue( Users::exists( 'ada' ) );
		$this->assertFalse( Users::exists( 'nobody@example.test' ) );
	}

	/**
	 * An id costs no query.
	 *
	 * The commonest case by far, and the one that would be quietly expensive
	 * if it went through the same path as a title.
	 */
	public function test_an_id_costs_no_query(): void {
		$GLOBALS['ou_queries'] = 0;

		Posts::find( 10 );

		$this->assertSame( 0, $GLOBALS['ou_queries'] );

		Posts::find( 'hello-world' );

		$this->assertSame( 1, $GLOBALS['ou_queries'], 'A slug should take one query, not two.' );
	}
}
