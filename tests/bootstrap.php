<?php
/**
 * PHPUnit bootstrap.
 *
 * There is no WordPress to load. Arr touches no WordPress function; for
 * Posts, Terms and Users the core functions they call are stubbed over a
 * small in-memory database in stubs.php.
 *
 * @package SugarCommerce\Support
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs.php';
