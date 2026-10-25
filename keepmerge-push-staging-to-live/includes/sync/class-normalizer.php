<?php
/**
 * Typed JSON values and URL tokens.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns stored values into the typed JSON of blueprint §8.1 and back, and replaces this site's
 * own address with tokens (§8.2).
 *
 * Typed JSON: arrays and scalars map directly; a float is {"__sgsp":"float","v":"1.5"}; a
 * string that is not UTF-8 is {"__sgsp":"bin","hex":"…"}; a stdClass is
 * {"__sgsp":"object","props":{…}}. Any other object makes the item unsupported: it is reported,
 * never guessed. An array that already uses the key "__sgsp", and a string that already holds
 * "{{sgsp:", make the item unsupported too, so a value is never silently altered.
 *
 * Serialized strings inside a value (double serialization) are decoded on the source only
 * (encode() in source mode) with allowed_classes false, into {"__sgsp":"ser","v":…}; live
 * rebuilds such a value with serialize(). Live never unserializes anything it receives, and its
 * own exports (destination mode) leave such strings undecoded: they become
 * {"__sgsp":"ser_raw","s":…}, the stored text with its URLs tokenized and its string lengths
 * recomputed by a small reader that never creates a PHP value. The Hasher brings the source's
 * "ser" values into the same text form, so both sites hash the same content to the same value.
 */
final class Normalizer {

	const MARK = '__sgsp';

	const TOKEN_LITERAL = '{{sgsp:';

	const MAX_DEPTH = 64;

	/**
	 * Origins with their token names, longest first.
	 *
	 * @var array<int,array{name:string,url:string}>
	 */
	private $origins;

	/**
	 * Whether nested serialized strings are decoded (source mode).
	 *
	 * @var bool
	 */
	private $decode_nested;

	/**
	 * Regex that finds every form of every origin, and the token for each match.
	 *
	 * @var array{pattern:string,tokens:array<string,string>}|null
	 */
	private $matcher = null;

	/**
	 * Problems met since the last reset(): codes such as unsupported_object:WP_Post.
	 *
	 * @var string[]
	 */
	private $problems = array();

	/**
	 * Creates a normalizer.
	 *
	 * @param array $origins       List of array( 'name' => 'home'|'site', 'url' => 'https://…' ).
	 * @param bool  $decode_nested Source mode: decode nested serialized strings.
	 */
	public function __construct( array $origins, $decode_nested ) {
		$this->origins       = $origins;
		$this->decode_nested = (bool) $decode_nested;
	}

	/**
	 * A normalizer for this site as the sending side.
	 *
	 * @return Normalizer
	 */
	public static function for_source() {
		return new self( self::local_origins(), true );
	}

	/**
	 * A normalizer for this site answering a remote read (never decodes nested strings).
	 *
	 * @return Normalizer
	 */
	public static function for_destination() {
		return new self( self::local_origins(), false );
	}

	/**
	 * This site's origins: home_url(), and site_url() when it differs (blueprint §8.2).
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	public static function local_origins() {
		$out  = array(
			array(
				'name' => 'home',
				'url'  => untrailingslashit( home_url() ),
			),
		);
		$site = untrailingslashit( site_url() );
		if ( strtolower( $site ) !== strtolower( $out[0]['url'] ) ) {
			$out[] = array(
				'name' => 'site',
				'url'  => $site,
			);
		}
		return $out;
	}

	/**
	 * Problems met since the last reset().
	 *
	 * @return string[]
	 */
	public function problems() {
		return array_values( array_unique( $this->problems ) );
	}

	/**
	 * Forgets the problems (one item at a time).
	 *
	 * @return void
	 */
	public function reset() {
		$this->problems = array();
	}

	/**
	 * Whether this normalizer decodes nested serialized strings.
	 *
	 * @return bool
	 */
	public function decodes_nested() {
		return $this->decode_nested;
	}

	/**
	 * Encodes a value as typed JSON (PHP arrays ready for wp_json_encode()).
	 *
	 * @param mixed $value Value as WordPress returned it.
	 * @param int   $depth Nesting depth.
	 * @return mixed
	 */
	public function encode( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			$this->problems[] = 'too_deep';
			return null;
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return array(
				self::MARK => 'float',
				'v'        => self::float_text( $value ),
			);
		}
		if ( is_string( $value ) ) {
			return $this->encode_string( $value, $depth );
		}
		if ( is_array( $value ) ) {
			if ( array_key_exists( self::MARK, $value ) ) {
				$this->problems[] = 'reserved_key';
				return null;
			}
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->encode( $item, $depth + 1 );
			}
			return $out;
		}
		if ( $value instanceof \stdClass ) {
			return $this->object_marker( get_object_vars( $value ), $depth );
		}
		if ( is_object( $value ) ) {
			$class = get_class( $value );
			if ( '__PHP_Incomplete_Class' === $class ) {
				// A nested serialized stdClass comes back incomplete because no class is allowed;
				// its properties are plain data and are kept. Anything else is unsupported.
				$props = (array) $value;
				$name  = isset( $props['__PHP_Incomplete_Class_Name'] ) ? (string) $props['__PHP_Incomplete_Class_Name'] : '';
				if ( 'stdClass' === $name ) {
					unset( $props['__PHP_Incomplete_Class_Name'] );
					return $this->object_marker( $props, $depth );
				}
				$class = '' === $name ? $class : $name;
			}
			$this->problems[] = 'unsupported_object:' . substr( sanitize_text_field( $class ), 0, 100 );
			return null;
		}

		$this->problems[] = 'unsupported_type:' . gettype( $value );
		return null;
	}

	/**
	 * Replaces this site's origins in a string with tokens, longest match first. A match must end
	 * at a host boundary, so https://example.com.other.net is left alone.
	 *
	 * @param string $text Text.
	 * @param bool   $flag Whether a literal "{{sgsp:" counts as a problem.
	 * @return string
	 */
	public function tokenize( $text, $flag = true ) {
		$text = (string) $text;
		if ( $flag && false !== strpos( $text, self::TOKEN_LITERAL ) ) {
			$this->problems[] = 'token_literal';
			return $text;
		}
		if ( '' === $text || ( false === stripos( $text, '//' ) && false === stripos( $text, '%2F%2F' ) && false === stripos( $text, '\/\/' ) ) ) {
			return $text;
		}

		$matcher = $this->matcher();
		$tokens  = $matcher['tokens'];
		$result  = preg_replace_callback(
			$matcher['pattern'],
			static function ( $m ) use ( $tokens ) {
				$key = strtolower( $m[0] );
				return isset( $tokens[ $key ] ) ? $tokens[ $key ] : $m[0];
			},
			$text
		);

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Replaces tokens with the same forms of the given origins (used by the receiving site for
	 * its own address, and by tests).
	 *
	 * @param string $text    Text with tokens.
	 * @param array  $origins Origins (see local_origins()).
	 * @return string
	 */
	public static function detokenize( $text, array $origins ) {
		$text = (string) $text;
		if ( false === strpos( $text, self::TOKEN_LITERAL ) ) {
			return $text;
		}

		$by_name = array();
		foreach ( $origins as $origin ) {
			$by_name[ $origin['name'] ] = (string) $origin['url'];
		}
		if ( ! isset( $by_name['home'] ) ) {
			return $text;
		}
		if ( ! isset( $by_name['site'] ) ) {
			$by_name['site'] = $by_name['home'];
		}

		$map = array();
		foreach ( $by_name as $name => $url ) {
			$rest = (string) preg_replace( '#\A[a-z][a-z0-9+.-]*://#i', '', $url );

			$map[ '{{sgsp:' . $name . '}}' ]   = $url;
			$map[ '{{sgsp:' . $name . ':j}}' ] = str_replace( '/', '\/', $url );
			$map[ '{{sgsp:' . $name . ':u}}' ] = rawurlencode( $url );
			$map[ '{{sgsp:' . $name . ':p}}' ] = '//' . $rest;
		}

		return strtr( $text, $map );
	}

	/**
	 * Decodes typed JSON back into PHP values. It never decodes serialized text: a "ser" value is
	 * rebuilt and serialized. Unknown markers, markers with other keys (for example
	 * {"__sgsp":"object","class":"WP_Theme"}) and raw floats are refused.
	 *
	 * @param mixed      $typed   Typed value (from json_decode( …, true )).
	 * @param array|null $origins When given, tokens in strings become these origins.
	 * @param int        $depth   Nesting depth.
	 * @return mixed|WP_Error
	 */
	public static function decode( $typed, $origins = null, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return self::invalid( 'too_deep' );
		}
		if ( null === $typed || is_bool( $typed ) || is_int( $typed ) ) {
			return $typed;
		}
		if ( is_string( $typed ) ) {
			return is_array( $origins ) ? self::detokenize( $typed, $origins ) : $typed;
		}
		if ( ! is_array( $typed ) ) {
			return self::invalid( 'raw_' . gettype( $typed ) );
		}

		if ( ! array_key_exists( self::MARK, $typed ) ) {
			$out = array();
			foreach ( $typed as $key => $item ) {
				$value = self::decode( $item, $origins, $depth + 1 );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$out[ $key ] = $value;
			}
			return $out;
		}

		$mark = $typed[ self::MARK ];
		$keys = array_keys( $typed );
		sort( $keys );

		switch ( $mark ) {
			case 'float':
				if ( array( self::MARK, 'v' ) !== $keys || ! is_string( $typed['v'] ) || 1 !== preg_match( '/\A(?:-?(?:[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|INF)|NAN)\z/', $typed['v'] ) ) {
					return self::invalid( 'float' );
				}
				return self::float_value( $typed['v'] );

			case 'bin':
				if ( array( self::MARK, 'hex' ) !== $keys || ! is_string( $typed['hex'] ) || 1 !== preg_match( '/\A(?:[0-9a-f]{2})*\z/', $typed['hex'] ) ) {
					return self::invalid( 'bin' );
				}
				return (string) hex2bin( $typed['hex'] );

			case 'object':
				if ( array( self::MARK, 'props' ) !== $keys || ! is_array( $typed['props'] ) ) {
					return self::invalid( 'object' );
				}
				$object = new \stdClass();
				foreach ( $typed['props'] as $key => $item ) {
					$value = self::decode( $item, $origins, $depth + 1 );
					if ( is_wp_error( $value ) ) {
						return $value;
					}
					$prop          = (string) $key;
					$object->$prop = $value;
				}
				return $object;

			case 'ser':
				if ( array( self::MARK, 'v' ) !== $keys ) {
					return self::invalid( 'ser' );
				}
				$inner = self::decode( $typed['v'], $origins, $depth + 1 );
				if ( is_wp_error( $inner ) ) {
					return $inner;
				}
				// Rebuilds a double-serialized value from plain data; serialize() computes the
				// string lengths, so no string surgery is needed (blueprint §8.1).
				return serialize( $inner ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Rebuilds a value that was stored serialized inside another value; input is plain data (arrays, scalars, stdClass).

			default:
				return self::invalid( 'marker' );
		}
	}

	/**
	 * The text of a serialized value with every string run through $leaf and its length
	 * recomputed. A small reader of PHP's serialize format: it copies class names and custom
	 * payloads verbatim and never creates a PHP value, so it is safe on any input. Returns null
	 * when the text is not one complete serialized value.
	 *
	 * @param string   $raw  Serialized text.
	 * @param callable $leaf Receives each string value, returns its replacement.
	 * @return string|null
	 */
	public static function canonical_serialized( $raw, $leaf ) {
		$raw = (string) $raw;
		$pos = 0;
		$out = '';
		if ( ! self::read_value( $raw, $pos, $out, $leaf, 0 ) || strlen( $raw ) !== $pos ) {
			return null;
		}
		return $out;
	}

	/**
	 * The text form of a float: the shortest of 15, 16 or 17 significant digits that reads back
	 * as the same float, so both sites write the same text whatever their ini settings.
	 *
	 * @param float $value Float.
	 * @return string
	 */
	public static function float_text( $value ) {
		if ( is_nan( $value ) ) {
			return 'NAN';
		}
		if ( is_infinite( $value ) ) {
			return $value > 0 ? 'INF' : '-INF';
		}
		foreach ( array( 15, 16, 17 ) as $precision ) {
			$text = sprintf( '%.' . $precision . 'G', $value );
			if ( (float) $text === $value ) {
				break;
			}
		}
		$text = str_replace( 'E+', 'E', $text );
		return (string) preg_replace( '/E(-?)0*([0-9])/', 'e$1$2', $text );
	}

	/**
	 * Encodes one string.
	 *
	 * @param string $value String.
	 * @param int    $depth Depth.
	 * @return mixed
	 */
	private function encode_string( $value, $depth ) {
		if ( 1 !== preg_match( '//u', $value ) ) {
			return array(
				self::MARK => 'bin',
				'hex'      => bin2hex( $value ),
			);
		}

		if ( is_serialized( $value ) ) {
			if ( $this->decode_nested ) {
				$decoded = $this->decode_nested_string( $value );
				if ( $decoded['ok'] ) {
					return array(
						self::MARK => 'ser',
						'v'        => $this->encode( $decoded['value'], $depth + 1 ),
					);
				}
			} else {
				$canonical = $this->canonical_text( $value );
				if ( null !== $canonical ) {
					return array(
						self::MARK => 'ser_raw',
						's'        => $canonical,
					);
				}
			}
		}

		return $this->tokenize( $value );
	}

	/**
	 * Source mode: decodes a serialized string stored inside a value.
	 *
	 * @param string $value Serialized text.
	 * @return array{ok:bool,value:mixed}
	 */
	private function decode_nested_string( $value ) {
		if ( 'b:0;' === $value ) {
			return array(
				'ok'    => true,
				'value' => false,
			);
		}
		// Only text the reader accepts as one complete value is decoded, so PHP's decoder never
		// meets malformed input.
		if ( null === self::canonical_serialized( $value, 'strval' ) ) {
			return array(
				'ok'    => false,
				'value' => null,
			);
		}

		// Source side only, on this site's own stored data (blueprint §8.1): allowed_classes false
		// means no object is ever created; an object comes back as __PHP_Incomplete_Class and
		// makes the item unsupported in encode().
		$decoded = unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Source-side decoding of this site's own double-serialized data with allowed_classes false; never applied to received data.

		return array(
			'ok'    => false !== $decoded,
			'value' => $decoded,
		);
	}

	/**
	 * Destination mode: the canonical text of a serialized string (tokens, recomputed lengths).
	 *
	 * @param string $value Serialized text.
	 * @return string|null
	 */
	private function canonical_text( $value ) {
		$self = $this;
		$leaf = static function ( $text ) use ( &$leaf, $self ) {
			if ( 1 !== preg_match( '//u', $text ) ) {
				return $text;
			}
			if ( is_serialized( $text ) ) {
				$inner = Normalizer::canonical_serialized( $text, $leaf );
				if ( null !== $inner ) {
					return $inner;
				}
			}
			return $self->tokenize( $text, false );
		};

		return self::canonical_serialized( $value, $leaf );
	}

	/**
	 * The object marker.
	 *
	 * @param array $props Properties.
	 * @param int   $depth Depth.
	 * @return array
	 */
	private function object_marker( array $props, $depth ) {
		$out = array();
		foreach ( $props as $key => $item ) {
			$out[ (string) $key ] = $this->encode( $item, $depth + 1 );
		}
		return array(
			self::MARK => 'object',
			'props'    => $out,
		);
	}

	/**
	 * The regex for this normalizer's origins.
	 *
	 * @return array{pattern:string,tokens:array<string,string>}
	 */
	private function matcher() {
		if ( null !== $this->matcher ) {
			return $this->matcher;
		}

		$tokens = array();
		foreach ( $this->origins as $origin ) {
			$name = (string) $origin['name'];
			$url  = (string) $origin['url'];
			$rest = (string) preg_replace( '#\A[a-z][a-z0-9+.-]*://#i', '', $url );
			if ( '' === $rest ) {
				continue;
			}
			foreach ( array( 'https', 'http' ) as $scheme ) {
				$full = $scheme . '://' . $rest;

				$tokens[ strtolower( $full ) ]                           = '{{sgsp:' . $name . '}}';
				$tokens[ strtolower( str_replace( '/', '\/', $full ) ) ] = '{{sgsp:' . $name . ':j}}';
				$tokens[ strtolower( rawurlencode( $full ) ) ]           = '{{sgsp:' . $name . ':u}}';
			}
			$tokens[ strtolower( '//' . $rest ) ] = '{{sgsp:' . $name . ':p}}';
		}

		$needles = array_keys( $tokens );
		usort(
			$needles,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$quoted = array();
		foreach ( $needles as $needle ) {
			$quoted[] = preg_quote( $needle, '~' );
		}

		$this->matcher = array(
			'pattern' => empty( $quoted ) ? '~(?!)~' : '~(?:' . implode( '|', $quoted ) . ')(?![A-Za-z0-9_-]|[.:][A-Za-z0-9])~i',
			'tokens'  => $tokens,
		);

		return $this->matcher;
	}

	/**
	 * Reads one serialized value at $pos, appending its canonical text to $out.
	 *
	 * @param string   $s     Text.
	 * @param int      $pos   Position (advanced).
	 * @param string   $out   Output (appended).
	 * @param callable $leaf  String callback.
	 * @param int      $depth Depth.
	 * @return bool
	 */
	private static function read_value( $s, &$pos, &$out, $leaf, $depth ) {
		if ( $depth > self::MAX_DEPTH || $pos >= strlen( $s ) ) {
			return false;
		}

		switch ( $s[ $pos ] ) {
			case 'N':
				return self::read_literal( $s, $pos, $out, '/\GN;/' );
			case 'b':
				return self::read_literal( $s, $pos, $out, '/\Gb:[01];/' );
			case 'i':
				return self::read_literal( $s, $pos, $out, '/\Gi:[+-]?[0-9]+;/' );
			case 'd':
				return self::read_literal( $s, $pos, $out, '/\Gd:(?:[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?|INF|-INF|NAN);/' );
			case 'r':
			case 'R':
				return self::read_literal( $s, $pos, $out, '/\G[rR]:[0-9]+;/' );
			case 's':
				$text = self::read_string( $s, $pos );
				if ( null === $text ) {
					return false;
				}
				$text = (string) call_user_func( $leaf, $text );
				$out .= 's:' . strlen( $text ) . ':"' . $text . '";';
				return true;
			case 'E':
				$text = self::read_string( $s, $pos, 'E' );
				if ( null === $text ) {
					return false;
				}
				$out .= 'E:' . strlen( $text ) . ':"' . $text . '";';
				return true;
			case 'a':
				if ( 1 !== preg_match( '/\Ga:([0-9]+):\{/', $s, $m, 0, $pos ) ) {
					return false;
				}
				$pos += strlen( $m[0] );
				$out .= $m[0];
				return self::read_pairs( $s, $pos, $out, $leaf, $depth, (int) $m[1] );
			case 'O':
				if ( 1 !== preg_match( '/\GO:([0-9]+):"/', $s, $m, 0, $pos ) ) {
					return false;
				}
				$length = (int) $m[1];
				$start  = $pos + strlen( $m[0] );
				if ( 1 !== preg_match( '/\G":([0-9]+):\{/', $s, $n, 0, $start + $length ) ) {
					return false;
				}
				$out .= substr( $s, $pos, strlen( $m[0] ) + $length ) . $n[0];
				$pos  = $start + $length + strlen( $n[0] );
				return self::read_pairs( $s, $pos, $out, $leaf, $depth, (int) $n[1] );
			case 'C':
				if ( 1 !== preg_match( '/\GC:([0-9]+):"/', $s, $m, 0, $pos ) ) {
					return false;
				}
				$length = (int) $m[1];
				$start  = $pos + strlen( $m[0] );
				if ( 1 !== preg_match( '/\G":([0-9]+):\{/', $s, $n, 0, $start + $length ) ) {
					return false;
				}
				$payload_at = $start + $length + strlen( $n[0] );
				$payload    = (int) $n[1];
				if ( '}' !== substr( $s, $payload_at + $payload, 1 ) ) {
					return false;
				}
				$out .= substr( $s, $pos, $payload_at + $payload + 1 - $pos );
				$pos  = $payload_at + $payload + 1;
				return true;
			default:
				return false;
		}
	}

	/**
	 * Reads key/value pairs and the closing brace.
	 *
	 * @param string   $s     Text.
	 * @param int      $pos   Position.
	 * @param string   $out   Output.
	 * @param callable $leaf  String callback.
	 * @param int      $depth Depth.
	 * @param int      $count Pairs.
	 * @return bool
	 */
	private static function read_pairs( $s, &$pos, &$out, $leaf, $depth, $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			// Keys are copied as they are: they are names, not content.
			if ( ! self::read_value( $s, $pos, $out, 'strval', $depth + 1 ) ) {
				return false;
			}
			if ( ! self::read_value( $s, $pos, $out, $leaf, $depth + 1 ) ) {
				return false;
			}
		}
		if ( '}' !== substr( $s, $pos, 1 ) ) {
			return false;
		}
		++$pos;
		$out .= '}';
		return true;
	}

	/**
	 * Reads s:N:"…"; (or E:N:"…";) and returns the N bytes.
	 *
	 * @param string $s    Text.
	 * @param int    $pos  Position.
	 * @param string $type 's' or 'E'.
	 * @return string|null
	 */
	private static function read_string( $s, &$pos, $type = 's' ) {
		if ( 1 !== preg_match( '/\G' . $type . ':([0-9]+):"/', $s, $m, 0, $pos ) ) {
			return null;
		}
		$length = (int) $m[1];
		$start  = $pos + strlen( $m[0] );
		if ( $start + $length + 2 > strlen( $s ) || '";' !== substr( $s, $start + $length, 2 ) ) {
			return null;
		}
		$pos = $start + $length + 2;
		return (string) substr( $s, $start, $length );
	}

	/**
	 * Copies a literal token.
	 *
	 * @param string $s       Text.
	 * @param int    $pos     Position.
	 * @param string $out     Output.
	 * @param string $pattern Anchored pattern.
	 * @return bool
	 */
	private static function read_literal( $s, &$pos, &$out, $pattern ) {
		if ( 1 !== preg_match( $pattern, $s, $m, 0, $pos ) ) {
			return false;
		}
		$pos += strlen( $m[0] );
		$out .= $m[0];
		return true;
	}

	/**
	 * A float from its text form.
	 *
	 * @param string $text Text.
	 * @return float
	 */
	private static function float_value( $text ) {
		if ( 'NAN' === $text ) {
			return NAN;
		}
		if ( 'INF' === $text ) {
			return INF;
		}
		if ( '-INF' === $text ) {
			return -INF;
		}
		return (float) $text;
	}

	/**
	 * A refusal of received typed data.
	 *
	 * @param string $reason Reason.
	 * @return WP_Error
	 */
	private static function invalid( $reason ) {
		return new WP_Error( 'invalid_value', 'invalid_value', array( 'reason' => $reason ) );
	}
}
