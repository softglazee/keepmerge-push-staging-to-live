<?php
/**
 * Suggested privacy policy text.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Keepmerge's section to Settings → Privacy → Policy Guide (blueprint §19). The text is
 * static: it states what the plugin stores and where data goes, using the default retention
 * periods; a site that changes them in Keepmerge Settings edits its own policy.
 */
final class Privacy {

	/**
	 * Registers the text with core's policy guide.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$paragraphs = array(
			__( 'This site uses Keepmerge to move content between this site and another WordPress site owned by the same administrator (usually a staging copy and the live site). Transfers happen only between those two sites, only after an administrator paired them, and only when an administrator starts an action. No data is sent to SoftGlaze or to any other third party.', 'keepmerge-push-staging-to-live' ),
			__( 'Keepmerge keeps an audit log of pairing, push and rollback activity. Each entry can include the user ID of the administrator who acted and the IP address of the request, including the address of the paired site. Audit entries are kept for 180 days by default.', 'keepmerge-push-staging-to-live' ),
			__( 'Before a push changes an item, the receiving site stores a snapshot of that item so the push can be rolled back. Snapshots are kept for 30 days by default (and for at least the three most recent pushes). Pushed content and snapshots can contain personal data that is already part of the site\'s content, such as names in posts or pages.', 'keepmerge-push-staging-to-live' ),
			__( 'Keepmerge never reads or transfers user accounts, comments, orders or customer records.', 'keepmerge-push-staging-to-live' ),
		);

		$content = '';
		foreach ( $paragraphs as $paragraph ) {
			$content .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		wp_add_privacy_policy_content(
			__( 'Keepmerge – Push Staging to Live', 'keepmerge-push-staging-to-live' ),
			wp_kses_post( $content )
		);
	}
}
