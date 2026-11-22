<?php
/**
 * Markup gate: which parts of an item core's filters would change, or that carry active markup.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which items wait for a live administrator. Pure and read-only (no filter changes, no
 * user switch, no writes), so the source can predict holds and live can check received items.
 * An item is held when core's kses for a user without unfiltered_html would change a title,
 * content, excerpt or description (compared token by token, so equivalent markup is not a change),
 * when global styles would lose insecure properties or carry custom CSS, or when any string in
 * meta, option or widget values (also URL-, entity- and nested-JSON-decoded) looks like active
 * markup. Filters can add patterns, never remove the built-in ones.
 */
final class Markup_Gate {

	/**
	 * Levels of JSON strings inside JSON strings that are decoded and looked into.
	 */
	const MAX_JSON_DEPTH = 8;

	/**
	 * Array nesting walked inside one value (a guard against hostile structures).
	 */
	const MAX_WALK_DEPTH = 64;

	/**
	 * Characters of context shown around a finding.
	 */
	const EXCERPT_CHARS = 120;

	/**
	 * Tag names whose presence in a stored value means active markup.
	 */
	const TAGS = 'script|iframe|object|embed|form|meta|link|base|style|svg|math';

	/**
	 * Patterns applied to every string of meta, option and widget values (and to their decoded
	 * forms). Keys name the rule in the hold reason.
	 *
	 * @var array<string,string>
	 */
	const PATTERNS = array(
		'tag'             => '#<\s*/?\s*(?:script|iframe|object|embed|form|meta|link|base|style|svg|math)(?![a-z0-9_-])#i',
		'srcdoc'          => '#srcdoc#i',
		'javascript_url'  => '#j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:#i',
		'vbscript_url'    => '#v\s*b\s*s\s*c\s*r\s*i\s*p\s*t\s*:#i',
		'data_html_url'   => '#data\s*:\s*text/html#i',
		'event_attribute' => '#(?:^|[\s"\'/>])on[a-z]{3,}\s*=#i',
		'escaped_tag'     => '#(?:&lt;?|\\\\x3c|\\\\u003c)\s*/?\s*(?:script|iframe|object|embed|form|meta|link|base|style|svg|math)(?![a-z0-9_-])#i',
	);

	/**
	 * Setting keys whose value a theme or plugin prints as code or markup. Plain JavaScript carries
	 * no tag the patterns would find, so these are held by name.
	 */
	const CODE_KEY = '/(?:\A|[_.-])(?:js|javascript|scripts?|css|codes?|html)(?:\z|[_.-])/i';

	/**
	 * Hold reasons for one item.
	 *
	 * Core's kses does not filter meta, options or widgets, so the gate holds anything that looks like
	 * active markup or code there for a live administrator.
	 *
	 * @param string $kind Item kind (post, product, attachment, pattern, navigation, template,
	 *                     template_part, global_styles, term, menu, option, widgets).
	 * @param array  $data The item's fields as plain PHP values (typed JSON decoded, URL tokens
	 *                     replaced by the site's own address).
	 * @return array[] Reasons: field, rule, excerpt. Empty when nothing would be held.
	 */
	public static function check( $kind, array $data ) {
		$reasons = array();

		switch ( $kind ) {
			case 'global_styles':
				self::kses_field( $reasons, $data, 'post_title', 'title_save_pre' );
				if ( isset( $data['post_content'] ) && is_string( $data['post_content'] ) ) {
					foreach ( self::global_styles_reasons( $data['post_content'] ) as $reason ) {
						$reasons[] = $reason;
					}
				}
				break;

			case 'product':
				$groups  = isset( $data['groups'] ) && is_array( $data['groups'] ) ? $data['groups'] : array();
				$content = isset( $groups['content'] ) && is_array( $groups['content'] ) ? $groups['content'] : array();
				self::kses_field( $reasons, $content, 'name', 'title_save_pre', 'groups.content.name' );
				self::kses_field( $reasons, $content, 'description', 'post', 'groups.content.description' );
				self::kses_field( $reasons, $content, 'short_description', 'post', 'groups.content.short_description' );
				unset( $content['name'], $content['description'], $content['short_description'] );
				self::scan_into( $reasons, $content, 'groups.content' );
				if ( isset( $groups['identity'] ) ) {
					self::scan_into( $reasons, $groups['identity'], 'groups.identity' );
				}
				break;

			case 'term':
				self::term_description( $reasons, $data, 'description' );
				if ( isset( $data['meta'] ) ) {
					self::scan_into( $reasons, $data['meta'], 'meta' );
				}
				break;

			case 'menu':
				self::term_description( $reasons, $data, 'description' );
				$items = isset( $data['items'] ) && is_array( $data['items'] ) ? array_values( $data['items'] ) : array();
				foreach ( $items as $i => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$path = 'items.' . $i;
					self::kses_field( $reasons, $item, 'title', 'title_save_pre', $path . '.title' );
					self::kses_field( $reasons, $item, 'attr_title', 'post', $path . '.attr_title' );
					self::kses_field( $reasons, $item, 'description', 'post', $path . '.description' );
					unset( $item['title'], $item['attr_title'], $item['description'] );
					self::scan_into( $reasons, $item, $path );
				}
				break;

			case 'option':
				if ( array_key_exists( 'value', $data ) ) {
					self::scan_into( $reasons, $data['value'], 'value' );
					$name = isset( $data['name'] ) && is_string( $data['name'] ) ? $data['name'] : '';
					if ( '' !== $name && 1 === preg_match( self::CODE_KEY, $name ) && self::code_value( $data['value'] ) ) {
						$reasons[] = self::reason( 'value', 'code_setting', self::excerpt( (string) $data['value'], 0 ) );
					}
					self::code_keys_into( $reasons, $data['value'], 'value' );
				}
				break;

			case 'widgets':
				self::scan_into( $reasons, $data, 'widgets' );
				self::code_keys_into( $reasons, $data, 'widgets' );
				break;

			default:
				// Posts, pages, custom types, patterns, navigation, templates, parts, attachments.
				self::kses_field( $reasons, $data, 'post_title', 'title_save_pre' );
				self::kses_field( $reasons, $data, 'post_content', 'post' );
				self::kses_field( $reasons, $data, 'post_excerpt', 'post' );
				foreach ( array( 'meta', 'alt', 'metadata' ) as $field ) {
					if ( isset( $data[ $field ] ) ) {
						self::scan_into( $reasons, $data[ $field ], $field );
					}
				}
		}

		return $reasons;
	}

	/**
	 * Whether the kses filter of a context changes a value beyond how its markup is written.
	 *
	 * @param string $value   Value as it would be saved.
	 * @param string $context 'title_save_pre' (titles, as wp_filter_kses() runs inside that filter),
	 *                        'post' (wp_filter_post_kses()) or 'pre_term_description'.
	 * @return string|null Excerpt around the first change, or null when kses changes nothing.
	 */
	public static function kses_change( $value, $context ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}

		// wp_filter_kses() and wp_filter_post_kses() unslash, run wp_kses() and slash again; the
		// value here is unslashed already, so wp_kses() alone gives the same result.
		$filtered = wp_kses( $value, $context );
		if ( $filtered === $value ) {
			return null;
		}

		$before = self::tokens( $value );
		$after  = self::tokens( $filtered );
		if ( null !== $before && null !== $after ) {
			$at = self::first_difference( $before, $after );
			return null === $at ? null : self::excerpt( $before[ $at ]['text'] ?? '', 0 );
		}

		// Markup the HTML API could not read to the end: compare the written forms after the
		// changes kses makes to every value (entities, lone "<", the space before "/>").
		if ( self::loose( $value ) === self::loose( $filtered ) ) {
			return null;
		}
		return self::excerpt( $value, self::common_prefix( $value, $filtered ) );
	}

	/**
	 * Hold reasons of a global styles post content (theme.json-shaped JSON).
	 *
	 * @param string $json Post content.
	 * @return array[]
	 */
	public static function global_styles_reasons( $json ) {
		$reasons = array();
		$decoded = json_decode( (string) $json, true );

		if ( ! is_array( $decoded ) ) {
			// Core stores anything else as it is, after wp_filter_post_kses(); that check applies.
			$change = self::kses_change( (string) $json, 'post' );
			if ( null !== $change ) {
				$reasons[] = self::reason( 'post_content', 'kses', $change );
			}
			return $reasons;
		}

		$css = self::find_css( isset( $decoded['styles'] ) ? $decoded['styles'] : null, 'styles' );
		if ( null !== $css ) {
			$reasons[] = self::reason( 'post_content', 'styles_css', $css );
		}

		unset( $decoded['isGlobalStylesUserThemeJSON'] );
		if ( class_exists( 'WP_Theme_JSON' ) ) {
			$sanitized = \WP_Theme_JSON::remove_insecure_properties( $decoded, 'custom' );
			if ( self::canonical_json( $sanitized ) !== self::canonical_json( $decoded ) ) {
				$reasons[] = self::reason( 'post_content', 'insecure_styles', self::excerpt( (string) wp_json_encode( $decoded ), 0 ) );
			}
		}

		// Core runs wp_filter_post_kses() on the JSON text after its own filter as well.
		$change = self::kses_change( (string) $json, 'post' );
		if ( null !== $change ) {
			$reasons[] = self::reason( 'post_content', 'kses', $change );
		}

		return $reasons;
	}

	/**
	 * The first pattern finding in a value of any shape (meta, options, widgets).
	 *
	 * @param mixed  $value Value (string, array, stdClass, scalar).
	 * @param string $path  Field path for the reason.
	 * @return array[] Reasons (at most one per string).
	 */
	public static function scan( $value, $path ) {
		$reasons = array();
		self::scan_into( $reasons, $value, $path );
		return $reasons;
	}

	/**
	 * Pattern finding for one string: its own text, its URL-decoded and HTML-entity-decoded forms
	 * (one pass each, and the two combined).
	 *
	 * @param string $text Text.
	 * @return array{rule:string,excerpt:string}|null
	 */
	public static function scan_string( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return null;
		}

		$url      = rawurldecode( $text );
		$entities = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$variants = array(
			$text,
			$url,
			$entities,
			html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			rawurldecode( $entities ),
		);
		// JSON and JavaScript escapes (i, \x69) inside a tag or attribute name, decoded.
		foreach ( $variants as $variant ) {
			$variants[] = self::unescaped( $variant );
		}
		$variants = array_unique( $variants );

		$patterns = self::patterns();
		foreach ( $variants as $variant ) {
			foreach ( $patterns as $id => $pattern ) {
				if ( 1 === preg_match( $pattern, $variant, $match, PREG_OFFSET_CAPTURE ) ) {
					return array(
						'rule'    => 'pattern:' . $id,
						'excerpt' => self::excerpt( $variant, (int) $match[0][1] ),
					);
				}
			}
		}
		return null;
	}

	/**
	 * The built-in patterns plus literal text added by the softglaze_sp_markup_gate_patterns filter.
	 *
	 * @return array<string,string> Rule ID => PCRE pattern.
	 */
	public static function patterns() {
		/**
		 * Adds text the markup gate looks for in meta, option and widget values.
		 *
		 * Add entries as rule ID => literal text (matched case-insensitively). The built-in
		 * patterns are applied whatever the filter returns.
		 *
		 * @param array<string,string> $patterns Built-in rule IDs and their PCRE patterns.
		 */
		$filtered = apply_filters( 'softglaze_sp_markup_gate_patterns', self::PATTERNS );

		$out = self::PATTERNS;
		if ( ! is_array( $filtered ) ) {
			return $out;
		}
		foreach ( $filtered as $id => $text ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || isset( self::PATTERNS[ $id ] ) || ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > 200 ) {
				continue;
			}
			$out[ 'custom_' . $id ] = '#' . preg_quote( $text, '#' ) . '#i';
		}
		return $out;
	}

	/**
	 * Adds the kses reason of one string field, if any.
	 *
	 * @param array  $reasons Reasons (appended to).
	 * @param array  $fields  Fields.
	 * @param string $name    Field name.
	 * @param string $context kses context.
	 * @param string $path    Path for the reason (defaults to the name).
	 * @return void
	 */
	private static function kses_field( array &$reasons, array $fields, $name, $context, $path = '' ) {
		if ( ! isset( $fields[ $name ] ) || ! is_string( $fields[ $name ] ) ) {
			return;
		}
		$change = self::kses_change( $fields[ $name ], $context );
		if ( null !== $change ) {
			$reasons[] = self::reason( '' === $path ? $name : $path, 'kses', $change );
		}
	}

	/**
	 * A term or menu description: wp_filter_post_kses(), and the stricter pre_term_description
	 * context core applies to every term description.
	 *
	 * @param array  $reasons Reasons (appended to).
	 * @param array  $fields  Fields.
	 * @param string $name    Field name.
	 * @return void
	 */
	private static function term_description( array &$reasons, array $fields, $name ) {
		if ( ! isset( $fields[ $name ] ) || ! is_string( $fields[ $name ] ) ) {
			return;
		}
		foreach ( array( 'post', 'pre_term_description' ) as $context ) {
			$change = self::kses_change( $fields[ $name ], $context );
			if ( null !== $change ) {
				$reasons[] = self::reason( $name, 'kses', $change );
				return;
			}
		}
	}

	/**
	 * Walks a value and adds one reason per string that matches a pattern.
	 *
	 * @param array  $reasons    Reasons (appended to).
	 * @param mixed  $value      Value.
	 * @param string $path       Path.
	 * @param int    $depth      Array nesting.
	 * @param int    $json_depth JSON strings decoded on the way here.
	 * @return void
	 */
	private static function scan_into( array &$reasons, $value, $path, $depth = 0, $json_depth = 0 ) {
		if ( $depth > self::MAX_WALK_DEPTH ) {
			$reasons[] = self::reason( $path, 'too_deep', '' );
			return;
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				// Keys are data too: a builder can keep markup in a key.
				$finding = is_string( $key ) ? self::scan_string( $key ) : null;
				if ( null !== $finding ) {
					$reasons[] = self::reason( $path, $finding['rule'], $finding['excerpt'] );
				}
				self::scan_into( $reasons, $item, $path . '.' . $key, $depth + 1, $json_depth );
			}
			return;
		}
		if ( ! is_string( $value ) ) {
			return;
		}

		$finding = self::scan_string( $value );
		if ( null !== $finding ) {
			$reasons[] = self::reason( $path, $finding['rule'], $finding['excerpt'] );
			return;
		}

		// A JSON document or JSON string stored as a string (page builders): its own strings are
		// looked at too, with JSON escapes such as < decoded. What cannot be looked into (nested
		// deeper than the decoder reads, or JSON inside JSON past MAX_JSON_DEPTH) is held.
		$trimmed = ltrim( $value );
		if ( '' === $trimmed || ! in_array( $trimmed[0], array( '{', '[', '"' ), true ) ) {
			return;
		}
		$decoded = json_decode( $value, false, self::MAX_WALK_DEPTH );
		if ( JSON_ERROR_DEPTH === json_last_error() ) {
			$reasons[] = self::reason( $path, 'too_deep', '' );
			return;
		}
		if ( ! is_array( $decoded ) && ! is_object( $decoded ) && ! is_string( $decoded ) ) {
			return;
		}
		if ( $json_depth >= self::MAX_JSON_DEPTH ) {
			$reasons[] = self::reason( $path, 'too_deep', '' );
			return;
		}
		self::scan_into( $reasons, $decoded, $path, $depth + 1, $json_depth + 1 );
	}

	/**
	 * Text with JSON and JavaScript escapes of ASCII characters decoded (i and \x69, also
	 * escaped again as \\u0069), so an escape inside a tag or attribute name does not hide it.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function unescaped( $text ) {
		return (string) preg_replace_callback(
			'/\\\\+(?:u([0-9a-fA-F]{4})|x([0-9a-fA-F]{2}))/',
			static function ( $m ) {
				$code = hexdec( isset( $m[2] ) && '' !== $m[2] ? $m[2] : $m[1] );
				return $code > 0 && $code < 0x80 ? chr( (int) $code ) : $m[0];
			},
			(string) $text
		);
	}

	/**
	 * Hold reasons for strings stored under code-like setting keys (CODE_KEY), at any depth.
	 *
	 * @param array[] $reasons Reasons (appended).
	 * @param mixed   $value   Value.
	 * @param string  $path    Field path.
	 * @param int     $depth   Nesting depth.
	 * @return void
	 */
	private static function code_keys_into( array &$reasons, $value, $path, $depth = 0 ) {
		if ( $depth > self::MAX_WALK_DEPTH ) {
			return;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && 1 === preg_match( self::CODE_KEY, $key ) && self::code_value( $item ) ) {
				$reasons[] = self::reason( $path . '.' . $key, 'code_setting', self::excerpt( (string) $item, 0 ) );
				continue;
			}
			self::code_keys_into( $reasons, $item, $path . '.' . $key, $depth + 1 );
		}
	}

	/**
	 * Whether a value under a code-like key is something a theme could print: a string that is not
	 * empty, a number or an on/off word.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function code_value( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$text = strtolower( trim( $value ) );
		return '' !== $text && ! is_numeric( $text ) && ! in_array( $text, array( 'true', 'false', 'yes', 'no', 'on', 'off' ), true );
	}

	/**
	 * The tokens of a piece of HTML: kind, name, closer flag, attributes (decoded values, sorted)
	 * and decoded text, as the HTML API reads them. Null when the markup ends inside a token.
	 *
	 * @param string $html HTML.
	 * @return array[]|null
	 */
	private static function tokens( $html ) {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$out       = array();

		while ( $processor->next_token() ) {
			$type  = (string) $processor->get_token_type();
			$token = array(
				'type' => $type,
				'name' => (string) $processor->get_token_name(),
			);

			if ( '#tag' === $type ) {
				$token['closer'] = $processor->is_tag_closer();
				$attributes      = array();
				$names           = $processor->get_attribute_names_with_prefix( '' );
				foreach ( is_array( $names ) ? $names : array() as $name ) {
					$attributes[ $name ] = self::attribute_value( $name, $processor->get_attribute( $name ) );
				}
				ksort( $attributes, SORT_STRING );
				$token['attributes'] = $attributes;
				// A readable form of the tag for the excerpt (shown as text).
				$shown = '<' . ( $token['closer'] ? '/' : '' ) . strtolower( $token['name'] );
				foreach ( $attributes as $name => $attribute ) {
					$shown .= ' ' . $name . ( true === $attribute ? '' : '="' . $attribute . '"' );
				}
				$token['text'] = $shown . '>';
				// Raw text elements (script, style, textarea, title) carry their text here.
				$token['inner'] = $processor->get_modifiable_text();
			} elseif ( '#comment' === $type ) {
				$token['text'] = self::comment_text( $processor->get_modifiable_text() );
			} else {
				$token['text'] = $processor->get_modifiable_text();
			}

			$out[] = $token;
		}

		if ( $processor->paused_at_incomplete_token() ) {
			return null;
		}

		return $out;
	}

	/**
	 * A comment as compared. A block delimiter's attributes are compared as data: core's kses
	 * re-encodes them (wp_pre_kses_block_attributes()), which writes an empty object "{}" as "[]"
	 * without changing any value.
	 *
	 * @param string $text Comment text.
	 * @return string
	 */
	private static function comment_text( $text ) {
		if ( 1 !== preg_match( '#\A(\s*/?wp:[a-z0-9_/-]+\s*)(\{.*\})(\s*/?\s*)\z#s', (string) $text, $m ) ) {
			return (string) $text;
		}
		$attributes = json_decode( $m[2], true, self::MAX_WALK_DEPTH );
		if ( ! is_array( $attributes ) ) {
			return (string) $text;
		}
		return trim( $m[1] ) . ' ' . self::canonical_json( $attributes ) . ' ' . trim( $m[3] );
	}

	/**
	 * An attribute value as compared: booleans as true; style values without the spacing that
	 * safecss_filter_attr() rewrites.
	 *
	 * @param string $name  Attribute name.
	 * @param mixed  $value Value from the HTML API.
	 * @return mixed
	 */
	private static function attribute_value( $name, $value ) {
		if ( ! is_string( $value ) ) {
			return true === $value ? true : $value;
		}
		if ( 'style' === $name ) {
			$parts = array();
			foreach ( explode( ';', $value ) as $declaration ) {
				$declaration = trim( (string) preg_replace( '/\s*:\s*/', ':', $declaration, 1 ) );
				if ( '' !== $declaration ) {
					$parts[] = $declaration;
				}
			}
			return implode( ';', $parts );
		}
		return $value;
	}

	/**
	 * Index of the first token that differs, or null when both lists match.
	 *
	 * @param array[] $before Tokens of the value.
	 * @param array[] $after  Tokens after kses.
	 * @return int|null
	 */
	private static function first_difference( array $before, array $after ) {
		// Text kses split around a removed tag reads as two text tokens; joined, equal text
		// still differs in the tag list, which is what makes the value different.
		$count = max( count( $before ), count( $after ) );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $before[ $i ], $after[ $i ] ) || $before[ $i ] !== $after[ $i ] ) {
				return isset( $before[ $i ] ) ? $i : max( 0, count( $before ) - 1 );
			}
		}
		return null;
	}

	/**
	 * The written form after the changes kses makes to any value, for markup the HTML API could
	 * not tokenize.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function loose( $html ) {
		$html = wp_kses_normalize_entities( $html );
		$html = str_replace( array( '&lt;', '&gt;' ), array( '<', '>' ), $html );
		return (string) preg_replace( '#\s*/>#', '/>', $html );
	}

	/**
	 * The CSS found under styles, as an excerpt, or null.
	 *
	 * @param mixed  $styles Styles branch.
	 * @param string $path   Path so far.
	 * @param int    $depth  Depth.
	 * @return string|null
	 */
	private static function find_css( $styles, $path, $depth = 0 ) {
		if ( ! is_array( $styles ) || $depth > self::MAX_WALK_DEPTH ) {
			return null;
		}
		foreach ( $styles as $key => $value ) {
			if ( 'css' === $key && is_string( $value ) && '' !== trim( $value ) ) {
				return self::excerpt( $path . '.css: ' . $value, 0 );
			}
			$found = self::find_css( $value, $path . '.' . $key, $depth + 1 );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * A JSON text of an array with object keys sorted and empty branches left out, so that a
	 * sanitizer that drops an empty "settings": [] is not seen as a change.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return string
	 */
	private static function canonical_json( $value, $depth = 0 ) {
		return (string) wp_json_encode( self::prune( $value, $depth ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Sorted keys, empty arrays removed.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function prune( $value, $depth ) {
		if ( ! is_array( $value ) || $depth > self::MAX_WALK_DEPTH ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$item = self::prune( $item, $depth + 1 );
			if ( array() === $item ) {
				continue;
			}
			$out[ $key ] = $item;
		}
		if ( array_keys( $out ) !== range( 0, count( $out ) - 1 ) ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/**
	 * Length of the common start of two strings.
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 * @return int
	 */
	private static function common_prefix( $a, $b ) {
		$max = min( strlen( $a ), strlen( $b ) );
		$i   = 0;
		while ( $i < $max && $a[ $i ] === $b[ $i ] ) {
			++$i;
		}
		return $i;
	}

	/**
	 * Plain-text context around an offset: control characters removed, invalid UTF-8 dropped,
	 * at most EXCERPT_CHARS characters. Screens show it as text, never as HTML.
	 *
	 * @param string $text   Text.
	 * @param int    $offset Byte offset of the finding.
	 * @return string
	 */
	private static function excerpt( $text, $offset ) {
		$text  = (string) $text;
		$start = max( 0, (int) $offset - 30 );
		$slice = substr( $text, $start, self::EXCERPT_CHARS * 2 );
		$slice = wp_check_invalid_utf8( (string) $slice, true );
		$slice = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $slice );
		$slice = (string) preg_replace( '/\s+/', ' ', $slice );
		if ( function_exists( 'mb_substr' ) ) {
			$slice = mb_substr( $slice, 0, self::EXCERPT_CHARS, 'UTF-8' );
		} else {
			$slice = substr( $slice, 0, self::EXCERPT_CHARS );
		}
		return ( $start > 0 ? '…' : '' ) . trim( $slice );
	}

	/**
	 * One reason.
	 *
	 * @param string $field   Field path.
	 * @param string $rule    Rule.
	 * @param string $excerpt Excerpt.
	 * @return array{field:string,rule:string,excerpt:string}
	 */
	private static function reason( $field, $rule, $excerpt ) {
		return array(
			'field'   => (string) $field,
			'rule'    => (string) $rule,
			'excerpt' => (string) $excerpt,
		);
	}
}
