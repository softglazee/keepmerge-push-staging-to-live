<?php
/**
 * Global styles written through core's wp_filter_global_styles_post() hash as they do on staging.
 * Found in a two-site end-to-end push: after the push,
 * the plugin's own comparison reported the pushed global styles as different on the two sites,
 * because core's filter writes the JSON again (another key order, "\/", an empty "settings"
 * section dropped) and the item hash took the stored text as it is. A later plan would then show
 * the item as changed on live.
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-recovery-harness.php';

use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Items;

/**
 * @group apply
 */
class GlobalStylesHashTest extends Keepmerge_Recovery_Harness {

	const LIVE_TEXT = '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"background":"#fbfaf7","text":"#0f3057"}},"settings":{}}';

	const STAGING_TEXT = '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"background":"#f4efe2","text":"#0f3057"},"spacing":{"padding":{"top":"calc(1rem / 2)"}}},"settings":{}}';

	/**
	 * Who finishes the apply.
	 */
	public function finishers() {
		return array(
			'the receiver'                     => array( false ),
			'a live administrator after review' => array( true ),
		);
	}

	/**
	 * Stores a global styles text as the Site Editor does for an administrator (no filter).
	 */
	private function store( $id, $text ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $text ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}

	/**
	 * @dataProvider finishers
	 *
	 * @param bool $review Whether a held item makes a live administrator finish the apply.
	 */
	public function test_pushed_global_styles_hash_on_live_as_on_staging( $review ) {
		$theme = wp_get_theme( 'twentytwentyfive' )->exists() ? 'twentytwentyfive' : 'twentytwentyfour';
		switch_theme( $theme );
		$styles = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		wp_set_object_terms( $styles, $theme, 'wp_theme' );

		// What staging holds, and its item hash as staging computes it.
		$this->store( $styles, self::STAGING_TEXT );
		$staging_hash = Items::export( 'global_styles', $styles, Export_Context::source() )['hash'];
		$this->store( $styles, self::LIVE_TEXT );

		$envelopes = array(
			$this->envelope(
				'global_styles',
				$styles,
				'push',
				static function ( $data ) {
					$data['post_content'] = self::STAGING_TEXT;
					return $data;
				}
			),
		);
		$held = 0;
		if ( $review ) {
			$held        = self::factory()->post->create( array( 'post_type' => 'page' ) );
			$envelopes[] = $this->envelope(
				'post',
				$held,
				'push',
				static function ( $data ) {
					$data['post_content'] = '<p>Hi</p><script>x()</script>';
					return $data;
				}
			);
		}
		$push = $this->stage( $envelopes );
		$this->assertSame( 'staged', $this->rows( $push )[ $envelopes[0]['uuid'] ]['status'], 'the gate does not hold these styles' );
		$answers = $this->apply( $push );
		if ( $review ) {
			$this->assertSame( 'awaiting_review', end( $answers )['state'] );
			$this->assertSame( 200, $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/held', array( 'decisions' => array( $envelopes[1]['uuid'] => 'approve' ) ) )->get_status() );
			for ( $i = 0; $i < 30; $i++ ) {
				$last = $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/continue', array( 'confirm' => self::HOST ) );
				if ( 200 !== $last->get_status() || ! in_array( $last->get_data()['state'], array( 'snapshotting', 'applying' ), true ) ) {
					break;
				}
			}
		}
		$this->assertSame( 'applied', \SoftGlaze\SafePush\Transfer\Push_Session::find( $push )['state'] );

		$stored = get_post_field( 'post_content', $styles, 'raw' );
		$this->assertSame( '#f4efe2', json_decode( $stored, true )['styles']['color']['background'], 'staging\'s styles are on live' );
		$this->assertNotSame( self::STAGING_TEXT, $stored, 'core\'s filter wrote the JSON again' );
		$this->assertSame( $staging_hash, $this->live_hash( 'global_styles', $styles ), 'live hashes as staging does: ' . $stored );

		// A real change still changes the hash.
		$this->store( $styles, str_replace( '#f4efe2', '#f4efe3', self::STAGING_TEXT ) );
		$this->assertNotSame( $staging_hash, Items::export( 'global_styles', $styles, Export_Context::source() )['hash'] );
	}
}
