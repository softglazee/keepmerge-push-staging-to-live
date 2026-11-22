=== Keepmerge – Push Staging to Live ===
Contributors: softglaze
Tags: staging, content sync, deployment, merge, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push staging to live: content, menus, templates and media, with a diff, conflict checks and rollback. WooCommerce orders and customers are excluded.

== Description ==

Keepmerge pushes staging to live for content and data: posts, pages, menus, templates, allowlisted settings and the media they use, after a dry-run diff you review. It merges instead of overwriting, so WooCommerce orders, customers and other records created on the live site meanwhile stay as they are. Theme and plugin code is reported, never pushed.

A typical case: a shop rebuilds its product pages and menus on a staging copy over a few weeks, while the live shop keeps taking orders and an editor corrects a few pages on live. Keepmerge sends the rebuilt pages and menus, keeps the corrections made on live, shows you every page that changed on both sites, and does not touch the orders.

= What Keepmerge moves =

* Posts, pages and custom post types, including WooCommerce products and variations.
* Categories, tags, product categories, attributes and other public taxonomies.
* Classic menus and their locations.
* Block theme templates, template parts, global styles, patterns and navigation menus stored in the database.
* A list of settings you control (site title, front page, theme customizations, widgets, and any settings you add).
* The images and files those items use, and only those.

= What it never moves =

* Plugin and theme code. Keepmerge lists the differences between the two sites so you can install or update code on live yourself.
* Orders, refunds, subscriptions, coupons, customers and other WooCommerce order and session data.
* Users, roles, comments and reviews.
* Protected settings such as the site address, admin email, active plugins, active theme, user roles and permalinks.
* Data that other plugins keep in their own database tables.

= How a push works =

1. Install Keepmerge on both sites. On live, open the receive window and create a pairing key. Paste the key on staging, then approve the connection on live.
2. Staging compares every item with live and with the version both sites last agreed on.
3. You review a dry-run diff: what will be created, updated, kept as it is on live, and which items changed on both sites. Nothing is written to live yet.
4. You decide each conflict, type your live domain to confirm, and push. Files go first, checked by checksum. Live then saves a snapshot of every item it is about to change and applies the changes.
5. If an item contains code that WordPress's HTML filter would remove (for example a script in a Custom HTML block or in page-builder data), live holds it until an administrator reviews it on live.
6. If something looks wrong, roll the push back from live with one click.

= How changes on live are kept =

For example: you rewrite the About page on staging, and meanwhile someone corrects the opening hours on the Contact page on live. The plan pushes the new About page and leaves the Contact page as it is on live. If both sites changed the same page, the plan lists it as a conflict and you choose which version stays.

* Keepmerge compares stored data, not a log of clicks, so it also sees changes made with WP-CLI, imports, the REST API or a page builder.
* An item changed only on live is kept. An item changed only on staging is pushed. An item changed on both is shown as a conflict for you to decide.
* Items are matched by an ID that Keepmerge stores on both sites, not by title or slug, and references between items (featured images, galleries, menu links, synced patterns) are remapped to live's IDs.
* Just before writing each item, live checks it again. If someone edited it on live after the dry run, it is skipped and listed.

= WooCommerce =

* Products and variations are written through WooCommerce's own product functions, which update only the fields that changed.
* Stock levels and sales counts belong to live and are not pushed unless you choose to for a specific product and live's stock has not changed.
* Price changes made on live are detected and kept unless you choose otherwise.
* Order storage is never read or written, so it works the same with High-Performance Order Storage on or off.
* WooCommerce® and its associated designs are trademarks of Automattic Inc. Keepmerge is an independent plugin and is not affiliated with or endorsed by WooCommerce.

= Requirements =

* Keepmerge 1.0 on both sites.
* HTTPS on the live site, on the standard port 443, at a public address.
* WordPress Application Passwords available on live, including for the receiver user Keepmerge creates (a core feature; some security plugins and hosts switch it off).
* The PHP cURL extension on the staging site (used to keep each connection on the address that was checked).
* WordPress 6.5 or later and PHP 7.4 or later on both sites. The two sites may run different plugin, theme and WordPress versions.

= Limitations =

* Page builders: content and URLs move as data; numeric IDs inside some builders' data cannot always be remapped and are listed in the diff.
* Fonts installed through the Font Library are reported when missing on live but not transferred.
* Multisite networks are not supported in 1.0.
* A live site on a private network address (an intranet) cannot be paired.
* The optional IP pin uses the address that connects to live, so it does not work when live sits behind a reverse proxy that does not pass on the client address.
* On older WordPress versions such as 6.5, WordPress's filter for category and tag descriptions removes rel and target from links, so a description with such links is held for review on live.
* Snapshots cover only what a push changed. Keep your host's backups.

= WP-CLI =

`wp keepmerge` with the subcommands status, receive, pair-key, connect, pair-approve, revoke, plan, push, pushes, rollback and audit. The commands that connect, approve, revoke, push or roll back ask for confirmation unless you add --yes.

= For developers =

Filters can add exclusions or rules and lower limits; none can remove a built-in exclusion or raise a limit.

* `softglaze_sp_option_denylist` and `softglaze_sp_excluded_meta_keys`: option names and meta keys that are never pushed.
* `softglaze_sp_protected_upload_dirs`: upload folders (first path segment) a push never writes into.
* `softglaze_sp_markup_gate_patterns`: literal text that holds a meta, option or widget value for review on live.
* `softglaze_sp_reference_rules`: block attributes, meta keys and options that hold IDs to remap.
* `softglaze_sp_limits`: lower size caps for the media of a push.
* `softglaze_sp_free_space`: report less free space on the uploads volume.
* `softglaze_sp_time_budget`: a shorter time budget for one request step, for hosts whose proxy ends requests early.
* `softglaze_sp_apply_step_items` and `softglaze_sp_rollback_step_items`: fewer items per apply or rollback step.

= Source code and build =

The readable JavaScript source is in the `src/` folder. Build it with `npm install` and `npm run build` (@wordpress/scripts).

= Credits =

Icons: Lucide (ISC licence), bundled locally.

== Installation ==

1. Install and activate Keepmerge on your staging site and on your live site.
2. On live, go to Tools > Staging Push > Receive and click "Open receive window" (it closes itself after 1 hour by default). Then click "Create pairing key" and copy the key. It works once, for 30 minutes.
3. On staging, go to Tools > Staging Push > Send, paste the key, click "Check key", check the site name and address shown, and confirm.
4. Back on live, check the address the staging site connected from, shown on the Receive tab, and click "Approve".
5. On staging, run the baseline. If you know the date staging was copied from live, enter it.
6. Create the plan, review the diff, decide any conflicts, and push.

== External services ==

Keepmerge works site to site only. It connects only to another WordPress site that you pair with it yourself, and it does not use any SoftGlaze server or third-party service.

* Service: your own other WordPress site with Keepmerge installed (usually your live site).
* When: only after you pair the two sites, and only when an administrator checks the connection, compares the sites, views a code report, or runs a push. A default installation sends nothing. The receiving site never sends requests to other servers.
* Endpoints called on the paired site: `/wp-json/keepmerge/v1/remote/status`, `/remote/pair/confirm`, `/remote/pair/finalize`, `/remote/baseline`, `/remote/manifest`, `/remote/items/fetch`, `/remote/code-manifest`, and `/remote/push/...` (session, files, chunks, items, apply, abort).
* What is sent: the content items, settings values and media files selected for the push; content hashes and IDs used for comparison; your site address and the WordPress, PHP and Keepmerge versions. Only if an administrator of the receiving site switches on "Allow code report requests" (off by default) does it return its plugin and theme names, versions and file checksums (never file contents) when you open the Code tab. WordPress adds your site address to the user agent of these requests, and the receiving site sees your server's IP address.
* Terms and privacy: both sites are yours; no third-party terms or privacy policy apply. The plugin's suggested privacy policy text describes the audit log it keeps.

Links shown in the plugin's screens but never requested by the plugin: none. The screens link only to the two paired sites' own admin pages. Addresses that appear only in file headers and code comments (the author's site, the GPL licence, the support-forum address in the translation template, and example.com samples) are never requested either.

== Frequently Asked Questions ==

= What is the security model (threat model)? =

Keepmerge connects two of your own sites, so it was designed around what could go wrong.

* The live site accepts pushes only while you open its receive window. It closes itself after 1 hour by default and after 24 hours at most. While it is closed, the plugin adds no remote REST routes at all.
* Every request is HTTPS and must pass two checks: a WordPress Application Password belonging to a dedicated receiver user that has one capability and cannot log in, and an HMAC-SHA256 signature over the request, a timestamp and a one-time nonce. A replayed or altered request is refused.
* The pairing key you copy works once, for 30 minutes, and a new connection does nothing until you approve it on live. The long-term credentials are created during pairing and never shown. Stored secrets are encrypted with a key taken from a SOFTGLAZE_SP_KEY constant you add to wp-config.php or, without it, from your WordPress security keys. The Overview tab warns if those keys are not set in wp-config.php.
* The live site never makes outbound requests. The staging site connects only to the public address it checked for your live site. Files are checked for path, type and checksum before they reach your uploads folder.
* It cannot change plugins, themes, users, roles, orders or protected settings. Content is saved through WordPress's own HTML filter. Anything that filter would remove, and script-like code in page-builder data or settings, waits until an administrator approves it on live.
* Live shares its plugin and theme list with the staging site only if you switch on the code report there.
* Every pairing, failed attempt and push is logged, and each push can be rolled back.
* What it cannot protect against: if someone takes over your staging site, they can change live content within these limits. Protect staging like live, and revoke the pairing if in doubt.

= Does it push plugins or themes? =

No. Keepmerge reports code differences only: which plugins and themes are missing, extra, at a different version, or have changed files on live. It never copies, installs, updates, activates or switches plugins or themes. Install or update code on live yourself, then push the content that depends on it.

= What happens to orders placed during a push? =

Keepmerge does not read or write orders, customers or other order data, and product saves change only the fields being pushed. An order placed while a push runs keeps its items, totals and status, and its stock change stays. This is part of the release tests, with High-Performance Order Storage on and off.

= What if I edit something on live after the dry run? =

Live checks each item again just before writing it. If it changed after the dry run, that item is skipped and listed so you can decide later.

= Can I undo a push? =

Yes, from live: Tools > Staging Push > Pushes > Roll back. You see a preview first. Items edited on live after the push are listed and left alone unless you tick them. Snapshots are kept for 30 days and for at least the last three pushes.

= Is this a backup plugin? =

No. Snapshots contain only the items a push changed. Keep regular backups from your host or a backup plugin.

= Does it work with my host's staging site? =

It works with any staging copy, whichever host or plugin made it, as long as both sites run Keepmerge and live uses HTTPS.

= Do both sites need the same plugins and themes? =

No. Templates for a theme that is not installed on live, and blocks that live does not have, are flagged before you push. If you switch on "Allow code report requests" on live, the Code tab on staging also lists plugin and theme differences.

= Why did my push stop with "waiting for review on live"? =

Some items contain code that WordPress's HTML filter would remove when it is saved from another site, for example a script in a Custom HTML block, custom CSS in global styles, or script in page-builder data. Live holds those items. Open Tools > Staging Push > Pushes on live, review them, and approve, filter or skip each one; then continue the push on live. Approving as is needs an administrator who may post unfiltered HTML.

= Where is the encryption key for stored credentials? =

Keepmerge derives it from a SOFTGLAZE_SP_KEY constant if you add one to wp-config.php (at least 32 random characters), and otherwise from your WordPress security keys. The Overview tab tells you which one is used and warns if your security keys are not set in wp-config.php. If the key changes, pair the sites again.

= Does it work with page builders? =

Builder content moves as data and its URLs are rewritten. Numeric IDs inside some builders' data cannot always be remapped; the diff lists those items so you can check them.

= Why does it need HTTPS and Application Passwords? =

Every request between your sites is encrypted and checked twice: with a WordPress Application Password for a dedicated receiver user, and with a signature so that a replayed or altered request is refused. Without HTTPS and Application Passwords, Keepmerge refuses to pair.

= My security plugin or host blocks Application Passwords or the Authorization header. =

The connection check on staging tells you which. Allow Application Passwords for the Keepmerge receiver user, or ask your host to pass the Authorization header to PHP. Keepmerge does not fall back to a weaker method.

= My live site is behind a reverse proxy or CDN. What should I know? =

Keepmerge takes the client address only from the connection itself and never trusts forwarded-for headers, because anyone can send them. If your proxy does not pass the real client address on to PHP, every request seems to come from the proxy: the approval card, the audit log and the failed-attempt limit (10 failures in 10 minutes block an address for 15 minutes) all see the proxy's address, so failed attempts by others can briefly block your staging site too, and the optional IP pin cannot be used. Configure the server to restore the client address from the proxy (for example with the web server's real-IP module) before pairing.

= What happens when I deactivate or uninstall it? =

Deactivating it on live revokes the pairing credentials, so you pair again after reactivating. Uninstalling always removes the receiver user and all credentials. Your history, snapshots and settings are kept unless you switch on "Remove all Keepmerge data on uninstall".

= Can I delete the plugin folder by hand? =

Deactivate Keepmerge first, then delete it from the Plugins screen. Deactivating revokes the receiver's Application Passwords and the pairing secrets, and deleting from the Plugins screen runs the uninstall, which also removes the receiver user. If you remove the folder by hand while the plugin is active, none of that runs: delete the Keepmerge receiver user under Users yourself.

= What data leaves my site? =

Only what is listed under External services, only to the site you paired, and only when an administrator acts.

= Does it support multisite? =

Not in 1.0. Network activation is refused.

== Screenshots ==

1. The dry-run diff: what will be created, updated, kept and which items conflict.
2. A conflict: the same page changed on staging and on live, with a text comparison.
3. The Code tab: plugin and theme differences are reported, never pushed.
4. Pairing on live: the receive window, the one-time pairing key and the approval of the new connection.
5. A push in progress with per-step progress.
6. Rollback preview on live before restoring a push.
7. The audit log.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
