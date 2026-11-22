# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |

## Reporting a vulnerability

Please do not report security problems in public issues.

Report them privately instead, in either of these ways:

- GitHub's private vulnerability reporting: the **Security** tab of this repository, then **Report a vulnerability**.
- Email: azhar@softglaze.com

Include the plugin version, the WordPress and PHP versions, whether the affected site is the sending (staging) or the receiving (live) side, the steps or the request that reproduce the problem, what an attacker needs beforehand (for example an administrator account, a valid pairing, control of the staging site, or nothing), and what they could do with it. Please test only against sites you own.

You will get a reply by email. When a fix is released, the changelog can credit you if you wish.

## Scope

In scope: the plugin code in this repository, in particular

- the remote REST routes under `/wp-json/keepmerge/v1/remote/` and their permission chain (receive window, HTTPS, receiver user, Application Password, HMAC signature, timestamp, nonce, rate limits, pairing state);
- the admin REST routes under `/wp-json/keepmerge/v1/admin/` (capability and nonce checks, typed-domain confirmations);
- pairing, approval, revocation, clone detection and the encryption of stored secrets;
- the staging site's outbound checks (address checks and pinning of the live site's address);
- received files and items (path, type and checksum checks, typed JSON, the review of held markup), snapshots and rollback;
- the WP-CLI commands and the uninstall routine.

Out of scope:

- The documented residual risk: someone who fully controls the staging site can change live content within the limits the plugin enforces (no code, users, roles, orders or protected settings). A way around those limits is in scope.
- Attacks that need full control of the live server, including reading `wp-config.php`.
- Vulnerabilities in WordPress core, WooCommerce or other plugins; report those to their maintainers.
- Missing hardening that the plugin does not claim, reports from automated scanners without a working attack, and social engineering.

## Security design in short

- **No remote routes by default.** The receiving site registers its remote routes only while an administrator keeps the receive window open: 1 hour by default, 24 hours at most. A fresh install exposes none, and every route has a real permission check.
- **One locked-down user per pairing.** Pairing creates a receiver user on live, because WordPress Application Passwords belong to a user. Its role has one custom capability and no `read`; interactive login and XML-RPC are refused, and a `rest_pre_dispatch` filter limits it to Keepmerge's remote routes. It is deleted on revoke and always on uninstall.
- **Two credentials on every request.** The receiver's Application Password and an HMAC-SHA256 signature over the method, route, query, pairing, site ID, timestamp (within 300 seconds), a one-time nonce (a replay is refused) and the body hash. Responses are signed too. Signatures are compared with `hash_equals()`.
- **Pairing needs the live administrator.** The pairing key holds bootstrap credentials valid for 30 minutes and destroyed when pairing finishes; a new pairing does nothing until an administrator approves it on live. Long-term credentials are never displayed.
- **Secrets encrypted at rest** with libsodium (`sodium_crypto_secretbox`), with a key derived from `SOFTGLAZE_SP_KEY` if it is defined, otherwise from the WordPress security keys. Secrets never appear in logs or error messages.
- **Live makes no outbound requests.** Staging checks every resolved address of the live host against private, loopback, link-local, shared and reserved ranges, refuses its own host and ports other than 443, pins the checked address for the connection, and uses the WordPress HTTP API with certificate verification and no redirects.
- **Typed data only.** Received values are JSON, never unserialized. Received files pass path, extension, file-type and `realpath()` checks and are written only inside the uploads folder.
- **No filter bypass.** Writes driven by staging run as the receiver user, so WordPress's HTML filters apply. Anything those filters would change, and script-like markup in metadata or settings, waits until a live administrator reviews it on live. The plugin never removes core's filters.
- **Out of reach by construction.** A push never reads or writes orders, customers or comments, never creates or changes users (the receiver user comes only from the live administrator's own pairing action), and never writes plugin or theme code. Code is only reported as names, versions and checksums, and only if live's administrator allows it.
- **Accountability.** Rate limits, a per-address block after repeated failed authentications, an audit log on each site, per-item snapshots and rollback from live.
