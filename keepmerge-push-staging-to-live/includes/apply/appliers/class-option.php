<?php
/**
 * Options and the widgets unit on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use SoftGlaze\SafePush\Apply\Snapshot_Store;
use SoftGlaze\SafePush\Sync\Option_Policy;
use SoftGlaze\SafePush\Sync\Scope;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes an allowlisted option or the widgets unit (blueprint §6.9). Live is authoritative: the
 * option must still be on live's own allowlist and pass Option_Policy when it is written (the
 * settings may have changed since it was staged). ID-valued options arrive with live's IDs.
 * Theme mods keep live's own custom_css_post_id (Additional CSS is not pushed in v1). The widgets
 * unit writes sidebars_widgets and each widget_* option it carries; widget options only live has
 * stay.
 */
final class Option extends Base {

	/**
	 * Options have no ID to allocate.
	 *
	 * @param Item_Context $ctx Context.
	 * @return int
	 */
	public static function allocate( Item_Context $ctx ) {
		unset( $ctx );
		return 0;
	}

	/**
	 * Write pass.
	 *
	 * @param Item_Context $ctx Context.
	 * @return array|WP_Error
	 */
	public static function write( Item_Context $ctx ) {
		if ( 'widgets' === $ctx->kind ) {
			return self::widgets( $ctx );
		}

		$name = $ctx->type;
		if ( '' !== Option_Policy::option_denial_reason( $name ) || ! Scope::option_allowed( $name ) ) {
			return new WP_Error( 'option_not_allowed', 'option_not_allowed' );
		}
		if ( ! array_key_exists( 'value', $ctx->data ) || self::str( $ctx->data, 'name' ) !== $name ) {
			return new WP_Error( 'invalid_value', 'invalid_value' );
		}
		$value = $ctx->data['value'];

		if ( 0 === strpos( $name, 'theme_mods_' ) && is_array( $value ) ) {
			$current = get_option( $name, array() );
			unset( $value['custom_css_post_id'] );
			if ( is_array( $current ) && isset( $current['custom_css_post_id'] ) ) {
				$value['custom_css_post_id'] = $current['custom_css_post_id'];
			}
		}

		$existed = Snapshot_Store::raw_option( $name )['exists'];
		if ( $existed ) {
			update_option( $name, $value );
		} else {
			add_option( $name, $value );
		}

		return array(
			'id'     => 0,
			'action' => $existed ? 'option_set' : 'option_added',
		);
	}

	/**
	 * The widgets unit.
	 *
	 * @param Item_Context $ctx Context.
	 * @return array|WP_Error
	 */
	private static function widgets( Item_Context $ctx ) {
		if ( ! Scope::widgets_included() ) {
			return new WP_Error( 'option_not_allowed', 'option_not_allowed' );
		}
		$widgets = isset( $ctx->data['widgets'] ) && is_array( $ctx->data['widgets'] ) ? $ctx->data['widgets'] : array();
		foreach ( $widgets as $name => $value ) {
			$name = (string) $name;
			if ( 1 !== preg_match( '/\Awidget_[a-z0-9_-]{1,100}\z/', $name ) || '' !== Option_Policy::option_denial_reason( $name ) ) {
				return new WP_Error( 'option_not_allowed', 'option_not_allowed' );
			}
		}
		foreach ( $widgets as $name => $value ) {
			// A widget plugin switched off on live since staging: its store is left alone.
			if ( Scope::widget_registered( (string) $name ) ) {
				update_option( (string) $name, $value );
			}
		}
		if ( isset( $ctx->data['sidebars_widgets'] ) && is_array( $ctx->data['sidebars_widgets'] ) ) {
			update_option( 'sidebars_widgets', $ctx->data['sidebars_widgets'] );
		}
		return array(
			'id'     => 0,
			'action' => 'option_set',
		);
	}
}
