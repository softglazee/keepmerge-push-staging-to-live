<?php
/**
 * Item hashes.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hash of an item (blueprint §6.3): SHA-256 of the JSON of its hash form, with object keys
 * sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
 *
 * The hash form is the normal form with URLs as tokens (Normalizer), every reference replaced by
 * a canonical token that names the same object on both sites (Reference_Mapper with the export
 * context's resolver), term assignment lists sorted, and nested serialized values in their
 * canonical text form. Volatile data (modification dates, edit locks, caches, Keepmerge's own
 * meta) is never part of the normal form, so it never enters the hash. The same content on both
 * sites therefore gives the same hash even though URLs and IDs differ.
 */
final class Hasher {

	/**
	 * Hashes of an item: the item hash and, for products, one per field group (blueprint §6.6).
	 * The live-owned group of a product is hashed on its own and left out of the item hash, so
	 * a sale on live does not make the product look changed.
	 *
	 * @param string $kind      Kind.
	 * @param array  $hash_form Hash form.
	 * @return array{hash:string,groups:array<string,string>}
	 */
	public static function item_hashes( $kind, array $hash_form ) {
		$groups = array();
		if ( 'product' === $kind && isset( $hash_form['groups'] ) && is_array( $hash_form['groups'] ) ) {
			foreach ( $hash_form['groups'] as $name => $group ) {
				$groups[ $name ] = self::hash( $group );
			}
			unset( $hash_form['groups']['live_owned'] );
		}

		return array(
			'hash'   => self::hash( $hash_form ),
			'groups' => $groups,
		);
	}

	/**
	 * One hash per field of an item's hash form, so a diff can tell which fields differ even
	 * where IDs and URLs are written differently on the two sites: top-level fields, each meta
	 * key ("meta.<key>"), and each field of a product group ("groups.<group>.<field>", product
	 * meta as "groups.<group>.meta.<key>").
	 *
	 * @param array $hash_form Hash form.
	 * @return array<string,string> Field path => hash.
	 */
	public static function field_hashes( array $hash_form ) {
		$out = array();
		foreach ( $hash_form as $key => $value ) {
			$key = (string) $key;
			if ( 'meta' === $key && is_array( $value ) ) {
				foreach ( $value as $meta_key => $meta_value ) {
					$out[ 'meta.' . $meta_key ] = self::hash( $meta_value );
				}
				continue;
			}
			if ( 'groups' === $key && is_array( $value ) ) {
				foreach ( $value as $group => $fields ) {
					if ( ! is_array( $fields ) ) {
						$out[ 'groups.' . $group ] = self::hash( $fields );
						continue;
					}
					foreach ( $fields as $field => $field_value ) {
						if ( 'meta' === $field && is_array( $field_value ) ) {
							foreach ( $field_value as $meta_key => $meta_value ) {
								$out[ 'groups.' . $group . '.meta.' . $meta_key ] = self::hash( $meta_value );
							}
							continue;
						}
						$out[ 'groups.' . $group . '.' . $field ] = self::hash( $field_value );
					}
				}
				continue;
			}
			$out[ $key ] = self::hash( $value );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * SHA-256 over the canonical JSON of a value.
	 *
	 * @param mixed $value Hash form.
	 * @return string 64 hex.
	 */
	public static function hash( $value ) {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * The canonical form: object keys sorted, term lists sorted, "ser" values as canonical text.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	public static function canonical( $value, $depth = 0 ) {
		if ( ! is_array( $value ) || $depth > Normalizer::MAX_DEPTH ) {
			return $value;
		}

		if ( isset( $value[ Normalizer::MARK ] ) && 'ser' === $value[ Normalizer::MARK ] && array_key_exists( 'v', $value ) ) {
			$text = self::serialized_text( $value['v'] );
			if ( null !== $text ) {
				return array(
					Normalizer::MARK => 'ser_raw',
					's'              => $text,
				);
			}
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$item = self::canonical( $item, $depth + 1 );
			if ( 'terms' === $key && is_array( $item ) ) {
				foreach ( $item as $taxonomy => $ids ) {
					if ( is_array( $ids ) ) {
						$ids = array_map( 'strval', $ids );
						sort( $ids, SORT_STRING );
						$item[ $taxonomy ] = $ids;
					}
				}
			}
			$out[ $key ] = $item;
		}

		if ( array_keys( $out ) !== range( 0, count( $out ) - 1 ) ) {
			ksort( $out, SORT_STRING );
		}

		return $out;
	}

	/**
	 * The text form a source-side "ser" value has on a site that keeps it serialized: the value
	 * rebuilt from typed data (tokens stay tokens) and serialized, read by the canonical reader.
	 *
	 * @param mixed $typed Typed inner value.
	 * @return string|null
	 */
	private static function serialized_text( $typed ) {
		$inner = Normalizer::decode( $typed );
		if ( is_wp_error( $inner ) ) {
			return null;
		}
		// The receiving site keeps such values as serialized text; this is the same text, built
		// from plain data (arrays, scalars, stdClass), so the two hashes can be compared.
		$text = serialize( $inner ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Builds the text form of plain data for hashing only.

		return Normalizer::canonical_serialized( $text, 'strval' );
	}
}
