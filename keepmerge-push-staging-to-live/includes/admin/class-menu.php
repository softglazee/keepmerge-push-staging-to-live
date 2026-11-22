<?php
/**
 * The admin page under Tools.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Staging Push (blueprint §16): one page on both sites, no top-level menu, no notices
 * anywhere else.
 */
final class Menu {

	const SLUG = 'keepmerge';

	const CAPABILITY = 'manage_options';

	/**
	 * The hook suffix WordPress gives the page; Assets loads only there.
	 */
	const HOOK_SUFFIX = 'tools_page_keepmerge';

	/**
	 * Adds the page.
	 *
	 * @return void
	 */
	public static function register() {
		add_management_page(
			__( 'Keepmerge – Push Staging to Live', 'keepmerge-push-staging-to-live' ),
			__( 'Staging Push', 'keepmerge-push-staging-to-live' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The page shell. The heading is printed here so the page has its title without
	 * JavaScript; the React app fills the status line and everything below the header. The
	 * masthead is a div, not a header element: the page sits inside core's main landmark, where a
	 * header would be a nested banner landmark.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap sgsp-wrap">
			<div class="sgsp-masthead">
				<div class="sgsp-masthead__text">
					<span class="sgsp-masthead__rule" aria-hidden="true"></span>
					<h1 class="sgsp-masthead__title"><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<p class="sgsp-masthead__lead"><?php esc_html_e( 'Push content and data from staging to live and keep what changed on live. Theme and plugin code is reported, never pushed.', 'keepmerge-push-staging-to-live' ); ?></p>
				</div>
				<div id="softglaze-sp-status" class="sgsp-masthead__status"></div>
			</div>
			<hr class="wp-header-end">
			<div id="softglaze-sp-root" class="sgsp-root">
				<noscript>
					<p class="sgsp-noscript"><?php esc_html_e( 'This screen needs JavaScript. Turn it on in your browser to use Keepmerge.', 'keepmerge-push-staging-to-live' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
	}
}
