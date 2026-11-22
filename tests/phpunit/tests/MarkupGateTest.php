<?php
/**
 * Apply\Markup_Gate: detection only, S-81 / F-14 (part).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Apply\Markup_Gate;

/**
 * @group gate
 * @group planner
 */
class MarkupGateTest extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'softglaze_sp_markup_gate_patterns' );
		parent::tear_down();
	}

	private function rules( array $reasons ) {
		return array_column( $reasons, 'rule' );
	}

	// --- Patterns in meta, option and widget values ------------------------------------------

	/**
	 * @dataProvider every_pattern
	 *
	 * @param string $value Value.
	 * @param string $rule  Expected rule.
	 */
	public function test_every_pattern_is_found( $value, $rule ) {
		$finding = Markup_Gate::scan_string( $value );
		$this->assertNotNull( $finding, $value );
		$this->assertSame( $rule, $finding['rule'], $value );
	}

	public function every_pattern() {
		$cases = array();
		foreach ( array( 'script', 'iframe', 'object', 'embed', 'form', 'meta', 'link', 'base', 'style', 'svg', 'math' ) as $tag ) {
			$cases[ "<$tag" ]          = array( "x <$tag y=1>", 'pattern:tag' );
			$cases[ "<$tag upper" ]    = array( '<' . strtoupper( $tag ) . '>', 'pattern:tag' );
			$cases[ "</$tag" ]         = array( "</$tag>", 'pattern:tag' );
			$cases[ "&lt;$tag" ]       = array( "&lt;$tag&gt;", 'pattern:escaped_tag' );
			$cases[ "\\x3c$tag" ]      = array( "\\x3c$tag", 'pattern:escaped_tag' );
			$cases[ "\\u003c$tag" ]    = array( "\\u003c$tag", 'pattern:escaped_tag' );
			$cases[ "\\U003C$tag" ]    = array( '\\U003C' . strtoupper( $tag ), 'pattern:escaped_tag' );
			$cases[ "%3C$tag" ]        = array( "%3C$tag%3E", 'pattern:tag' );
			$cases[ "&#60;$tag" ]      = array( "&#60;$tag", 'pattern:tag' );
			$cases[ "&#x3c;$tag" ]     = array( "&#x3c;$tag", 'pattern:tag' );
		}
		$cases['srcdoc alone']              = array( 'a SRCDOC attribute', 'pattern:srcdoc' );
		$cases['javascript:']               = array( 'javascript:alert(1)', 'pattern:javascript_url' );
		$cases['JavaScript: mixed case']    = array( 'JaVaScRiPt:alert(1)', 'pattern:javascript_url' );
		$cases['java script: with a tab']   = array( "java\tscript:alert(1)", 'pattern:javascript_url' );
		$cases['javascript: url-encoded']   = array( 'javascript%3Aalert(1)', 'pattern:javascript_url' );
		$cases['javascript: entity']        = array( 'javascript&#58;alert(1)', 'pattern:javascript_url' );
		$cases['&#106;avascript:']          = array( '&#106;avascript:alert(1)', 'pattern:javascript_url' );
		$cases['vbscript:']                 = array( 'vbscript:msgbox', 'pattern:vbscript_url' );
		$cases['data:text/html']            = array( 'data:text/html;base64,AAAA', 'pattern:data_html_url' );
		$cases['data: text/html spaced']    = array( 'DATA : text/html,x', 'pattern:data_html_url' );
		$cases['onerror=']                  = array( '<img src=x onerror=alert(1)>', 'pattern:event_attribute' );
		$cases['onload =']                  = array( 'x onload = y', 'pattern:event_attribute' );
		$cases['onclick after a quote']     = array( '"x"onclick="y"', 'pattern:event_attribute' );
		$cases['onmouseover after a slash'] = array( '<a/onmouseover=y>', 'pattern:event_attribute' );
		return $cases;
	}

	/**
	 * @dataProvider ordinary_strings
	 *
	 * @param string $value Value.
	 */
	public function test_ordinary_strings_are_not_held( $value ) {
		$this->assertNull( Markup_Gate::scan_string( $value ), $value );
	}

	public function ordinary_strings() {
		return array(
			'plain'                      => array( 'Summer collection, 20% off' ),
			'url'                        => array( 'https://example.com/shop?online=1&order=asc' ),
			'json'                       => array( '{"title":"Hello","id":12,"url":"https://example.com/a.jpg"}' ),
			'serialized'                 => array( 'a:1:{s:4:"link";s:19:"https://example.com";}' ),
			'paragraph'                  => array( '<p>Contact us on <strong>Monday</strong>.</p>' ),
			'words with on'              => array( 'Turn it on=off; online, onion, condition' ),
			'scripts word'               => array( 'Our scripts and metadata are described below.' ),
			'linkedin'                   => array( 'Follow us on <a href="https://linkedin.com/x">LinkedIn</a>' ),
			'custom element link-preview' => array( '<link-preview href="x"></link-preview>' ),
			'escaped html as text'       => array( 'Use &lt;p&gt; for paragraphs' ),
			'data image'                 => array( 'data:image/png;base64,iVBORw0KGgo=' ),
			'empty'                      => array( '' ),
		);
	}

	/**
	 * JSON strings nested $levels deep whose innermost value only reads "javascript:" once its
	 * JSON escape (backslash, u0073 for "s") is decoded.
	 *
	 * @param int $levels JSON documents to decode.
	 * @return string
	 */
	private function nested_json( $levels ) {
		$value = '{"v":"java' . '\\' . 'u0073cript:alert(1)"}';
		for ( $i = 1; $i < $levels; $i++ ) {
			$value = wp_json_encode( array( 'level' . $i => $value ) );
		}
		return $value;
	}

	public function test_nested_json_is_decoded_to_depth_8() {
		// KMF-04 (final security review): the escape is decoded in the raw text as well.
		$this->assertSame( 'pattern:javascript_url', Markup_Gate::scan_string( $this->nested_json( 1 ) )['rule'], 'the raw text with its escape decoded matches' );
		foreach ( array( 1, 2, 5, 8 ) as $levels ) {
			$reasons = Markup_Gate::scan( array( 'builder' => $this->nested_json( $levels ) ), 'meta' );
			$this->assertSame( array( 'pattern:javascript_url' ), $this->rules( $reasons ), "$levels levels" );
		}
		// Depth 8 is the limit; a ninth level is not decoded, and is held (KMF-04: fail closed).
		$this->assertNotEmpty( Markup_Gate::scan( $this->nested_json( 9 ), 'meta' ) );

		$escaped = wp_json_encode( array( 'html' => '<script' ), JSON_HEX_TAG );
		$this->assertStringContainsString( '\\' . 'u003C', $escaped );
		$this->assertSame( array( 'pattern:escaped_tag' ), $this->rules( Markup_Gate::scan( $escaped, 'm' ) ) );
	}

	public function test_json_hidden_with_hex_escapes_is_found_after_decoding() {
		// JSON_HEX_TAG writes the less-than sign as an escape: the raw string matches the escaped
		// form, the decoded JSON the tag itself. Either way the value is held.
		$doc = wp_json_encode( array( 'widgets' => array( array( 'html' => '<iframe src="x">' ) ) ), JSON_HEX_TAG );
		$this->assertStringNotContainsString( '<iframe', $doc );
		$reasons = Markup_Gate::scan( $doc, 'meta._elementor_data' );
		$this->assertNotEmpty( $reasons );
	}

	public function test_a_plain_marker_is_seen_at_any_depth() {
		// Without an escape, the marker is in the raw text of every level.
		$value = 'javascript:x';
		for ( $i = 0; $i < 12; $i++ ) {
			$value = wp_json_encode( array( 'v' => $value ) );
		}
		$this->assertNotEmpty( Markup_Gate::scan( $value, 'm' ) );
	}

	public function test_url_and_entity_encodings_one_pass() {
		$this->assertNotNull( Markup_Gate::scan_string( '%3Cscript%3Ealert(1)%3C%2Fscript%3E' ) );
		$this->assertNotNull( Markup_Gate::scan_string( '&lt;iframe src=x&gt;' ) );
		$this->assertNotNull( Markup_Gate::scan_string( '&#x3C;svg onload=x&#x3E;' ) );
		$this->assertNotNull( Markup_Gate::scan_string( '%26lt%3Bscript' ), 'URL-decoded then entity-decoded' );
		$this->assertNotNull( Markup_Gate::scan_string( '&percnt;3Cscript' ), 'entity-decoded then URL-decoded' );
	}

	public function test_arrays_objects_and_keys_are_walked() {
		$object        = new stdClass();
		$object->inner = array( 'a' => 'fine', 'b' => array( 'c' => 'vbscript:x' ) );
		$reasons       = Markup_Gate::scan( array( 'list' => array( 1, true, null, 2.5, $object ) ), 'meta' );
		$this->assertSame( array( 'pattern:vbscript_url' ), $this->rules( $reasons ) );
		$this->assertSame( 'meta.list.4.inner.b.c', $reasons[0]['field'] );

		$reasons = Markup_Gate::scan( array( '<script>' => 'x' ), 'meta' );
		$this->assertSame( array( 'pattern:tag' ), $this->rules( $reasons ) );
	}

	public function test_filter_adds_literal_patterns_but_cannot_remove_or_weaken() {
		add_filter(
			'softglaze_sp_markup_gate_patterns',
			static function ( $patterns ) {
				unset( $patterns['tag'], $patterns['javascript_url'] );
				$patterns['srcdoc']   = '#never#';
				$patterns['shortcode'] = '[raw_html';
				$patterns['regex']     = '.*';
				return $patterns;
			}
		);
		$patterns = Markup_Gate::patterns();
		foreach ( Markup_Gate::PATTERNS as $id => $pattern ) {
			$this->assertSame( $pattern, $patterns[ $id ], $id );
		}
		$this->assertNotNull( Markup_Gate::scan_string( '<script>' ) );
		$this->assertNotNull( Markup_Gate::scan_string( 'srcdoc' ) );
		$this->assertSame( 'pattern:custom_shortcode', Markup_Gate::scan_string( 'x [RAW_HTML]y' )['rule'] );
		// Added text is literal: ".*" matches only the two characters.
		$this->assertNull( Markup_Gate::scan_string( 'anything at all' ) );
		$this->assertSame( 'pattern:custom_regex', Markup_Gate::scan_string( 'a.*b' )['rule'] );
	}

	public function test_a_filter_returning_garbage_keeps_the_built_ins() {
		add_filter( 'softglaze_sp_markup_gate_patterns', '__return_false' );
		$this->assertSame( Markup_Gate::PATTERNS, Markup_Gate::patterns() );
	}

	// --- kses on titles, content, excerpts and term descriptions -----------------------------

	/**
	 * @dataProvider kses_changes
	 *
	 * @param string $value   Value.
	 * @param string $context kses context.
	 */
	public function test_markup_kses_would_change_is_held( $value, $context ) {
		$this->assertNotNull( Markup_Gate::kses_change( $value, $context ), $value );
	}

	public function kses_changes() {
		return array(
			'script in content'        => array( '<p>x</p><script>alert(1)</script>', 'post' ),
			'iframe in content'        => array( '<iframe src="https://example.com"></iframe>', 'post' ),
			'onerror in content'       => array( '<img src="x" onerror="alert(1)">', 'post' ),
			'javascript: href'         => array( '<a href="javascript:alert(1)">x</a>', 'post' ),
			'style tag'                => array( '<style>body{}</style>', 'post' ),
			'form'                     => array( '<form action="x"><input name="a"></form>', 'post' ),
			'span in a title'          => array( '<span class="x">Title</span>', 'title_save_pre' ),
			'img in a title'           => array( 'Title <img src=x onerror=alert(1)>', 'title_save_pre' ),
			'script in a block attr'   => array( '<!-- wp:paragraph {"content":"<script>x</script>"} --><p>x</p><!-- /wp:paragraph -->', 'post' ),
			'div in a term description' => array( '<div>About this category</div>', 'pre_term_description' ),
			'< and > read as a tag'      => array( 'Prices < 10 and > 5', 'title_save_pre' ),
		);
	}

	/**
	 * @dataProvider ordinary_markup
	 *
	 * @param string $value   Value.
	 * @param string $context kses context.
	 */
	public function test_ordinary_content_is_not_held( $value, $context ) {
		// rel and target on links in term descriptions are allowed by newer cores' kses; WordPress
		// 6.5 strips them, so there the receiver's write would change the value and the gate holds
		// it (the behaviour the gate exists for).
		$allowed = wp_kses_allowed_html( $context );
		if ( 'pre_term_description' === $context && false !== strpos( $value, ' rel=' ) && empty( $allowed['a']['rel'] ) ) {
			$this->assertNotNull( Markup_Gate::kses_change( $value, $context ), $value );
			return;
		}
		$this->assertNull( Markup_Gate::kses_change( $value, $context ), $value );
	}

	public function ordinary_markup() {
		$image = "<!-- wp:image {\"id\":14,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"https://example.com/a-1024x683.png\" alt=\"\" class=\"wp-image-14\"/></figure>\n<!-- /wp:image -->";
		return array(
			'plain title'                 => array( 'Tom & Jerry', 'title_save_pre' ),
			'title with <'                => array( 'Prices < 10', 'title_save_pre' ),
			'title with allowed em'       => array( 'A <em>fine</em> title', 'title_save_pre' ),
			'paragraph block'             => array( "<!-- wp:paragraph -->\n<p>Hello &amp; welcome, see <a href=\"https://example.com/?a=1&b=2\">this</a>.</p>\n<!-- /wp:paragraph -->", 'post' ),
			'image block, self-closing'   => array( $image, 'post' ),
			'cover block with style'      => array( '<div class="wp-block-cover" style="min-height: 400px;aspect-ratio:unset"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span></div>', 'post' ),
			'empty object block attrs'    => array( "<!-- wp:woocommerce/product-collection {\"query\":{\"taxQuery\":{},\"exclude\":[]}} /-->", 'post' ),
			'single-quoted attribute'     => array( "<p class='lead'>x</p>", 'post' ),
			'term description with link' => array( 'See <a href="https://example.com" rel="nofollow">our shop</a>.', 'pre_term_description' ),
			'empty'                       => array( '', 'post' ),
		);
	}

	// --- Whole items ----------------------------------------------------------------------------

	public function test_post_item_reasons_name_the_field() {
		$reasons = Markup_Gate::check(
			'post',
			array(
				'post_title'   => 'Fine',
				'post_content' => '<p>ok</p>',
				'post_excerpt' => '<script>x</script>',
				'meta'         => array(
					'_builder' => array( '[{"type":"html","html":"<iframe src=x>"}]' ),
					'_seo'     => array( 'A normal description' ),
				),
			)
		);
		$this->assertSame( array( 'post_excerpt', 'meta._builder.0' ), array_column( $reasons, 'field' ) );
		$this->assertSame( array( 'kses', 'pattern:tag' ), $this->rules( $reasons ) );
		foreach ( $reasons as $reason ) {
			$this->assertLessThanOrEqual( Markup_Gate::EXCERPT_CHARS + 1, mb_strlen( $reason['excerpt'] ) );
		}
	}

	public function test_ordinary_post_item_is_not_held() {
		$this->assertSame(
			array(),
			Markup_Gate::check(
				'post',
				array(
					'post_title'   => 'Summer & Winter',
					'post_content' => "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->",
					'post_excerpt' => '',
					'meta'         => array( '_price_note' => array( 'From 10 €' ) ),
				)
			)
		);
	}

	public function test_global_styles_with_custom_css_or_insecure_styles() {
		$clean = wp_json_encode(
			array(
				'version'                     => 3,
				'settings'                    => array(),
				'styles'                      => array( 'color' => array( 'background' => '#fbfaf7' ) ),
				'isGlobalStylesUserThemeJSON' => true,
			)
		);
		$this->assertSame( array(), Markup_Gate::check( 'global_styles', array( 'post_title' => 'Custom Styles', 'post_content' => $clean ) ) );

		$css = wp_json_encode(
			array(
				'version'                     => 3,
				'styles'                      => array( 'css' => 'body{color:red}' ),
				'isGlobalStylesUserThemeJSON' => true,
			)
		);
		$this->assertContains( 'styles_css', $this->rules( Markup_Gate::check( 'global_styles', array( 'post_content' => $css ) ) ) );

		$block_css = wp_json_encode(
			array(
				'version'                     => 3,
				'styles'                      => array( 'blocks' => array( 'core/paragraph' => array( 'css' => 'color:red' ) ) ),
				'isGlobalStylesUserThemeJSON' => true,
			)
		);
		$this->assertContains( 'styles_css', $this->rules( Markup_Gate::check( 'global_styles', array( 'post_content' => $block_css ) ) ) );

		$insecure = wp_json_encode(
			array(
				'version'                     => 3,
				'styles'                      => array( 'color' => array( 'background' => 'url(javascript:alert(1))' ) ),
				'isGlobalStylesUserThemeJSON' => true,
			)
		);
		$this->assertContains( 'insecure_styles', $this->rules( Markup_Gate::check( 'global_styles', array( 'post_content' => $insecure ) ) ) );
	}

	public function test_terms_menus_options_widgets_and_products() {
		$this->assertSame( array( 'kses' ), $this->rules( Markup_Gate::check( 'term', array( 'description' => '<div onclick="x">d</div>' ) ) ) );
		$this->assertSame( array( 'pattern:tag' ), $this->rules( Markup_Gate::check( 'term', array( 'description' => 'ok', 'meta' => array( 'k' => array( '<script>' ) ) ) ) ) );

		$menu = Markup_Gate::check(
			'menu',
			array(
				'description' => '',
				'items'       => array(
					array( 'title' => 'Home', 'url' => 'https://example.com', 'meta' => array() ),
					array( 'title' => 'Bad', 'url' => 'javascript:alert(1)', 'meta' => array() ),
				),
			)
		);
		$this->assertSame( array( 'items.1.url' ), array_column( $menu, 'field' ) );

		$this->assertSame( array( 'pattern:event_attribute' ), $this->rules( Markup_Gate::check( 'option', array( 'name' => 'blogdescription', 'value' => '<b onmouseover=x>' ) ) ) );
		$this->assertSame( array(), Markup_Gate::check( 'option', array( 'name' => 'blogdescription', 'value' => 'Just another site' ) ) );

		$widgets = Markup_Gate::check( 'widgets', array( 'widgets' => array( 'widget_custom_html' => array( 2 => array( 'content' => '<iframe src=x></iframe>' ) ) ) ) );
		$this->assertSame( array( 'pattern:tag' ), $this->rules( $widgets ) );

		$product = Markup_Gate::check(
			'product',
			array(
				'groups' => array(
					'content'  => array(
						'name'              => 'Shirt',
						'description'       => '<p>Soft</p><script>x</script>',
						'short_description' => 'Short',
						'purchase_note'     => 'Thanks <iframe src=x>',
						'meta'              => array(),
					),
					'identity' => array( 'sku' => 'SKU-1' ),
				),
			)
		);
		$this->assertSame( array( 'groups.content.description', 'groups.content.purchase_note' ), array_column( $product, 'field' ) );
	}

	public function test_the_gate_changes_no_global_state() {
		global $wp_filter;
		$before = array_keys( $wp_filter );
		$user   = get_current_user_id();
		Markup_Gate::check( 'post', array( 'post_title' => '<b>x</b>', 'post_content' => '<script>x</script>' ) );
		$this->assertSame( $before, array_keys( $wp_filter ) );
		$this->assertSame( $user, get_current_user_id() );
	}
}
