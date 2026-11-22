<?php
/**
 * Review of held items on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Tables;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A live administrator decides each held item on live's own screen, never the remote: approve
 * (needs unfiltered_html, plus edit_css for global styles with custom CSS), filtered (core's HTML
 * filter on content fields only, since it does not reach meta, options or widgets) or skip.
 * Nothing is pre-chosen and every decision is audited.
 */
final class Review {

	const DECISIONS = array( 'approve', 'filtered', 'skip' );

	const MAX_DECISIONS = 500;

	/**
	 * Reason fields that are content fields (core's HTML filter applies to them).
	 */
	const CONTENT_FIELD = '/\A(?:post_title|post_content|post_excerpt|description|groups\.content\.(?:name|description|short_description)|items\.[0-9]+\.(?:title|attr_title|description))\z/';

	/**
	 * Held rows of a session with their reasons, for the review screen (excerpts are plain text).
	 *
	 * @param array $session Session.
	 * @return array[]
	 */
	public static function held_items( array $session ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the session's held rows.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type, action, status, review, review_by, payload, local_object_id FROM %i WHERE push_id = %d AND side = %s AND status IN ('held','applied','skipped','failed','applying') AND (status = 'held' OR review <> '') ORDER BY id ASC LIMIT 1000", Tables::table_name( 'push_items' ), absint( $session['id'] ), Applier::SIDE ), ARRAY_A );

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			$payload = is_array( $payload ) ? $payload : array();
			$gate    = isset( $payload['gate'] ) && is_array( $payload['gate'] ) ? $payload['gate'] : array();
			$reasons = array();
			foreach ( $gate as $reason ) {
				if ( is_array( $reason ) ) {
					$reasons[] = array(
						'field'   => isset( $reason['field'] ) ? (string) $reason['field'] : '',
						'rule'    => isset( $reason['rule'] ) ? (string) $reason['rule'] : '',
						'excerpt' => isset( $reason['excerpt'] ) ? (string) $reason['excerpt'] : '',
					);
				}
			}
			$out[] = array(
				'uuid'       => (string) $row['uuid'],
				'kind'       => (string) $row['object_type'],
				'type'       => isset( $payload['type'] ) ? (string) $payload['type'] : '',
				'title'      => self::title( (string) $row['object_type'], $payload ),
				'action'     => (string) $row['action'],
				'status'     => (string) $row['status'],
				'review'     => (string) $row['review'],
				'review_by'  => (int) $row['review_by'],
				'reasons'    => $reasons,
				'can_filter' => self::filter_possible( $reasons ),
				'needs_css'  => self::needs_edit_css( (string) $row['object_type'], $reasons ),
				'live_id'    => (int) $row['local_object_id'],
			);
		}
		return $out;
	}

	/**
	 * Records decisions for held items. Every decision is checked first; one refused decision
	 * stores none.
	 *
	 * @param array $session   Session.
	 * @param mixed $decisions uuid => decision, as received.
	 * @param int   $user_id   Current user.
	 * @return array|WP_Error Counts per decision and the items as they now stand.
	 */
	public static function decide( array $session, $decisions, $user_id ) {
		if ( 'awaiting_review' !== $session['state'] ) {
			return new WP_Error( 'review_closed', __( 'This push is not waiting for review.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( ! is_array( $decisions ) || empty( $decisions ) || count( $decisions ) > self::MAX_DECISIONS ) {
			return new WP_Error( 'invalid_decisions', __( 'Choose a decision for at least one held item.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}

		$held = array();
		foreach ( self::held_items( $session ) as $item ) {
			if ( 'held' === $item['status'] ) {
				$held[ $item['uuid'] ] = $item;
			}
		}

		$clean = array();
		foreach ( $decisions as $uuid => $decision ) {
			$uuid = is_string( $uuid ) ? strtolower( $uuid ) : '';
			if ( ! isset( $held[ $uuid ] ) || ! is_string( $decision ) || ! in_array( $decision, self::DECISIONS, true ) ) {
				return new WP_Error( 'invalid_decisions', __( 'One of the decisions names an item that is not held in this push.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
			}
			$item = $held[ $uuid ];
			if ( 'approve' === $decision ) {
				$refusal = self::approve_refusal( $item );
				if ( null !== $refusal ) {
					return $refusal;
				}
			}
			if ( 'filtered' === $decision && ! $item['can_filter'] ) {
				return new WP_Error( 'filter_not_possible', __( 'WordPress\'s HTML filter only covers titles, content, excerpts and descriptions. This item is held for markup elsewhere: approve it as is or skip it.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
			}
			$clean[ $uuid ] = $decision;
		}

		global $wpdb;
		$counts = array_fill_keys( self::DECISIONS, 0 );
		foreach ( $clean as $uuid => $decision ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the held row of this session.
			$wpdb->update(
				Tables::table_name( 'push_items' ),
				array(
					'review'    => $decision,
					'review_by' => absint( $user_id ),
				),
				array(
					'push_id' => absint( $session['id'] ),
					'side'    => Applier::SIDE,
					'uuid'    => $uuid,
					'status'  => 'held',
				)
			);
			++$counts[ $decision ];
		}

		Audit_Log::write(
			'held_items_decided',
			array(
				'actor_id'    => absint( $user_id ),
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array_merge( $counts, array( 'undecided' => self::undecided( $session ) ) ),
			)
		);

		return array(
			'decided'   => $counts,
			'undecided' => self::undecided( $session ),
			'items'     => self::held_items( $session ),
		);
	}

	/**
	 * Held rows of a session not decided yet.
	 *
	 * @param array $session Session.
	 * @return int
	 */
	public static function undecided( array $session ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE push_id = %d AND side = %s AND status = 'held' AND review = ''", Tables::table_name( 'push_items' ), absint( $session['id'] ), Applier::SIDE ) );
	}

	/**
	 * Why the current user may not approve an item as is, or null when they may.
	 *
	 * @param array $item Held item (from held_items()).
	 * @return WP_Error|null
	 */
	public static function approve_refusal( array $item ) {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new WP_Error( 'approve_not_allowed', __( 'Approving markup as is needs the "unfiltered_html" capability, which your account does not have on this site. Choose "Apply with WordPress\'s HTML filter" or "Skip".', 'keepmerge-push-staging-to-live' ), array( 'status' => 403 ) );
		}
		if ( ! empty( $item['needs_css'] ) && ! current_user_can( 'edit_css' ) ) {
			return new WP_Error( 'approve_not_allowed', __( 'These global styles carry custom CSS. Approving them as is needs the "edit_css" capability, which your account does not have on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 403 ) );
		}
		return null;
	}

	/**
	 * Whether every reason of an item is a content field.
	 *
	 * @param array[] $reasons Reasons.
	 * @return bool
	 */
	public static function filter_possible( array $reasons ) {
		if ( empty( $reasons ) ) {
			return false;
		}
		foreach ( $reasons as $reason ) {
			if ( 1 !== preg_match( self::CONTENT_FIELD, (string) $reason['field'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether approving an item needs edit_css: global styles with custom CSS.
	 *
	 * @param string  $kind    Kind.
	 * @param array[] $reasons Reasons.
	 * @return bool
	 */
	public static function needs_edit_css( $kind, array $reasons ) {
		if ( 'global_styles' !== $kind ) {
			return false;
		}
		foreach ( $reasons as $reason ) {
			if ( 'styles_css' === (string) $reason['rule'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs the kses core applies for a user without unfiltered_html over an item's content fields
	 * ("Apply with WordPress's HTML filter").
	 *
	 * @param string $kind Kind.
	 * @param array  $data Decoded data.
	 * @return array
	 */
	public static function filter( $kind, array $data ) {
		switch ( $kind ) {
			case 'term':
				self::kses( $data, 'description', 'pre_term_description' );
				break;

			case 'menu':
				self::kses( $data, 'description', 'pre_term_description' );
				if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
					foreach ( $data['items'] as $i => $item ) {
						if ( is_array( $item ) ) {
							self::kses( $item, 'title', 'title_save_pre' );
							self::kses( $item, 'attr_title', 'post' );
							self::kses( $item, 'description', 'post' );
							$data['items'][ $i ] = $item;
						}
					}
				}
				break;

			case 'product':
				if ( isset( $data['groups']['content'] ) && is_array( $data['groups']['content'] ) ) {
					$content = $data['groups']['content'];
					self::kses( $content, 'name', 'title_save_pre' );
					self::kses( $content, 'description', 'post' );
					self::kses( $content, 'short_description', 'post' );
					$data['groups']['content'] = $content;
				}
				break;

			case 'global_styles':
				self::kses( $data, 'post_title', 'title_save_pre' );
				if ( isset( $data['post_content'] ) && is_string( $data['post_content'] ) ) {
					$data['post_content'] = self::filter_styles( $data['post_content'] );
				}
				break;

			default:
				self::kses( $data, 'post_title', 'title_save_pre' );
				self::kses( $data, 'post_content', 'post' );
				self::kses( $data, 'post_excerpt', 'post' );
		}
		return $data;
	}

	/**
	 * Global styles JSON as core stores it for a user without unfiltered_html and edit_css.
	 *
	 * @param string $json Post content.
	 * @return string
	 */
	public static function filter_styles( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return wp_kses( (string) $json, 'post' );
		}
		unset( $decoded['isGlobalStylesUserThemeJSON'] );
		$clean = class_exists( 'WP_Theme_JSON' ) ? \WP_Theme_JSON::remove_insecure_properties( $decoded, 'custom' ) : $decoded;
		// This choice means "as stored for a user without edit_css", so custom CSS goes as well.
		$clean                                = self::without_css( $clean, 0 );
		$clean['isGlobalStylesUserThemeJSON'] = true;
		$text                                 = wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		return wp_kses( is_string( $text ) ? $text : '{}', 'post' );
	}

	/**
	 * Every "css" key under styles removed.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function without_css( $value, $depth ) {
		if ( ! is_array( $value ) || $depth > 64 ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			if ( 'css' === $key && $depth > 0 ) {
				unset( $value[ $key ] );
				continue;
			}
			if ( 0 === $depth && 'styles' !== $key ) {
				continue;
			}
			$value[ $key ] = self::without_css( $item, $depth + 1 );
		}
		return $value;
	}

	/**
	 * One field through kses in a context.
	 *
	 * @param array  $fields  Fields (changed).
	 * @param string $name    Field.
	 * @param string $context kses context.
	 * @return void
	 */
	private static function kses( array &$fields, $name, $context ) {
		if ( isset( $fields[ $name ] ) && is_string( $fields[ $name ] ) ) {
			$fields[ $name ] = wp_kses( $fields[ $name ], $context );
		}
	}

	/**
	 * A title for the review list (shown as text).
	 *
	 * @param string $kind    Kind.
	 * @param array  $payload Payload.
	 * @return string
	 */
	public static function title( $kind, array $payload ) {
		if ( isset( $payload['title'] ) && is_string( $payload['title'] ) ) {
			return $payload['title'];
		}
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		foreach ( array( 'post_title', 'name' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) && '' !== $data[ $field ] ) {
				return wp_strip_all_tags( $data[ $field ] );
			}
		}
		if ( isset( $data['groups']['content']['name'] ) && is_string( $data['groups']['content']['name'] ) ) {
			return wp_strip_all_tags( $data['groups']['content']['name'] );
		}
		if ( isset( $data['post_name'] ) && is_string( $data['post_name'] ) ) {
			return $data['post_name'];
		}
		return isset( $payload['type'] ) ? (string) $payload['type'] : $kind;
	}
}
