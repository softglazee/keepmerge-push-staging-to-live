<?php
/**
 * Classification of one item: the three-way table and the first-sync date rules.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure classification (no I/O) of one plan row from S (staging hash now), L (live hash now) and
 * B (base hash from live's sync_state).
 *
 * Classes and default actions:
 *   exists both, S = B, L = B            unchanged             none
 *   S ≠ B, L = B                         changed on staging    Push
 *   S = B, L ≠ B                         changed on live       Keep live
 *   S ≠ B, L ≠ B, S = L                  changed identically   record base
 *   S ≠ B, L ≠ B, S ≠ L                  conflict              undecided
 *   staging only, not on live            new on staging        Create (Skip when the slug is taken)
 *   was in base, gone from staging       deleted on staging    Keep on live (Trash: posts only)
 *   was in base, gone from live, S ≠ B   deleted on live, changed on staging: conflict (Recreate / Leave deleted)
 *   was in base, gone from live, S = B   deleted on live       Leave deleted
 * Items only on live are never touched and are not part of a plan.
 *
 * Without a base (a pairing's first plan), strong baseline matches are decided by modification
 * dates against the clone date; anything dates cannot decide is a conflict. Products are compared
 * per field group; the live-owned group (stock, sales, ratings) is never pushed by default.
 */
final class Classifier {

	const UNCHANGED = 'unchanged';

	const STAGING_CHANGED = 'staging_changed';

	const LIVE_CHANGED = 'live_changed';

	const BOTH_SAME = 'both_same';

	const CONFLICT = 'conflict';

	const NEW_STAGING = 'new_staging';

	const DELETED_STAGING = 'deleted_staging';

	const DELETED_LIVE_CHANGED = 'deleted_live_chg';

	const DELETED_LIVE = 'deleted_live';

	const UNSUPPORTED = 'unsupported';

	const EXCLUDED = 'excluded';

	/**
	 * Every class a row can have.
	 */
	const CLASSES = array( self::UNCHANGED, self::STAGING_CHANGED, self::LIVE_CHANGED, self::BOTH_SAME, self::CONFLICT, self::NEW_STAGING, self::DELETED_STAGING, self::DELETED_LIVE_CHANGED, self::DELETED_LIVE, self::UNSUPPORTED, self::EXCLUDED );

	/**
	 * Every action a row or group can carry.
	 */
	const ACTIONS = array( 'none', 'push', 'skip', 'keep_live', 'use_staging', 'record_base', 'create', 'keep_on_live', 'trash_on_live', 'recreate', 'leave_deleted', 'push_stock', 'out_of_scope' );

	/**
	 * Actions that send an item's content to live.
	 */
	const SENT = array( 'push', 'use_staging', 'create', 'recreate' );

	/**
	 * Product field groups compared one by one (the live-owned group is handled apart).
	 */
	const GROUPS = array( 'content', 'pricing', 'identity' );

	/**
	 * Kinds that carry no modification time, so dates cannot decide them.
	 */
	const NO_TIMESTAMP_KINDS = array( 'term', 'menu', 'option', 'widgets' );

	/**
	 * Classifies one item.
	 *
	 * @param array $in {
	 *     Inputs.
	 *
	 *     @type string   $kind        Item kind.
	 *     @type bool     $exists_here Whether this site still has the item.
	 *     @type string   $confidence  Baseline match: strong, medium, none, duplicate, excluded,
	 *                                 pushed, or '' when this site has no sync row for it.
	 *     @type string   $live_state  found, missing or excluded (manifest answer).
	 *     @type string   $s           Staging hash ('' when gone here).
	 *     @type string   $l           Live hash ('' when not found).
	 *     @type string   $b           Base hash ('' when none).
	 *     @type string[] $s_groups    Staging group hashes (products).
	 *     @type string[] $l_groups    Live group hashes.
	 *     @type string[] $b_groups    Base group hashes.
	 *     @type bool     $unsupported The staging item cannot be pushed (PHP objects, token literal).
	 *     @type string   $s_modified  Staging modification time (GMT, Y-m-d H:i:s) or ''.
	 *     @type string   $l_modified  Live modification time or ''.
	 *     @type int|null $clone_gmt   Start of the clone date (Unix time) or null when unknown.
	 *     @type bool     $slug_taken  Live has another item with this slug.
	 * }
	 * @return array{class:string,action:string,choices:string[],badges:string[],groups:array}
	 */
	public static function classify( array $in ) {
		$in = array_merge(
			array(
				'kind'        => 'post',
				'exists_here' => true,
				'confidence'  => '',
				'live_state'  => 'missing',
				's'           => '',
				'l'           => '',
				'b'           => '',
				's_groups'    => array(),
				'l_groups'    => array(),
				'b_groups'    => array(),
				'unsupported' => false,
				's_modified'  => '',
				'l_modified'  => '',
				'clone_gmt'   => null,
				'slug_taken'  => false,
			),
			$in
		);

		$kind = (string) $in['kind'];

		if ( $in['exists_here'] && $in['unsupported'] ) {
			return self::result( self::UNSUPPORTED, 'skip', array(), array( 'unsupported' ) );
		}
		if ( 'excluded' === $in['live_state'] ) {
			return self::result( self::EXCLUDED, 'skip', array(), array( 'excluded_on_live' ) );
		}

		$found = 'found' === $in['live_state'] && '' !== $in['l'];
		$base  = (string) $in['b'];

		// Gone from this site.
		if ( ! $in['exists_here'] ) {
			if ( ! $found ) {
				return self::result( self::UNCHANGED, 'none', array(), array( 'gone_both' ) );
			}
			$choices = array( 'keep_on_live' );
			if ( 'post' === $kind ) {
				$choices[] = 'trash_on_live';
			}
			return self::result( self::DELETED_STAGING, 'keep_on_live', $choices, array() );
		}

		// Gone from live (or never there).
		if ( ! $found ) {
			if ( '' !== $base ) {
				if ( hash_equals( $base, (string) $in['s'] ) ) {
					return self::result( self::DELETED_LIVE, 'leave_deleted', array( 'leave_deleted', 'recreate' ), array() );
				}
				return self::result( self::DELETED_LIVE_CHANGED, '', array( 'recreate', 'leave_deleted' ), array() );
			}
			if ( in_array( $in['confidence'], array( 'strong', 'pushed' ), true ) ) {
				// Linked at the baseline, gone from live since: first-sync dates decide.
				$dates = self::dates( $kind, $in, true );
				if ( 'staging' === $dates || 'both' === $dates ) {
					return self::result( self::DELETED_LIVE_CHANGED, '', array( 'recreate', 'leave_deleted' ), array( 'by_dates' ) );
				}
				if ( 'live' === $dates || 'none' === $dates ) {
					return self::result( self::DELETED_LIVE, 'leave_deleted', array( 'leave_deleted', 'recreate' ), array( 'by_dates' ) );
				}
				return self::result( self::DELETED_LIVE_CHANGED, '', array( 'recreate', 'leave_deleted' ), array( 'no_history' ) );
			}
			if ( $in['slug_taken'] || 'duplicate' === $in['confidence'] || 'medium' === $in['confidence'] ) {
				return self::result( self::NEW_STAGING, 'skip', array( 'skip', 'create' ), array( 'slug_taken' ) );
			}
			return self::result( self::NEW_STAGING, 'create', array( 'create', 'skip' ), array() );
		}

		// On both sites.
		if ( 'medium' === $in['confidence'] && '' === $base ) {
			if ( hash_equals( (string) $in['l'], (string) $in['s'] ) ) {
				return self::result( self::UNCHANGED, 'none', array(), array( 'matched_slug_date' ) );
			}
			return self::result( self::CONFLICT, '', array( 'keep_live', 'use_staging' ), array( 'matched_slug_date' ) );
		}

		if ( 'product' === $kind && ! empty( $in['s_groups'] ) && ! empty( $in['l_groups'] ) ) {
			return self::product( $in );
		}

		return self::three_way( $kind, $in, (string) $in['s'], (string) $in['l'], $base, 'item' );
	}

	/**
	 * The three-way rows of the table (and the first-sync rules when there is no base) for an
	 * item or one product group.
	 *
	 * @param string $kind  Kind.
	 * @param array  $in    Inputs (confidence, dates).
	 * @param string $s     Staging hash.
	 * @param string $l     Live hash.
	 * @param string $b     Base hash ('' none).
	 * @param string $scope 'item' or 'group'.
	 * @return array
	 */
	private static function three_way( $kind, array $in, $s, $l, $b, $scope ) {
		$group = 'group' === $scope;
		$skip  = $group ? 'keep_live' : 'skip';

		if ( hash_equals( $l, $s ) && ( '' === $b || hash_equals( $b, $s ) ) ) {
			return self::result( self::UNCHANGED, 'none', array(), array() );
		}

		if ( '' !== $b ) {
			$s_moved = ! hash_equals( $b, $s );
			$l_moved = ! hash_equals( $b, $l );
			if ( $s_moved && ! $l_moved ) {
				return self::result( self::STAGING_CHANGED, 'push', array( 'push', $skip ), array() );
			}
			if ( ! $s_moved && $l_moved ) {
				return self::result( self::LIVE_CHANGED, 'keep_live', array( 'keep_live', 'use_staging' ), array() );
			}
			if ( hash_equals( $l, $s ) ) {
				return self::result( self::BOTH_SAME, 'record_base', array(), array() );
			}
			return self::result( self::CONFLICT, '', array( 'keep_live', 'use_staging' ), array() );
		}

		// No base: the first plan. Only linked (strong) matches are decided by dates.
		if ( ! in_array( $in['confidence'], array( 'strong', 'pushed' ), true ) ) {
			return self::result( self::CONFLICT, '', array( 'keep_live', 'use_staging' ), array( 'no_history' ) );
		}

		switch ( self::dates( $kind, $in, false ) ) {
			case 'staging':
				return self::result( self::STAGING_CHANGED, 'push', array( 'push', $skip ), array( 'by_dates' ) );
			case 'live':
				return self::result( self::LIVE_CHANGED, 'keep_live', array( 'keep_live', 'use_staging' ), array( 'by_dates' ) );
			case 'both':
				return self::result( self::CONFLICT, '', array( 'keep_live', 'use_staging' ), array( 'changed_both_since_clone' ) );
			default:
				return self::result( self::CONFLICT, '', array( 'keep_live', 'use_staging' ), array( 'no_history' ) );
		}
	}

	/**
	 * Which side changed after the clone date: 'staging', 'live', 'both', 'none' (neither, so a
	 * change dates cannot see, such as meta only), or 'unknown' (no clone date, or a kind or item
	 * without timestamps).
	 *
	 * @param string $kind         Kind.
	 * @param array  $in           Inputs.
	 * @param bool   $staging_only Only the staging time is known (the item is gone from live).
	 * @return string
	 */
	public static function dates( $kind, array $in, $staging_only ) {
		$clone = isset( $in['clone_gmt'] ) ? $in['clone_gmt'] : null;
		if ( null === $clone || in_array( $kind, self::NO_TIMESTAMP_KINDS, true ) ) {
			return 'unknown';
		}
		$s = self::time( isset( $in['s_modified'] ) ? $in['s_modified'] : '' );
		$l = self::time( isset( $in['l_modified'] ) ? $in['l_modified'] : '' );
		if ( null === $s || ( ! $staging_only && null === $l ) ) {
			return 'unknown';
		}

		$s_after = $s >= (int) $clone;
		if ( $staging_only ) {
			return $s_after ? 'staging' : 'none';
		}
		$l_after = $l >= (int) $clone;

		if ( $s_after && $l_after ) {
			return 'both';
		}
		if ( $s_after ) {
			return 'staging';
		}
		if ( $l_after ) {
			return 'live';
		}
		return 'none';
	}

	/**
	 * A product compared group by group.
	 *
	 * @param array $in Inputs.
	 * @return array
	 */
	private static function product( array $in ) {
		$s_groups = self::hashes( $in['s_groups'] );
		$l_groups = self::hashes( $in['l_groups'] );
		$b_groups = self::hashes( $in['b_groups'] );
		$item_b   = (string) $in['b'];
		$groups   = array();

		// The item hash covers fields outside the groups (date, author). When only those differ,
		// they count as content.
		$envelope = ! hash_equals( (string) $in['l'], (string) $in['s'] );
		foreach ( self::GROUPS as $name ) {
			if ( ! hash_equals( self::pick( $l_groups, $name ), self::pick( $s_groups, $name ) ) ) {
				$envelope = false;
				break;
			}
		}

		foreach ( self::GROUPS as $name ) {
			$s = self::pick( $s_groups, $name );
			$l = self::pick( $l_groups, $name );
			$b = self::pick( $b_groups, $name );

			if ( 'content' === $name && $envelope ) {
				$groups[ $name ] = self::three_way( 'product', $in, (string) $in['s'], (string) $in['l'], $item_b, 'group' );
				continue;
			}

			if ( '' === $b && '' !== $item_b ) {
				// A base without group hashes: the item base still tells which side moved.
				if ( hash_equals( $s, $l ) ) {
					$groups[ $name ] = self::result( self::UNCHANGED, 'none', array(), array() );
				} elseif ( hash_equals( $item_b, (string) $in['l'] ) ) {
					$groups[ $name ] = self::result( self::STAGING_CHANGED, 'push', array( 'push', 'keep_live' ), array() );
				} elseif ( hash_equals( $item_b, (string) $in['s'] ) ) {
					$groups[ $name ] = self::result( self::LIVE_CHANGED, 'keep_live', array( 'keep_live', 'use_staging' ), array() );
				} else {
					$groups[ $name ] = self::result( self::CONFLICT, '', array( 'keep_live', 'use_staging' ), array() );
				}
				continue;
			}

			$groups[ $name ] = self::three_way( 'product', $in, $s, $l, $b, 'group' );
		}

		// Live-owned: never pushed by default; "Push stock" only when live's stock is unchanged
		// since the base. Without a base that cannot be shown, so it is refused.
		$s_owned = self::pick( $s_groups, 'live_owned' );
		$l_owned = self::pick( $l_groups, 'live_owned' );
		$b_owned = self::pick( $b_groups, 'live_owned' );
		$choices = array( 'keep_live' );
		if ( '' !== $b_owned && hash_equals( $b_owned, $l_owned ) && ! hash_equals( $s_owned, $l_owned ) ) {
			$choices[] = 'push_stock';
		}
		$badges = array( 'live_owned' );
		if ( '' !== $b_owned && ! hash_equals( $b_owned, $l_owned ) ) {
			$badges[] = 'stock_changed_on_live';
		}
		$groups['live_owned'] = self::result( hash_equals( $s_owned, $l_owned ) ? self::UNCHANGED : self::LIVE_CHANGED, 'keep_live', $choices, $badges );

		// The row: the strongest group class wins.
		$order  = array( self::CONFLICT, self::STAGING_CHANGED, self::LIVE_CHANGED, self::BOTH_SAME, self::UNCHANGED );
		$class  = self::UNCHANGED;
		$badges = array();
		foreach ( $order as $candidate ) {
			foreach ( self::GROUPS as $name ) {
				if ( $candidate === $groups[ $name ]['class'] ) {
					$class = $candidate;
					break 2;
				}
			}
		}
		foreach ( self::GROUPS as $name ) {
			foreach ( $groups[ $name ]['badges'] as $badge ) {
				$badges[] = $badge;
			}
		}

		$actions = array();
		foreach ( self::GROUPS as $name ) {
			$actions[] = $groups[ $name ]['action'];
		}
		if ( self::CONFLICT === $class ) {
			$action = '';
		} elseif ( in_array( 'push', $actions, true ) ) {
			$action = 'push';
		} elseif ( in_array( 'keep_live', $actions, true ) ) {
			$action = 'keep_live';
		} elseif ( in_array( 'record_base', $actions, true ) ) {
			$action = 'record_base';
		} else {
			$action = 'none';
		}

		$out           = self::result( $class, $action, array(), array_values( array_unique( $badges ) ) );
		$out['groups'] = $groups;
		return $out;
	}

	/**
	 * Group hashes as a clean map.
	 *
	 * @param mixed $groups Groups.
	 * @return array<string,string>
	 */
	private static function hashes( $groups ) {
		$out = array();
		foreach ( is_array( $groups ) ? $groups : array() as $name => $hash ) {
			if ( is_string( $name ) && is_string( $hash ) ) {
				$out[ $name ] = $hash;
			}
		}
		return $out;
	}

	/**
	 * One hash from a map, '' when absent.
	 *
	 * @param array  $map  Map.
	 * @param string $name Key.
	 * @return string
	 */
	private static function pick( array $map, $name ) {
		return isset( $map[ $name ] ) ? (string) $map[ $name ] : '';
	}

	/**
	 * A GMT datetime as Unix time, null when empty or zero.
	 *
	 * @param string $gmt GMT datetime.
	 * @return int|null
	 */
	private static function time( $gmt ) {
		$gmt = (string) $gmt;
		if ( '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) ) {
			return null;
		}
		$time = strtotime( $gmt . ' UTC' );
		return false === $time ? null : $time;
	}

	/**
	 * A result.
	 *
	 * @param string   $cls     Class.
	 * @param string   $action  Default action ('' = undecided).
	 * @param string[] $choices Actions the admin can choose.
	 * @param string[] $badges  Badges.
	 * @return array
	 */
	private static function result( $cls, $action, array $choices, array $badges ) {
		return array(
			'class'   => $cls,
			'action'  => $action,
			'choices' => $choices,
			'badges'  => $badges,
			'groups'  => array(),
		);
	}
}
