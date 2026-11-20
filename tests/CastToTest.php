<?php
/**
 * Casting what somebody typed to the type it is declared as.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

namespace SugarCommerce\Support\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Support\Cast;
use WP_Error;

/**
 * Forgiving about shape, strict about meaning: a price keeps its value
 * whatever symbols came with it, and what cannot mean what it says is
 * handed back, or refused, rather than becoming nought or no.
 */
final class CastToTest extends TestCase {

	/**
	 * The site's timezone back to UTC.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['ou_timezone'] );
	}

	/**
	 * Nothing is nothing, whatever the type.
	 */
	public function test_nothing_is_left_alone(): void {
		$this->assertNull( Cast::to( null, 'number' ) );
		$this->assertSame( '', Cast::to( '', 'boolean' ) );
	}

	/**
	 * A number out of a spreadsheet, and a word that is not one.
	 */
	public function test_numbers_lose_their_symbols_not_their_meaning(): void {
		$this->assertSame( 1299.0, Cast::to( '£1,299.00', 'number' ) );
		$this->assertSame( -3.5, Cast::to( ' -3.5 ', 'number' ) );
		$this->assertSame( 'banana', Cast::to( 'banana', 'number' ) );
	}

	/**
	 * A whole number may be written with decimals; 12.9 is not twelve.
	 */
	public function test_integers_are_whole(): void {
		$this->assertSame( 12, Cast::to( '12.00', 'integer' ) );
		$this->assertSame( '12.9', Cast::to( '12.9', 'integer' ) );
		$this->assertSame( 'twelve', Cast::to( 'twelve', 'integer' ) );
	}

	/**
	 * Yes and no, in the words people use -- and a typo is an error, not a no.
	 */
	public function test_a_yes_or_no_is_one_or_an_error(): void {
		foreach ( [ 'Yes', 'TRUE', 'y', 'on', '1', 't' ] as $yes ) {
			$this->assertTrue( Cast::to( $yes, 'boolean' ), $yes );
		}

		foreach ( [ 'no', 'False', 'n', 'off', '0', 'f' ] as $no ) {
			$this->assertFalse( Cast::to( $no, 'boolean' ), $no );
		}

		$this->assertTrue( Cast::to( true, 'boolean' ) );

		$typo = Cast::to( 'Ys', 'boolean' );

		$this->assertInstanceOf( WP_Error::class, $typo );
		$this->assertSame( 'invalid_boolean', $typo->get_error_code() );
		$this->assertStringContainsString( 'not a yes or a no', $typo->get_error_message() );
	}

	/**
	 * A code is upper case and trimmed; anything else is a string.
	 */
	public function test_codes_and_strings(): void {
		$this->assertSame( 'GBP', Cast::to( ' gbp ', 'currency' ) );
		$this->assertSame( 'DE', Cast::to( 'de', 'country' ) );
		$this->assertSame( '42', Cast::to( 42, 'string' ) );
	}

	/**
	 * A declared date format settles 03/04, and is held to exactly.
	 */
	public function test_a_declared_date_format_is_kept_exactly(): void {
		$this->assertSame( '2026-03-04', Cast::to( '03/04/2026', 'date' ) );
		$this->assertSame( '2026-04-03', Cast::to( '03/04/2026', 'date', [ 'date_format' => 'd/m/Y' ] ) );
		$this->assertSame( '2026-13-45', Cast::to( '2026-13-45', 'date', [ 'date_format' => 'Y-m-d' ] ), 'Rolled forward into 2027.' );
		$this->assertSame( 'not a date', Cast::to( 'not a date', 'date' ) );
	}

	/**
	 * A date is the site's: read and written in its timezone.
	 */
	public function test_dates_are_in_the_sites_timezone(): void {
		$GLOBALS['ou_timezone'] = 'America/New_York';

		$this->assertSame( '2026-03-04 10:00:00', Cast::to( '2026-03-04 10:00', 'datetime' ) );
		$this->assertSame( '2026-03-03 18:00:00', Cast::to( '2026-03-04T01:00:00+02:00', 'datetime' ) );
		$this->assertSame( '2026-03-03', Cast::to( '2026-03-04T01:00:00+02:00', 'date' ) );
	}

	/**
	 * The types it knows, for a caller deciding whether to ask.
	 */
	public function test_the_scalar_types(): void {
		$this->assertContains( 'datetime', Cast::SCALARS );
		$this->assertNotContains( 'list', Cast::SCALARS );
	}
}
