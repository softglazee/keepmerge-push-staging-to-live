<?php
/**
 * How an export runs: which side, which pairing.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The context of one export run.
 *
 * Source: nested serialized strings are decoded, objects without a UUID get one (a write to this
 * site's own meta only), and a reference is named for the hash by the live ID it is linked to
 * through this pairing's strong baseline matches ("p:<live ID>"), or by its UUID when it has no
 * link yet ("u:<uuid>").
 *
 * Destination (answering a remote read): nothing is written, nested serialized strings stay
 * text, and a reference is named by its own ID ("p:<ID>"). For a matched object the two names
 * are equal, which is what makes the hashes of unchanged items equal on both sites.
 */
final class Export_Context {

	/**
	 * Normalizer for this side.
	 *
	 * @var Normalizer
	 */
	public $normalizer;

	/**
	 * 'source' or 'destination'.
	 *
	 * @var string
	 */
	private $role;

	/**
	 * Pairing row ID (source; 0 without a pairing).
	 *
	 * @var int
	 */
	private $pairing_id;

	/**
	 * Linked local ID => remote ID, per space.
	 *
	 * @var array{post:array<int,int>,term:array<int,int>}
	 */
	private $links = array(
		'post' => array(),
		'term' => array(),
	);

	/**
	 * Known UUIDs per space, loaded on first use.
	 *
	 * @var array{post:array<int,string>|null,term:array<int,string>|null}
	 */
	private $uuids = array(
		'post' => null,
		'term' => null,
	);

	/**
	 * Mapper with this context's resolver.
	 *
	 * @var Reference_Mapper|null
	 */
	private $mapper = null;

	/**
	 * Use source() or destination().
	 *
	 * @param string $role       Role.
	 * @param int    $pairing_id Pairing row ID.
	 */
	private function __construct( $role, $pairing_id ) {
		$this->role       = $role;
		$this->pairing_id = absint( $pairing_id );
		$this->normalizer = 'source' === $role ? Normalizer::for_source() : Normalizer::for_destination();

		if ( 'source' === $role && $this->pairing_id > 0 ) {
			$this->links['post'] = Sync_State::linked_map( $this->pairing_id, 'post' );
			$this->links['term'] = Sync_State::linked_map( $this->pairing_id, 'term' );
		}
	}

	/**
	 * The sending side, for a pairing (0: no pairing yet).
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return Export_Context
	 */
	public static function source( $pairing_id = 0 ) {
		return new self( 'source', $pairing_id );
	}

	/**
	 * The receiving side answering a remote read: writes nothing.
	 *
	 * @return Export_Context
	 */
	public static function destination() {
		return new self( 'destination', 0 );
	}

	/**
	 * Whether this is the sending side.
	 *
	 * @return bool
	 */
	public function is_source() {
		return 'source' === $this->role;
	}

	/**
	 * The UUID of an object: assigned when missing on the source, read only on the destination.
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    ID.
	 * @return string
	 */
	public function uuid( $space, $id ) {
		$space = 'term' === $space ? 'term' : 'post';
		$id    = absint( $id );
		if ( null === $this->uuids[ $space ] ) {
			$this->uuids[ $space ] = Identity::all( $space );
		}
		if ( isset( $this->uuids[ $space ][ $id ] ) ) {
			return $this->uuids[ $space ][ $id ];
		}
		if ( ! $this->is_source() ) {
			return '';
		}
		$uuid = Identity::assign( $space, $id );
		if ( '' !== $uuid ) {
			$this->uuids[ $space ][ $id ] = $uuid;
		}
		return $uuid;
	}

	/**
	 * Forgets cached UUIDs (after the baseline adopted or renewed some).
	 *
	 * @return void
	 */
	public function forget_uuids() {
		$this->uuids = array(
			'post' => null,
			'term' => null,
		);
	}

	/**
	 * The canonical name of a referenced object for the hash form.
	 *
	 * @param string $space  'post' or 'term'.
	 * @param int    $id     Local ID.
	 * @param string $uuid   Its UUID ('' when none).
	 * @param bool   $exists Whether it exists here.
	 * @return string
	 */
	public function canon( $space, $id, $uuid, $exists ) {
		$prefix = 'term' === $space ? 't:' : 'p:';
		$id     = (int) $id;
		if ( ! $exists ) {
			return 'x:' . $id;
		}
		if ( ! $this->is_source() ) {
			return $prefix . $id;
		}
		$links = $this->links[ 'term' === $space ? 'term' : 'post' ];
		if ( isset( $links[ $id ] ) ) {
			return $prefix . $links[ $id ];
		}
		return '' === $uuid ? 'n:' . $id : 'u:' . $uuid;
	}

	/**
	 * A reference mapper using this context.
	 *
	 * @return Reference_Mapper
	 */
	public function mapper() {
		if ( null === $this->mapper ) {
			$this->mapper = new Reference_Mapper( array( $this, 'uuid' ), array( $this, 'canon' ) );
		}
		return $this->mapper;
	}
}
