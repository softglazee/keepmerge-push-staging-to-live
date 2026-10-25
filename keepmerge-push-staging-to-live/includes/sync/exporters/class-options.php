<?php
/**
 * Allowlisted options and the widgets unit.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync\Exporters;

use SoftGlaze\SafePush\Sync\Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options on this site's allowlist (blueprint §6.9), each an item named option:<name>, and the
 * widgets unit (sidebars_widgets plus every widget_* option) named unit:widgets. The hard
 * denylist applies before anything is read. Theme mods never carry custom_css_post_id: Additional
 * CSS lives in a custom_css post that v1 does not push.
 */
final class Options extends Exporter {

	const WIDGETS_UUID = 'unit:widgets';

	/**
	 * Exports one option.
	 *
	 * @param int|string $id Option name.
	 * @return array|null
	 */
	public function export( $id ) {
		$name = (string) $id;
		if ( ! Scope::option_allowed( $name ) ) {
			return null;
		}
		$missing = new \stdClass();
		$value   = get_option( $name, $missing );
		if ( $value === $missing ) {
			return null;
		}
		if ( 0 === strpos( $name, 'theme_mods_' ) && is_array( $value ) ) {
			unset( $value['custom_css_post_id'] );
		}

		return $this->item(
			'option',
			$name,
			0,
			'option:' . $name,
			array(
				'name'  => $name,
				'value' => $value,
			),
			array( 'title' => $name )
		);
	}

	/**
	 * Exports the widgets unit, or null when the Widgets group is off.
	 *
	 * @return array|null
	 */
	public function export_widgets() {
		if ( ! Scope::widgets_included() ) {
			return null;
		}

		$widgets = array();
		foreach ( Scope::widget_option_names() as $name ) {
			if ( 'sidebars_widgets' !== $name ) {
				$widgets[ $name ] = get_option( $name, array() );
			}
		}
		ksort( $widgets, SORT_STRING );

		return $this->item(
			'widgets',
			'widgets',
			0,
			self::WIDGETS_UUID,
			array(
				'sidebars_widgets' => get_option( 'sidebars_widgets', array() ),
				'widgets'          => $widgets,
			),
			array( 'title' => __( 'Widgets', 'keepmerge-push-staging-to-live' ) )
		);
	}
}
