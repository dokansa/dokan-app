<?php
/**
 * CLI harness: loads only the helpers + search normaliser / concept parser with minimal WP stubs
 * and prints the parsed concepts and chips for a set of queries.
 *
 * Usage: php wp-plugin/tests/search-test.php ["extra query" ...]
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DOKAN_APP_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );

function apply_filters( $tag, $value ) {
	return $value;
}
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function wp_unslash( $v ) {
	return $v;
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function get_option( $k, $d = false ) {
	return $d;
}
function get_current_user_id() {
	return 0;
}
function wp_parse_args( $a, $d ) {
	return array_merge( $d, (array) $a );
}

require dirname( __DIR__ ) . '/dokan-app-api/includes/class-dokan-app-helpers.php';
require dirname( __DIR__ ) . '/dokan-app-api/includes/class-dokan-app-search.php';

// Brands as they would come from the index (taxonomy names incl. Polylang translations).
$brands = array(
	10 => array( 'names' => array( 'Royal Canin', 'رويال كانين' ), 'slugs' => array( 'royal-canin' ), 'ids' => array( 10, 11 ), 'k' => 120 ),
	12 => array( 'names' => array( 'Whiskas' ), 'slugs' => array( 'whiskas' ), 'ids' => array( 12 ), 'k' => 40 ),
	13 => array( 'names' => array( 'JosiCat' ), 'slugs' => array( 'josicat' ), 'ids' => array( 13 ), 'k' => 30 ),
	14 => array( 'names' => array( 'فيلاين قو', 'Feline Go' ), 'slugs' => array( 'feline-go' ), 'ids' => array( 14, 15 ), 'k' => 25 ),
);
$be = Dokan_App_Search::brand_entries( $brands );

$queries = array(
	'ar' => array( 'اكل قطط رطب', 'أكل جاف لقطه صغيره', 'رمل', 'رويال كانين كيتن', 'شامبو كلاب', 'العاب للقطط تحت 50', 'اكل للقطط المعقمه', 'ارخص رمل قطط', 'whiskas', 'ويسكاس', 'قطتي عندها كرات شعر', 'طعام للجراء' ),
	'en' => array( 'wet cat food', 'litter', 'royal canin kitten', 'dog shampoo' ),
);
if ( $argc > 1 ) {
	$queries = array( 'ar' => array_slice( $argv, 1 ) );
}

$fmt = function ( $c ) {
	$parts = array();
	if ( $c['pets'] ) {
		$parts[] = 'pet=' . implode( ',', $c['pets'] );
	}
	if ( $c['types'] ) {
		$parts[] = 'type=' . implode( ',', $c['types'] );
	}
	if ( $c['stage'] ) {
		$parts[] = 'stage=' . $c['stage'];
	}
	if ( $c['health'] ) {
		$parts[] = 'health=' . implode( ',', $c['health'] );
	}
	if ( $c['brands'] ) {
		$parts[] = 'brand=' . implode( ',', $c['brands'] );
	}
	if ( null !== $c['min_price'] ) {
		$parts[] = 'min=' . $c['min_price'];
	}
	if ( null !== $c['max_price'] ) {
		$parts[] = 'max=' . $c['max_price'];
	}
	if ( $c['sort'] ) {
		$parts[] = 'sort=' . $c['sort'];
	}
	if ( $c['on_sale'] ) {
		$parts[] = 'on_sale';
	}
	if ( $c['topics'] ) {
		$parts[] = 'topics=' . implode( ',', $c['topics'] );
	}
	if ( $c['keywords'] ) {
		$parts[] = 'keywords=' . implode( ',', array_map( function ( $k ) { return $k['w'] . '→' . $k['s']; }, $c['keywords'] ) );
	}
	return implode( ' | ', $parts );
};

foreach ( $queries as $lang => $list ) {
	Dokan_App_Helpers::with_lang(
		$lang,
		function () use ( $list, $be, $fmt ) {
			foreach ( $list as $q ) {
				$c     = Dokan_App_Search::parse( $q, $be );
				$chips = array_map( function ( $u ) { return $u['label']; }, Dokan_App_Search::understood( $c, $be ) );
				printf( "%-28s %s\n%-28s chips: %s\n", $q, $fmt( $c ), '', implode( ' · ', $chips ) );
			}
		}
	);
}

// ------------------------------------------------------------------
// Engine run against a small fake index (store-like titles / categories).
// ------------------------------------------------------------------
if ( $argc <= 1 ) {
	$cat_names = array(
		1  => array( 'القطط', 0 ), 2 => array( 'طعام القطط', 1 ), 3 => array( 'طعام رطب', 2 ), 4 => array( 'طعام جاف', 2 ),
		5  => array( 'رمل القطط', 1 ), 6 => array( 'ألعاب القطط', 1 ), 7 => array( 'العناية والصحة', 1 ),
		10 => array( 'الكلاب', 0 ), 11 => array( 'العناية بالكلاب', 10 ), 12 => array( 'طعام الكلاب', 10 ),
	);
	$products  = array(
		// id => [title, leaf cat, brand, sales, instock, price, onsale]
		101 => array( 'فيلاين قو طعام رطب كامل للقطط البالغة بالتونة واليقطين في الصلصة 75 جرام – كرتون 24 حبة', 3, 14, 340, 1, 89, 0 ),
		102 => array( 'JosiCat Poultry طعام جاف للقطط البالغة 10 كجم', 4, 13, 210, 1, 145, 1 ),
		103 => array( 'رويال كانين كيتن طعام جاف للقطط الصغيرة 2 كجم', 4, 10, 500, 1, 120, 0 ),
		104 => array( 'رويال كانين ستيرلايزد طعام جاف للقطط المعقمة 2 كجم', 4, 10, 260, 1, 135, 0 ),
		105 => array( 'رمل قطط متكتل برائحة اللافندر 10 لتر', 5, 0, 800, 1, 35, 0 ),
		106 => array( 'رمل قطط سيليكا 5 لتر', 5, 0, 150, 1, 49, 0 ),
		107 => array( 'شامبو للكلاب بالألوفيرا', 11, 0, 60, 1, 30, 0 ),
		108 => array( 'لعبة صنارة للقطط مع ريش', 6, 0, 90, 1, 15, 0 ),
		109 => array( 'طعام جاف للجراء بالدجاج 3 كجم', 12, 0, 70, 1, 99, 0 ),
		110 => array( 'معجون كرات الشعر للقطط', 7, 0, 40, 1, 45, 0 ),
		111 => array( 'ويسكاس طعام رطب للقطط بالدجاج', 3, 12, 400, 0, 25, 0 ),
		112 => array( 'كرة مطاطية للقطط', 6, 0, 20, 1, 60, 0 ),
	);
	$anc  = function ( $cid ) use ( $cat_names ) {
		$out = array();
		while ( $cat_names[ $cid ][1] ) {
			$cid   = $cat_names[ $cid ][1];
			$out[] = $cid;
		}
		return $out;
	};
	$idx  = array( 'v' => 'test', 'built' => time(), 'lang' => 'ar', 'p' => array(), 'vocab' => array(), 'cats' => array(), 'brands' => $brands, 'kinds' => array( 'cat' => array( 1 ), 'dog' => array( 10 ) ), 'btax' => 'product_brand' );
	$addv = function ( $text ) use ( &$idx ) {
		$st = array();
		foreach ( Dokan_App_Search::tokens( $text ) as $tk ) {
			$s = (string) $tk[1];
			if ( '' === $s || isset( $st[ $s ] ) ) {
				continue;
			}
			$st[ $s ] = 1;
			if ( ! isset( $idx['vocab'][ $s ] ) ) {
				$idx['vocab'][ $s ] = array( $tk[0], 0, Dokan_App_Search::len( $s ) );
			}
			$idx['vocab'][ $s ][1]++;
		}
		return array_map( 'strval', array_keys( $st ) );
	};
	foreach ( $products as $id => $p ) {
		$cats            = array_merge( array( $p[1] ), $anc( $p[1] ) );
		$idx['p'][ $id ] = array( 't' => $addv( $p[0] ), 'c' => $cats, 'b' => $p[2] ? array( $p[2] ) : array(), 's' => $p[3], 'i' => $p[4], 'r' => (float) $p[5], 'd' => $id, 'o' => $p[6] );
	}
	foreach ( $cat_names as $cid => $cn ) {
		$a                    = $anc( $cid );
		$idx['cats'][ $cid ] = array( 'n' => $cn[0], 'sl' => '', 'p' => $cn[1], 'k' => 1, 's' => array_map( 'strval', array_keys( array_flip( array_map( function ( $t ) { return (string) $t[1]; }, Dokan_App_Search::tokens( $cn[0] ) ) ) ) ), 'top' => $a ? end( $a ) : $cid );
	}
	$rp = new ReflectionProperty( 'Dokan_App_Search', 'index' );
	$rp->setAccessible( true );
	$rp->setValue( null, array( 'ar' => $idx, 'en' => $idx ) );

	echo "\n=== engine (fake index of 12 products) ===\n";
	$engine_q = array( 'اكل قطط رطب', 'أكل جاف لقطه صغيره', 'رمل', 'رويال كانين كيتن', 'شامبو كلاب', 'العاب للقطط تحت 50', 'اكل للقطط المعقمه', 'ارخص رمل قطط', 'ويسكاس', 'قطتي عندها كرات شعر', 'طعام للجراء', 'فلاين قو', 'رمل بالفراولة للقطط', 'xyzzy' );
	foreach ( $engine_q as $q ) {
		$r = Dokan_App_Search::search_response( $q, null, false, false, 40 );
		printf( "%-24s ids=[%s] total=%d\n%-24s reply: %s\n%-24s sugg: %s | cats: %s\n", $q, implode( ',', $r['product_ids'] ), $r['total'], '', $r['reply'], '', implode( ' / ', $r['suggestions'] ), implode( ' / ', array_map( function ( $c ) { return $c['name']; }, $r['categories'] ) ) );
	}
	Dokan_App_Helpers::with_lang(
		'en',
		function () {
			foreach ( array( 'wet cat food', 'royal canin kitten', 'cheapest litter' ) as $q ) {
				$r = Dokan_App_Search::search_response( $q, null, false, false, 40 );
				printf( "%-24s ids=[%s] reply: %s\n", $q, implode( ',', $r['product_ids'] ), $r['reply'] );
			}
		}
	);
	// Soft pet context: a kitten owner searching "طعام جاف" (no pet named).
	$r = Dokan_App_Search::search_response( 'طعام جاف', array( 'kind' => 'cat', 'birth_date' => gmdate( 'Y-m-d', time() - 90 * 86400 ) ), false, false, 40 );
	printf( "%-24s ids=[%s] (kitten owner: kitten food first)\n", 'طعام جاف + pet', implode( ',', $r['product_ids'] ) );
}

// Sanity: normaliser + stemmer.
echo "\nnormalize('أَكْـلٌ لِلقِطَطِ ١٠٠ ريال'): " . Dokan_App_Search::normalize( 'أَكْـلٌ لِلقِطَطِ ١٠٠ ريال' ) . "\n";
foreach ( array( 'للقطط', 'المعقمة', 'الألعاب', 'فيتامينات', 'بالتونة', 'صغيرة', 'cats' ) as $w ) {
	echo $w . ' → ' . Dokan_App_Search::stem_folded( Dokan_App_Search::fold( Dokan_App_Search::clean_text( $w ) ) ) . "\n";
}
