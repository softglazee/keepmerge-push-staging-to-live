<?php
/**
 * Sync\Normalizer: typed JSON and URL tokens.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Hasher;
use SoftGlaze\SafePush\Sync\Normalizer;

/**
 * @group sync
 * @group normalizer
 */
class NormalizerTest extends WP_UnitTestCase {

	const STAGING = array(
		array(
			'name' => 'home',
			'url'  => 'https://staging.example.com',
		),
	);

	const LIVE = array(
		array(
			'name' => 'home',
			'url'  => 'https://live.example.org',
		),
	);

	private function source( array $origins = self::STAGING ) {
		return new Normalizer( $origins, true );
	}

	private function destination( array $origins = self::LIVE ) {
		return new Normalizer( $origins, false );
	}

	/**
	 * Encode, then JSON as it travels, then decode.
	 */
	private function round_trip( $value, ?Normalizer $n = null ) {
		$n     = $n ? $n : $this->source();
		$typed = $n->encode( $value );
		$this->assertSame( array(), $n->problems(), 'no problems for ' . wp_json_encode( $typed ) );
		$wire = json_decode( wp_json_encode( $typed ), true );
		return Normalizer::decode( $wire );
	}

	// --- Types -------------------------------------------------------------------------------

	public function test_null_booleans_and_integers_keep_their_types() {
		foreach ( array( null, true, false, 0, 42, -7, PHP_INT_MAX ) as $value ) {
			$this->assertSame( $value, $this->source()->encode( $value ) );
			$this->assertSame( $value, $this->round_trip( $value ) );
		}
	}

	public function test_floats_travel_as_text_and_come_back_identical() {
		foreach ( array( 1.5, 0.1, 1 / 3, 1e25, 2.0, -0.25, 123456789.123, 5e-324, 1.7976931348623157e308 ) as $value ) {
			$typed = $this->source()->encode( $value );
			$this->assertSame( 'float', $typed['__sgsp'] );
			$this->assertIsString( $typed['v'] );
			$back = $this->round_trip( $value );
			$this->assertIsFloat( $back );
			$this->assertSame( $value, $back, 'float ' . $typed['v'] );
		}
		$this->assertSame( '1.5', Normalizer::float_text( 1.5 ) );
		$this->assertSame( '0.1', Normalizer::float_text( 0.1 ) );
		$this->assertSame( '2', Normalizer::float_text( 2.0 ) );
	}

	public function test_inf_and_nan() {
		$this->assertSame( INF, $this->round_trip( INF ) );
		$this->assertSame( -INF, $this->round_trip( -INF ) );
		$this->assertNan( $this->round_trip( NAN ) );
	}

	public function test_strings_unicode_and_binary() {
		$this->assertSame( 'Grüße ✓ 漢字', $this->round_trip( 'Grüße ✓ 漢字' ) );

		$binary = "\xff\xfe\x00raw\x80";
		$typed  = $this->source()->encode( $binary );
		$this->assertSame(
			array(
				'__sgsp' => 'bin',
				'hex'    => bin2hex( $binary ),
			),
			$typed
		);
		$this->assertSame( $binary, $this->round_trip( $binary ) );
	}

	public function test_lists_and_associative_arrays() {
		$value = array(
			'list'   => array( 1, 'two', 3.5, null, false ),
			'map'    => array(
				'b' => 1,
				'a' => array( 'x' => 'y' ),
			),
			'sparse' => array(
				3 => 'c',
				7 => 'g',
			),
			'empty'  => array(),
		);
		$this->assertSame( $value, $this->round_trip( $value ) );
	}

	public function test_stdclass_becomes_an_object_marker_and_back() {
		$inner        = new stdClass();
		$inner->deep  = array( 1, 2 );
		$object       = new stdClass();
		$object->name = 'x';
		$object->num  = 2.5;
		$object->obj  = $inner;

		$typed = $this->source()->encode( $object );
		$this->assertSame( 'object', $typed['__sgsp'] );
		$this->assertSame( 'object', $typed['props']['obj']['__sgsp'] );

		$back = $this->round_trip( array( 'o' => $object ) );
		$this->assertInstanceOf( stdClass::class, $back['o'] );
		$this->assertEquals( $object, $back['o'] );
		$this->assertInstanceOf( stdClass::class, $back['o']->obj );
	}

	public function test_nested_serialized_string_is_decoded_on_the_source_only() {
		$inner  = array(
			'a' => 1,
			'b' => array( 1.5, 'text', true ),
		);
		$stored = serialize( $inner ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$typed = $this->source()->encode( array( 'meta' => $stored ) );
		$this->assertSame( 'ser', $typed['meta']['__sgsp'] );
		$this->assertSame( 1, $typed['meta']['v']['a'] );

		// Live rebuilds the same serialized text from plain data, without unserialize().
		$back = $this->round_trip( array( 'meta' => $stored ) );
		$this->assertSame( $stored, $back['meta'] );

		// Destination mode never decodes: the text stays text (canonical form).
		$dest = $this->destination()->encode( $stored );
		$this->assertSame( 'ser_raw', $dest['__sgsp'] );
		$this->assertSame( $stored, $dest['s'] );

		// b:0; is the serialized false: decoded to false, rebuilt as the same text.
		$typed = $this->source()->encode( 'b:0;' );
		$this->assertSame( 'ser', $typed['__sgsp'] );
		$this->assertFalse( $typed['v'] );
		$this->assertSame( 'b:0;', $this->round_trip( 'b:0;' ) );
	}

	public function test_nested_serialized_stdclass_is_kept_other_classes_are_unsupported() {
		$n     = $this->source();
		$typed = $n->encode( 'O:8:"stdClass":1:{s:1:"a";i:1;}' );
		$this->assertSame( array(), $n->problems() );
		$this->assertSame( 'ser', $typed['__sgsp'] );
		$this->assertSame( 'object', $typed['v']['__sgsp'] );
		$this->assertSame( 1, $typed['v']['props']['a'] );

		$n->reset();
		$n->encode( array( 'x' => 'O:8:"WP_Theme":0:{}' ) );
		$this->assertSame( array( 'unsupported_object:WP_Theme' ), $n->problems() );
	}

	public function test_a_php_object_makes_the_item_unsupported() {
		$n = $this->source();
		$n->encode( array( 'x' => new WP_Error( 'a', 'b' ) ) );
		$this->assertSame( array( 'unsupported_object:WP_Error' ), $n->problems() );

		$n->reset();
		$n->encode( new ArrayObject( array( 1 ) ) );
		$this->assertSame( array( 'unsupported_object:ArrayObject' ), $n->problems() );
	}

	public function test_reserved_key_and_token_literal_are_flagged_not_altered() {
		$n = $this->source();
		$n->encode( array( '__sgsp' => 'object' ) );
		$this->assertSame( array( 'reserved_key' ), $n->problems() );

		$n->reset();
		$this->assertSame( 'see {{sgsp:home}} here', $n->encode( 'see {{sgsp:home}} here' ) );
		$this->assertSame( array( 'token_literal' ), $n->problems() );
	}

	public function test_decode_refuses_crafted_markers_and_never_unserializes() {
		$crafted = array(
			array(
				'__sgsp' => 'object',
				'class'  => 'WP_Theme',
			),
			array(
				'__sgsp' => 'object',
				'props'  => array(),
				'class'  => 'WP_Theme',
			),
			array(
				'__sgsp' => 'ser_raw',
				's'      => 'O:8:"WP_Theme":0:{}',
			),
			array(
				'__sgsp' => 'float',
				'v'      => '1;phpinfo()',
			),
			array(
				'__sgsp' => 'bin',
				'hex'    => 'zz',
			),
			array( '__sgsp' => 'unknown' ),
			array( 'x' => 1.5 ),
		);
		foreach ( $crafted as $value ) {
			$this->assertWPError( Normalizer::decode( $value ), wp_json_encode( $value ) );
		}

		// A serialized-looking string received as a string stays a string.
		$this->assertSame( 'O:8:"stdClass":0:{}', Normalizer::decode( 'O:8:"stdClass":0:{}' ) );
	}

	// --- URL tokens --------------------------------------------------------------------------

	public function test_url_tokens_in_all_four_forms_and_the_http_variant() {
		$n     = $this->source();
		$cases = array(
			'https://staging.example.com/about/'                => '{{sgsp:home}}/about/',
			'http://staging.example.com/about/'                 => '{{sgsp:home}}/about/',
			'{"url":"https:\/\/staging.example.com\/a.jpg"}'    => '{"url":"{{sgsp:home:j}}\/a.jpg"}',
			'{"url":"http:\/\/staging.example.com\/a.jpg"}'     => '{"url":"{{sgsp:home:j}}\/a.jpg"}',
			'?redirect=https%3A%2F%2Fstaging.example.com%2Fx'   => '?redirect={{sgsp:home:u}}%2Fx',
			'?redirect=https%3a%2f%2fstaging.example.com%2fx'   => '?redirect={{sgsp:home:u}}%2fx',
			'?redirect=http%3A%2F%2Fstaging.example.com'        => '?redirect={{sgsp:home:u}}',
			'<img src="//staging.example.com/a.jpg">'           => '<img src="{{sgsp:home:p}}/a.jpg">',
			'Visit https://staging.example.com.'                => 'Visit {{sgsp:home}}.',
			'https://staging.example.com.evil.net/'             => 'https://staging.example.com.evil.net/',
			'https://staging.example.com:8443/'                 => 'https://staging.example.com:8443/',
			'https://staging.example.community/'                => 'https://staging.example.community/',
			'mail user@staging.example.com'                     => 'mail user@staging.example.com',
		);
		foreach ( $cases as $in => $expected ) {
			$this->assertSame( $expected, $n->tokenize( $in ), $in );
		}
		$this->assertSame( array(), $n->problems() );
	}

	public function test_longest_origin_first() {
		$n = $this->source(
			array(
				array(
					'name' => 'home',
					'url'  => 'https://staging.example.com',
				),
				array(
					'name' => 'site',
					'url'  => 'https://staging.example.com/wp',
				),
			)
		);
		$this->assertSame( '{{sgsp:site}}/wp-admin/', $n->tokenize( 'https://staging.example.com/wp/wp-admin/' ) );
		$this->assertSame( '{{sgsp:home}}/wp-content/a.jpg', $n->tokenize( 'https://staging.example.com/wp-content/a.jpg' ) );
		$this->assertSame( '{{sgsp:site:j}}\/x', $n->tokenize( 'https:\/\/staging.example.com\/wp\/x' ) );
	}

	public function test_detokenize_writes_the_receiving_sites_own_forms() {
		$text = '{{sgsp:home}}/a {{sgsp:home:j}}\/b {{sgsp:home:u}}%2Fc {{sgsp:home:p}}/d {{sgsp:site}}/e';
		$this->assertSame(
			'https://live.example.org/a https:\/\/live.example.org\/b https%3A%2F%2Flive.example.org%2Fc //live.example.org/d https://live.example.org/e',
			Normalizer::detokenize( $text, self::LIVE )
		);

		// Round trip staging -> tokens -> live.
		$typed = $this->source()->encode( array( 'u' => 'https://staging.example.com/x' ) );
		$this->assertSame( array( 'u' => 'https://live.example.org/x' ), Normalizer::decode( $typed, self::LIVE ) );
	}

	public function test_serialized_text_with_urls_hashes_alike_on_both_sites() {
		$staging = serialize( array( 'link' => 'https://staging.example.com/page/', 'n' => 2 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$live    = serialize( array( 'link' => 'https://live.example.org/page/', 'n' => 2 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$source_form = $this->source()->encode( array( 'meta' => $staging ) );
		$live_form   = $this->destination()->encode( array( 'meta' => $live ) );

		$this->assertSame( 'ser', $source_form['meta']['__sgsp'] );
		$this->assertSame( 'ser_raw', $live_form['meta']['__sgsp'] );
		$this->assertSame( 'a:2:{s:4:"link";s:19:"{{sgsp:home}}/page/";s:1:"n";i:2;}', $live_form['meta']['s'] );
		$this->assertSame( Hasher::hash( $live_form ), Hasher::hash( $source_form ) );

		// A different value gives a different hash.
		$other = $this->destination()->encode( array( 'meta' => serialize( array( 'link' => 'https://live.example.org/other/', 'n' => 2 ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertNotSame( Hasher::hash( $other ), Hasher::hash( $source_form ) );
	}

	public function test_canonical_reader_refuses_malformed_text() {
		$this->assertNull( Normalizer::canonical_serialized( 's:5:"abc";', 'strval' ) );
		$this->assertNull( Normalizer::canonical_serialized( 'a:2:{i:0;i:1;}', 'strval' ) );
		$this->assertNull( Normalizer::canonical_serialized( 'i:1;junk', 'strval' ) );
		$this->assertSame( 'O:8:"WP_Theme":0:{}', Normalizer::canonical_serialized( 'O:8:"WP_Theme":0:{}', 'strval' ) );
	}
}
