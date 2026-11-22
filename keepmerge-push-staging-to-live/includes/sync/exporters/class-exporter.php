<?php
/**
 * Shared exporter code.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync\Exporters;

use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Hasher;
use SoftGlaze\SafePush\Sync\Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base of the exporters. An exporter reads one object through WordPress APIs and returns an item:
 * its normal form (typed JSON, URLs as tokens), the reference sidecar, the problems that make it
 * unsupported, and its hash. Exporters only read; the one write they can cause is a UUID for an
 * object that has none, on the source only.
 */
abstract class Exporter {

	/**
	 * Context.
	 *
	 * @var Export_Context
	 */
	protected $ctx;

	/**
	 * Creates the exporter.
	 *
	 * @param Export_Context $ctx Context.
	 */
	public function __construct( Export_Context $ctx ) {
		$this->ctx = $ctx;
	}

	/**
	 * Exports one object, or null when it is not an item of this exporter on this site.
	 *
	 * @param int|string $id Object ID (or option name).
	 * @return array|null
	 */
	abstract public function export( $id );

	/**
	 * Builds an item from its raw fields.
	 *
	 * @param string $kind  Kind.
	 * @param string $type  Post type, taxonomy or option name.
	 * @param int    $id    Local ID (0 for options and units).
	 * @param string $uuid  UUID, option:<name> or unit:<name>.
	 * @param array  $raw   Raw fields as WordPress returned them.
	 * @param array  $extra Envelope fields outside the hash (title, modified_gmt, stylesheet).
	 * @return array
	 */
	protected function item( $kind, $type, $id, $uuid, array $raw, array $extra = array() ) {
		$normalizer = $this->ctx->normalizer;
		$normalizer->reset();

		$data     = $normalizer->encode( $raw );
		$data     = is_array( $data ) ? $data : array();
		$problems = $normalizer->problems();

		$scan = $this->ctx->mapper()->scan( $kind, $data );
		$form = $scan['hash_form'];

		// The author is compared by login: an email that differs between the sites (staging
		// copies often anonymise them) does not make content differ.
		unset( $form['author']['email'] );
		if ( 'global_styles' === $kind ) {
			$form = Hasher::styles_form( $form );
		}

		$hashes = Hasher::item_hashes( $kind, $form );

		return array_merge(
			array(
				'kind'         => $kind,
				'type'         => (string) $type,
				'id'           => (int) $id,
				'uuid'         => (string) $uuid,
				'title'        => '',
				'modified_gmt' => '',
				'data'         => $data,
				'refs'         => $scan['refs'],
				'unmapped'     => $scan['unmapped'],
				'block_types'  => $scan['block_types'],
				'problems'     => $problems,
				'unsupported'  => ! empty( $problems ),
				'hash'         => $hashes['hash'],
				'groups'       => $hashes['groups'],
				'fields'       => Hasher::field_hashes( $form ),
			),
			$extra
		);
	}

	/**
	 * Post meta minus the excluded keys: key => list of values, as WordPress returns them for the
	 * key (already unserialized by core).
	 *
	 * @param int           $post_id Post ID.
	 * @param string[]      $skip    Keys this exporter carries as fields.
	 * @param callable|null $also    Extra test: function( $key ): bool (true skips).
	 * @return array<string,array>
	 */
	protected function post_meta( $post_id, array $skip = array(), $also = null ) {
		$all = get_post_meta( (int) $post_id );
		$out = array();
		foreach ( array_keys( is_array( $all ) ? $all : array() ) as $key ) {
			$key = (string) $key;
			if ( in_array( $key, $skip, true ) || Scope::meta_excluded( $key ) || ( null !== $also && call_user_func( $also, $key ) ) ) {
				continue;
			}
			$out[ $key ] = array_values( (array) get_post_meta( (int) $post_id, $key, false ) );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Term meta minus the excluded keys.
	 *
	 * @param int           $term_id Term ID.
	 * @param callable|null $also    Extra test: function( $key ): bool (true skips).
	 * @return array<string,array>
	 */
	protected function term_meta( $term_id, $also = null ) {
		$all = get_term_meta( (int) $term_id );
		$out = array();
		foreach ( array_keys( is_array( $all ) ? $all : array() ) as $key ) {
			$key = (string) $key;
			if ( Scope::meta_excluded( $key ) || ( null !== $also && call_user_func( $also, $key ) ) ) {
				continue;
			}
			$out[ $key ] = array_values( (array) get_term_meta( (int) $term_id, $key, false ) );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The author as login, plus the email on the source (live maps by login, then email); live's own
	 * answers never carry an email.
	 *
	 * @param int $user_id User ID.
	 * @return array{login:string,email?:string}
	 */
	protected function author( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return array( 'login' => '' );
		}
		$out = array( 'login' => (string) $user->user_login );
		if ( $this->ctx->is_source() ) {
			$out['email'] = (string) $user->user_email;
		}
		return $out;
	}

	/**
	 * Term assignments of a post in the included taxonomies: taxonomy => sorted term IDs.
	 *
	 * @param \WP_Post $post       Post.
	 * @param string[] $taxonomies Limit to these (default: all of the post type's included ones).
	 * @return array<string,int[]>
	 */
	protected function terms_for( \WP_Post $post, array $taxonomies = array() ) {
		$included = Scope::taxonomies();
		$taxes    = empty( $taxonomies ) ? get_object_taxonomies( $post->post_type ) : $taxonomies;
		$out      = array();
		foreach ( $taxes as $taxonomy ) {
			if ( ! in_array( $taxonomy, $included, true ) ) {
				continue;
			}
			$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) || empty( $ids ) ) {
				continue;
			}
			$ids = array_map( 'intval', $ids );
			sort( $ids );
			$out[ $taxonomy ] = $ids;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * The name of the first term of a post in a non-public taxonomy (wp_theme, areas).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	protected function first_term_name( $post_id, $taxonomy ) {
		$names = wp_get_object_terms( (int) $post_id, $taxonomy, array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) || empty( $names ) ) {
			return '';
		}
		return (string) reset( $names );
	}
}
