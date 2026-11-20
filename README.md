# Support

The array moves every plugin writes again, finding a post, term or user from whatever somebody typed, and the
stored-value helpers: a cache that can keep `false`, cleanup by prefix, and casts back from the options table.

## What it does

Every plugin reads a setting three levels deep, groups rows by a column, turns a map into the options a select wants,
and slots a tab in after another one. Each of those is four lines that get written slightly differently every time.
`Arr` is those four lines, once, with the edge cases settled.

And every plugin is handed a post, a term or a user in whatever form the caller had: an id from a meta field, a slug
from a URL, a title from a CSV, an email from a checkout, a `WP_Post` from a hook. Core has a different function for
each and a different kind of nothing when it misses -- `false`, `null`, or a `WP_Error`, which is truthy. `Posts`,
`Terms` and `Users` take any of them and answer with the object or `null`.

And everything a plugin keeps in the options table comes back a string, its transients cannot tell a cached `false`
from nothing, and its uninstall has to find every key it ever wrote. `Cache`, `Cast` and `Cleanup` do those three.

## Features

* Read, write and test dotted paths -- `Arr::get( $settings, 'emails.receipt.subject' )`
* Keep or drop keys, first and last, a strict `contains()`
* Sort numbers, values, keys, or rows by a column -- rows that lack it included
* Group rows by a column, pluck one out (keyed by another, like `wp_list_pluck()`), flatten
* Insert after or before a key, for tab and column lists
* All-of and any-of comparisons, with the empty answers pinned
* A map to `{value, label}` options and back
* Find a post, term or user by object, id, slug, title, name, email or login, in one call
* The post type and status are honoured however the post was named
* Several at once, in the order named, each once
* Search for an autocomplete, with options ready for a select
* Lookups that resolved are remembered for the request
* Cache-or-compute that can store `false`
* Delete options, transients and meta by prefix on uninstall, the underscore a literal one
* Read stored values back as booleans, numbers, id lists and string lists
* Cast what somebody typed to its declared type without guessing: a price, a yes/no, a date in a given format

## Installation

```bash
composer require sugarcommerce/support
```

## Quick start

```php
use SugarCommerce\Support\Arr;
use SugarCommerce\Support\Posts;
use SugarCommerce\Support\Terms;
use SugarCommerce\Support\Users;

$subject = Arr::get( $settings, 'emails.receipt.subject', 'Your receipt' );

$tabs = Arr::insert_after( $tabs, 'general', array( 'tax' => __( 'Tax', 'myshop' ) ) );

// A CSV row: the product by title, the category by name, the customer by email.
$product  = Posts::find( $row['product'], 'product' );
$category = Terms::id( $row['category'], 'product_cat' );
$customer = Users::find( $row['email'] );
```

## Dotted paths

```php
$data = array( 'user' => array( 'address' => array( 'city' => 'Dublin' ) ) );

Arr::get( $data, 'user.address.city' );           // 'Dublin'
Arr::get( $data, 'user.address.postcode', '—' );  // '—'
Arr::has( $data, 'user.address' );                 // true
Arr::get_first( $data, array( 'billing.city', 'user.address.city' ) ); // 'Dublin'

$data = Arr::set( $data, 'user.address.postcode', 'D02' );
```

A key that literally contains the dots wins over the path, so a flat `'user.name'` stays reachable -- even when what it
holds is `null`. A stored `null` is there: `has()` says so and `get()` returns it rather than the fallback.
`get_first()` takes the first path whose value is not `null`.

`set()` returns a copy and makes the levels it needs. A level on the way that is not an array is replaced by one:
setting `a.b` on `array( 'a' => 'x' )` loses the `'x'`.

## Keys and values

```php
Arr::only( $args, array( 'id', 'name' ) );
Arr::except( $args, array( 'password' ) );
Arr::first( $items );               // null when empty
Arr::last( $items );
Arr::contains( array( '1' ), 1 );   // false: strict
Arr::flatten( array( 1, array( 2, array( 3 ) ) ) );        // [1, 2, 3]
Arr::flatten( array( array( 1, 2 ), array( 2 ) ), true );  // [1, 2], a list
```

## Sorting

```php
Arr::sort_numeric( array( 3, 'x', 0, -2, '1.5' ) );   // [-2, 0, 1.5, 3] -- not-numbers dropped
Arr::sort_alphabetic( $names, descending: true );     // a list
Arr::sort_by_key( $map );                              // keys kept
Arr::sort_by_column( $rows, 'price', true );           // arrays or objects
```

A row without the column sorts as `null`, first ascending -- it does not throw.

## Rows

```php
Arr::group_by( $orders, 'status' );        // array( 'paid' => [...], 'refunded' => [...] )
Arr::pluck( $orders, 'total' );            // the rows' own keys kept
Arr::pluck( $orders, 'total', 'id' );      // array( 1001 => 4999, 1002 => 1500 )
```

Rows are arrays or objects. A row without the column gives `null` in `pluck()` and is grouped under `''` by
`group_by()`. With an index, a row whose index is missing or not a key is appended, as `wp_list_pluck()` does.

## Inserting

```php
$tabs = Arr::insert_after( $tabs, 'general', array( 'tax' => 'Tax' ) );   // at the end if 'general' is not there
$tabs = Arr::insert_before( $tabs, 'advanced', array( 'tax' => 'Tax' ) ); // at the start if 'advanced' is not there
```

For string-keyed arrays; a numeric key written `'0'` is found. A key can be there only once, so when the addition
repeats one, the copy that comes first in the result is kept.

## Comparing

```php
Arr::has_all_matches( array( 'a', 'b' ), $allowed );  // every one of them
Arr::has_any_matches( $product_tags, $rule_tags );    // at least one
Arr::has_matches( $a, $b, $match_all );
```

The empty answers decide whether a rule with nothing configured matches everything or nothing, and they differ:
all of nothing is present, any of nothing is not. An empty haystack matches nothing either way.

## Options

```php
Arr::to_options( array( 'gb' => 'United Kingdom', 'ie' => 'Ireland' ) );
// array( array( 'value' => 'gb', 'label' => 'United Kingdom' ), … )

Arr::to_options( $plans, 'name' );   // rows: the label from a column
Arr::from_options( $options );       // back to array( 'gb' => 'United Kingdom', … )
```

The `{value, label}` shape is the one `sugarcommerce/conditions` and `sugarcommerce/fields` take for their option
lists. A label that is not text -- an array, a plain object, `null`, a row without the column -- falls back to the key,
so no option reads "Array" or is blank.

`Arr::to_string( $ids )` joins values for storage or a header (`'1,2,3'`, nested arrays as JSON). For a list a person
reads -- "a, b and c" -- use `SugarCommerce\Text\Text::list()` from `sugarcommerce/text`.

`Arr::shuffle()` uses PHP's own `shuffle()`, so seeding `mt_srand()` -- as `sugarcommerce/faker` does -- makes it
repeat. Not for anything that must be unguessable.

## Finding posts, terms and users

```php
Posts::find( 42 );                      // WP_Post or null
Posts::find( 'blue-shirt', 'product' ); // by slug
Posts::find( 'Blue Shirt', 'product' ); // by title
Posts::find( $id, 'product', 'publish' );
Posts::id( $anything );                 // int or null
Posts::exists( $anything, 'product' );
Posts::find_many( array( 12, 'blue-shirt', 'Blue Shirt' ), 'product' ); // [12] -- each once

Terms::find( 'Shoes', 'product_cat' );  // object, id, slug, then name
Users::find( 'ada@example.test' );      // object, id, email, login, then nicename
```

| | Tried in order |
|---|---|
| `Posts::find( $x, $type = 'any', $status = 'any' )` | a `WP_Post` or object with `ID`, a numeric id, a slug (through `sanitize_title()`, so a title finds the slug it made), an exact title |
| `Terms::find( $x, $taxonomy )` | a `WP_Term` of that taxonomy or object with `term_id`, a numeric id, a slug, a name |
| `Users::find( $x )` | a `WP_User` or object with `ID`, a numeric id, an email -- something shaped like one is looked up only as one -- a login, a nicename |

The type and status are checked whichever way a post was named: `Posts::find( $page_id, 'product' )` is `null`. Leave
them as `'any'` to take a post whatever it is. A term from another taxonomy is refused, even handed in as an object.
`Users::find( 0 )` is nobody, not the current user.

`find_many()` drops what does not resolve and returns each post, term or user once, where it was first named -- ids,
or the objects with `$with_objects`.

A slug, title, name, email or login that resolved is remembered for the rest of the request, and checked again when
it is used. One that missed is not remembered, so find-or-create works:

```php
$term_id = Terms::id( $name, 'product_cat' ) ?? wp_insert_term( $name, 'product_cat' )['term_id'];
```

`Posts::reset()`, `Terms::reset()` and `Users::reset()` forget, for tests and long-running imports that rename or
delete as they go.

## Searching

```php
Posts::search( 'blue', 'product', 10 );        // WP_Post[]
Posts::search_options( 'blue', 'product' );    // array( array( 'value' => 12, 'label' => 'Blue Shirt' ), … )
Terms::search_options( 'sho', 'product_cat' );
Users::search_options( 'ada' );                // label "Ada Lovelace (ada@example.test)"
```

An empty search returns nothing, not everything -- a blank box does not render every post on the site. A limit below
one is one, never core's "unlimited". An untitled post is labelled `#12`; a user's label carries the email, because
two customers called John Smith are otherwise the same option. Posts are searched among those published.

These are for a quick server-side lookup. A paginated, REST-backed picker is `sugarcommerce/fields`'
`Search\PostSource`, `TermSource` and `UserSource`.

## Cache, cleanup and casts

`get_transient()` returns `false` both when nothing is cached and when the
cached value *is* `false`, so the cache-or-compute pattern gets written by
hand, subtly wrong, in every plugin. `Cache::remember()` wraps the stored
value so the two are distinguishable.

```php
use SugarCommerce\Support\Cache;
use SugarCommerce\Support\Cast;
use SugarCommerce\Support\Cleanup;

// Computed once an hour, and it can legitimately return false.
$eligible = Cache::remember( 'myplugin_eligible_' . $user_id, fn() => check( $user_id ) );

Cache::has( $key );         // true even when what is stored is false
Cache::get( $key, 'none' ); // what is kept -- false included -- or the default
Cache::set( $key, false );  // kept as false, not as nothing
Cache::forget( $key );

// On uninstall, take everything with you.
Cleanup::options( 'myplugin_' );
Cleanup::transients( 'myplugin_' );    // both halves, so nothing is orphaned
Cleanup::meta( '_myplugin_', 'post' );
```

The prefix goes through `esc_like()`, so the underscore in `myplugin_` is a
literal one rather than SQL's single-character wildcard. An empty prefix
deletes nothing, and a meta type core has no table for deletes nothing.

Anything out of the options table is a string. `Cast` reads it back as what it
was meant to be:

```php
Cast::bool( 'false' );                 // false -- PHP says true
Cast::int( get_option( 'limit' ), 5 );
Cast::ids( '[5,"7",0,5]' );            // [5, 7] from a JSON column
Cast::ids( '5, 7' );                   // [5, 7] from typed text
Cast::strings( $row->features, 'sanitize_text_field' );
```

| Method | Keeps | Drops |
|---|---|---|
| `Cast::array( $value )` | every entry, re-indexed | nothing (`null`, `''` and `false` are an empty list) |
| `Cast::ids( $value )` | positive integers (`absint`) | zero, duplicates, nested arrays |
| `Cast::strings( $value, $clean = null )` | trimmed, cleaned scalars | blanks, duplicates, nested arrays |

A string that opens with `[` or `{` is read as JSON. If that JSON is broken
the list is empty rather than a comma split of the brackets.

What somebody typed -- a CSV cell, a form field -- is cast to the type it is
declared as with `Cast::to()`: forgiving about how it is written, strict about
what it means. A value it cannot read is handed back untouched for the caller
to refuse, never turned into `0` or `false`; a yes/no that is neither is a
`WP_Error`. Dates are read and written in the site's timezone, and a declared
`date_format` is held to exactly (`2026-13-45` is not rolled into 2027).
sugarcommerce/importers casts every cell with it.

```php
Cast::to( '£1,299.00', 'number' );                                // 1299.0
Cast::to( '12.00', 'integer' );                                   // 12; '12.9' comes back as it was
Cast::to( 'Ys', 'boolean' );                                      // WP_Error invalid_boolean
Cast::to( '03/04/2026', 'date', [ 'date_format' => 'd/m/Y' ] );   // '2026-04-03'
Cast::to( ' gbp ', 'currency' );                                  // 'GBP'
Cast::SCALARS;                                                    // the types it knows
```

## Security

* Finds options, transients and meta to clean up with `esc_like()` and `$wpdb->prepare()`, and interpolates a meta table only after its type matched a fixed list, so a prefix cannot widen the delete
* Deletes nothing for an empty prefix or a meta type core has no table for
* Reads a JSON list only after `json_validate()` says it is one, so a broken or absurdly nested string costs nothing
* Searches posts among those published, and returns nothing for an empty search rather than everything
* Checks no capability itself: a user search matches emails, so a route that offers one should ask `list_users` first

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later, for `Posts`, `Terms`, `Users`, `Cache` and `Cleanup`; `Arr` is plain PHP

## License

GPL-2.0-or-later
