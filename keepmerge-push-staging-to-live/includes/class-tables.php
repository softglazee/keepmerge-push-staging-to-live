<?php
/**
 * The plugin's seven tables: names and schema.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds table names from a fixed allowlist and holds the dbDelta schema (blueprint §14.1).
 *
 * Every query in the plugin gets its table name from table_name(), so no table name is ever
 * assembled from input, and Plugin Check's DirectDB sniff can see where names come from.
 */
final class Tables {

	/**
	 * Short names of the seven tables. Snapshots and file transfers live in push_items (D14).
	 *
	 * @var string[]
	 */
	const NAMES = array( 'pairings', 'nonces', 'throttle', 'audit', 'sync_state', 'pushes', 'push_items' );

	/**
	 * Returns the full name of one of the plugin's tables.
	 *
	 * @param string $name Short name, one of self::NAMES.
	 * @return string Prefixed table name, for example wp_softglaze_sp_audit.
	 * @throws \InvalidArgumentException When the name is not one of the seven tables.
	 */
	public static function table_name( $name ) {
		global $wpdb;

		if ( ! is_string( $name ) || ! in_array( $name, self::NAMES, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown Keepmerge table "%s".', is_string( $name ) ? $name : gettype( $name ) ) ) );
		}

		return $wpdb->prefix . 'softglaze_sp_' . $name;
	}

	/**
	 * Full names of all seven tables, keyed by short name.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		$out = array();
		foreach ( self::NAMES as $name ) {
			$out[ $name ] = self::table_name( $name );
		}
		return $out;
	}

	/**
	 * CREATE TABLE statements in the format dbDelta() parses (two spaces after PRIMARY KEY,
	 * one column per line).
	 *
	 * Column names avoid MySQL reserved words, which dbDelta does not quote: the push cursor
	 * is stored as cursor_json because CURSOR is reserved.
	 *
	 * pairings keeps the key's bootstrap credentials apart from the long-term ones because both
	 * exist between confirm and finalize (blueprint §5.3). local_home_hash binds a row to this
	 * site: a hash, because host clone tools search-replace URLs in every table and would
	 * rewrite a stored URL to match the copy (threat model T21).
	 *
	 * sync_state.base_groups (destination) holds the base hash of each product field group as JSON
	 * (group => hash), so the planner can run the three-way comparison per group (blueprint §6.6)
	 * and "Push stock" can be refused when live's stock changed since the base (§7 rule 4).
	 *
	 * Source rows (role "source") also keep the receiver login on live (remote_login), what live
	 * last reported about itself and the last check (remote_info, JSON without credentials) and
	 * the address the outbound guard checked with its time (resolved_ip, resolved_gmt), which
	 * every request of a run connects to for at most 10 minutes (blueprint §5.10).
	 *
	 * @return string[]
	 */
	public static function schema() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$t       = self::all();

		$sql = array();

		$sql[] = "CREATE TABLE {$t['pairings']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
pairing_uuid char(36) NOT NULL,
role varchar(20) NOT NULL DEFAULT '',
remote_url varchar(255) NOT NULL DEFAULT '',
rest_root varchar(255) NOT NULL DEFAULT '',
local_home_url varchar(255) NOT NULL DEFAULT '',
local_home_hash char(64) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'pending',
receiver_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
app_password_uuid varchar(36) NOT NULL DEFAULT '',
app_password_enc text NULL,
secret_enc text NULL,
bootstrap_password_uuid varchar(36) NOT NULL DEFAULT '',
bootstrap_secret_enc text NULL,
source_site_id varchar(36) NOT NULL DEFAULT '',
source_ip varchar(45) NOT NULL DEFAULT '',
default_author_id bigint(20) unsigned NOT NULL DEFAULT 0,
pinned_ip varchar(45) NOT NULL DEFAULT '',
expires_gmt datetime NULL DEFAULT NULL,
created_gmt datetime NOT NULL,
confirmed_gmt datetime NULL DEFAULT NULL,
approved_gmt datetime NULL DEFAULT NULL,
approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
last_used_gmt datetime NULL DEFAULT NULL,
revoked_gmt datetime NULL DEFAULT NULL,
remote_login varchar(60) NOT NULL DEFAULT '',
remote_info longtext NULL,
resolved_ip varchar(45) NOT NULL DEFAULT '',
resolved_gmt datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY pairing_uuid (pairing_uuid),
KEY role_status (role,status)
) $charset;";

		$sql[] = "CREATE TABLE {$t['nonces']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
pairing_id bigint(20) unsigned NOT NULL,
nonce char(32) NOT NULL,
received_gmt datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY pairing_nonce (pairing_id,nonce),
KEY received_gmt (received_gmt)
) $charset;";

		$sql[] = "CREATE TABLE {$t['throttle']} (
bucket varchar(64) NOT NULL,
hits int(10) unsigned NOT NULL DEFAULT 0,
window_start datetime NOT NULL,
blocked_until datetime NULL DEFAULT NULL,
PRIMARY KEY  (bucket)
) $charset;";

		$sql[] = "CREATE TABLE {$t['audit']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_gmt datetime NOT NULL,
pairing_id bigint(20) unsigned NOT NULL DEFAULT 0,
actor_type varchar(10) NOT NULL DEFAULT 'system',
actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
event varchar(64) NOT NULL,
object_type varchar(32) NOT NULL DEFAULT '',
object_ref varchar(191) NOT NULL DEFAULT '',
ip varchar(45) NOT NULL DEFAULT '',
result varchar(10) NOT NULL DEFAULT 'ok',
details longtext NULL,
PRIMARY KEY  (id),
KEY event_created (event,created_gmt),
KEY created_gmt (created_gmt)
) $charset;";

		$sql[] = "CREATE TABLE {$t['sync_state']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
pairing_id bigint(20) unsigned NOT NULL,
uuid varchar(191) NOT NULL,
object_type varchar(32) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
remote_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
base_hash char(64) NOT NULL DEFAULT '',
base_groups text NULL,
base_gmt datetime NULL DEFAULT NULL,
last_push_id bigint(20) unsigned NOT NULL DEFAULT 0,
match_confidence varchar(16) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
UNIQUE KEY pairing_uuid (pairing_id,uuid),
KEY object (object_type,object_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['pushes']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
push_uuid char(36) NOT NULL,
pairing_id bigint(20) unsigned NOT NULL DEFAULT 0,
side varchar(12) NOT NULL DEFAULT '',
state varchar(32) NOT NULL DEFAULT '',
plan_hash char(64) NOT NULL DEFAULT '',
counts longtext NULL,
cursor_json longtext NULL,
chunk_size int(10) unsigned NOT NULL DEFAULT 0,
started_gmt datetime NOT NULL,
finished_gmt datetime NULL DEFAULT NULL,
initiated_by bigint(20) unsigned NOT NULL DEFAULT 0,
error_code varchar(64) NOT NULL DEFAULT '',
error_detail text NULL,
PRIMARY KEY  (id),
UNIQUE KEY push_uuid (push_uuid),
KEY pairing_state (pairing_id,state)
) $charset;";

		$sql[] = "CREATE TABLE {$t['push_items']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
push_id bigint(20) unsigned NOT NULL,
side varchar(12) NOT NULL DEFAULT '',
seq int(10) unsigned NOT NULL DEFAULT 0,
uuid varchar(191) NOT NULL,
object_type varchar(32) NOT NULL DEFAULT '',
action varchar(20) NOT NULL DEFAULT '',
decision varchar(20) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT '',
review varchar(20) NOT NULL DEFAULT '',
review_by bigint(20) unsigned NOT NULL DEFAULT 0,
payload longtext NULL,
refs longtext NULL,
transfer longtext NULL,
local_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
pre_image longtext NULL,
pre_hash char(64) NOT NULL DEFAULT '',
post_hash char(64) NOT NULL DEFAULT '',
snapshot_file_rel varchar(255) NOT NULL DEFAULT '',
snapshot_gmt datetime NULL DEFAULT NULL,
applied_gmt datetime NULL DEFAULT NULL,
restored_gmt datetime NULL DEFAULT NULL,
error text NULL,
PRIMARY KEY  (id),
UNIQUE KEY push_side_uuid (push_id,side,uuid),
KEY push_status (push_id,status)
) $charset;";

		return $sql;
	}
}
