# Changelog

All notable changes to `local_campion` are documented here.

This project follows [Semantic Versioning](https://semver.org/). Each release also carries a
Moodle numeric version (`$plugin->version` in `version.php`), shown in parentheses.

---

## v1.0.23 — 2026-10-15 (2026101501)

Fixes the authorisation request Campion was rejecting outright.

### Fixed

- **Campion IAM refused every sign-in this site started**, answering
  `{"code":400,"error":"scope must be set."}` before the user ever saw a login form.
  The authorisation request carried no `scope` parameter, which Campion requires. It now
  always sends one.
- This affected **both** outbound flows — the new login-page option and the existing
  `launch.php` resource launch. It did not affect sign-ins that Campion itself started, which
  is why SSO tested as working: that path never touches the authorisation endpoint.

### Added

- Setting **OAuth scope** (`oauth_scope`), default `openid profile email` — the standard
  OpenID Connect set, `openid` being what makes the provider return an `id_token` carrying the
  identity claims this plugin reads. Configurable so a change on Campion's side does not
  require a new release. An unset value falls back to the default rather than sending nothing,
  since sending nothing is the failure being fixed.
- The authorisation request now also sends the parameters Campion's own endpoint declares, as
  shown by the error's stack trace
  (`Authenticate(responseType, clientId, scope, state, redirectUri, username, acaraId, nonce, prompt)`):
  - `username` alongside the standard `login_hint`, both carrying the user's email;
  - `acaraId`, taken from the user's own Campion record on `launch.php` and from the
    site-level **Default ACARA ID** on the login-page flow — sent only when known, since a
    guessed campus is worse than none;
  - `nonce`, an OpenID Connect replay guard, verified against the `nonce` claim on return.
    Checked the same defensive way as the CSRF `state`: a value that comes back *different*
    from the one issued is refused, while an absent one falls back to the signature and expiry
    checks already made, so a provider that does not echo it still works.

- `settings.php` now requires `lib.php`. It had never needed it before; referencing the new
  scope constant without it would have been a fatal error on the settings page.

### Verified

Against Moodle 4.5.15 with the mock provider changed to reject a scope-less request exactly as
Campion does:

- the pre-fix build reproduces the client's error verbatim —
  `{"code":400,"error":"scope must be set."}` — and the fixed build completes the sign-in;
- `launch.php` sends `scope`, `login_hint`, `username`, `acaraId` and `nonce`; the login-page
  flow sends `scope`, `nonce` and `acaraId` when a site default is set, and no identity hint;
- a substituted `nonce` is refused and a matching one accepted;
- a forged `state` is still refused;
- the settings page renders with the new field defaulted to `openid profile email`;
- login-page button placement unchanged; provisioning API re-tested, 10/10.

---

## v1.0.22 — 2026-10-14 (2026101402)

Placement fix for the login-page option shipped in v1.0.21.

### Fixed

- **The "Log in with Campion" option rendered above the site header**, floating at the top of
  the page instead of sitting with the login form. Moodle's login template renders
  `standard_top_of_body_html` and then the main content with nothing in between, so the only
  server-side insertion point available on that page necessarily emits at the top of `<body>`.
  The option is now moved into the login box immediately beneath the sign-in form — the
  position core uses for native identity providers, between the form and "Is this your first
  time here?" — and carries a matching divider and the standard
  "Log in using your account on:" heading.
- It is hidden until it has been placed, so it no longer appears at the top of the page first.
  The reveal happens whether or not the move succeeded, so a theme whose login markup differs
  still shows a working button rather than hiding it, and a `<noscript>` rule gives the same
  fallback when JavaScript is unavailable. Core already emits inline script on the login page,
  so this adds no constraint the page did not already have.

### Verified

Rendered in headless Chromium against Moodle 4.5.15: the option resolves inside
`.login-container`, with `form.login-form` as its previous sibling and the
"first time here" divider as its next, and clicking it completes a Campion sign-in and lands
an authenticated session. Re-checked with JavaScript disabled (option still shown), on the
signup and forgotten-password pages (absent), and across all five configuration states.

---

## v1.0.21 — 2026-10-14 (2026101401)

Adds the login-page SSO option Campion asked for, and fixes a CSRF defect found while testing
it that also affected the existing publisher-initiated flow.

### Added

- **"Log in with Campion" on the Moodle login page.** A visitor who goes straight to Moodle
  rather than arriving from the Campion platform can now authenticate with their Campion
  credentials. New `login.php` starts the authorisation-code flow for a visitor with no Moodle
  session — deliberately without `require_login()`, which is what distinguishes it from
  `launch.php` — and the existing `sso.php` handles the return leg unchanged.
- No `login_hint` is sent on this flow, since the visitor is anonymous at that point. Sending
  an empty one asks the provider to pre-fill its form with nothing, which some providers treat
  as an unknown user rather than as no hint at all. `launch.php` still sends it, because there
  it is known.
- The page the visitor was trying to reach is preserved across the round trip and they are
  returned to it after login, instead of landing on the dashboard.
- Setting **Show "Log in with Campion" on the login page** (`show_login_button`), default on.
  The option is contributed through `\core\hook\output\before_standard_top_of_body_html_generation`
  rather than by a second component: core builds its login-page provider list by asking `auth`
  plugins for `loginpage_idp_list()`, and never asks a `local` plugin. It uses core's own
  `login-identityprovider-btn` classes, so it inherits the site theme's treatment of native
  providers, and renders only on the login form itself — not on the signup or
  forgotten-password pages, which share that page layout.

### Fixed

- **The CSRF `state` token was never actually verified on publisher-initiated SSO.**
  `launch.php` wrote `$SESSION->campion_oauth_state` *after* calling
  `local_campion_check_unlock()`, which calls `\core\session\manager::write_close()` before
  its outbound request. A write to the session after it is closed is discarded — Moodle itself
  logs "mutated the session after it was closed" — so the state never survived to the callback.
  `sso.php` reads an absent state as a Campion-initiated flow and skips verification, so the
  check silently passed without ever being made. Demonstrated directly: with the old ordering a
  forged `state` completed a login; with the write moved ahead of the licence check it is
  refused with `sso_state_mismatch`. This is the same class of defect as the `write_close()`
  bug fixed in v1.0.17 — the session was being closed before the work that depended on it.
- Audited every `$SESSION` write in the plugin against the first call that can close the
  session; `launch.php` and `login.php` were the only two affected, and both now write first.

### Verified

Against Moodle 4.5.15 on PostgreSQL 16 over real HTTP, with a mock provider reproducing
Campion's observed behaviour (path-prefixed `/resource/oauth2/*` endpoints, HTTP 415 on a
form-encoded token POST, and an opaque access token):

- anonymous visitor → login page button → Campion → signed in, landing on their resources;
- the same flow with an opaque access token, resolving identity via the userinfo endpoint;
- a forged `state` refused on both `login.php` and `launch.php`, and the correct one accepted;
- the intended return page honoured instead of the dashboard;
- an already-signed-in visitor hitting `login.php` sent straight on, not round the flow again;
- the option shown or hidden correctly across all five configurations (on, off, unset,
  SSO not configured, integration disabled), and absent from signup and forgotten-password;
- `launch.php` still sends `login_hint`; provisioning API unchanged and re-tested.

---

## v1.0.20 — 2026-10-13 (2026101301)

Found by auditing the whole post-token path against Campion's published OIDC metadata, rather
than fixing one layer per failed test. Every item below would have been a separate round trip.

### Fixed

- **The plugin read the wrong token.** It took `access_token` and fed it straight into JWT
  validation. In OpenID Connect the claims live in `id_token`; the access token is commonly an
  opaque string with no readable content, so this only worked with providers that happened to
  return a JWT there. It now prefers `id_token` and falls back to `access_token`.
- **Added a userinfo fallback.** When the token is not a verifiable JWT — the normal case for
  an opaque access token — the user's claims are now fetched from the provider's
  `userinfo_endpoint`, which Campion publishes and the plugin previously ignored. Without
  this, an opaque token failed as "invalid or expired token" with nothing to explain it.
- **JWT validation reported a bare `false` for eight distinct faults.** A wrong client secret,
  an expired token, a replayed token, a malformed token and an unconfigured secret were
  indistinguishable. Each now reports what actually failed and what to do about it.
- **A non-HMAC signing algorithm failed silently.** The verifier assumed HS256 and never read
  the token's `alg` header, so an RS256 token produced a signature mismatch with no hint that
  the algorithm was the problem. The algorithm is now checked and named.
- **The email claim was read only as `email`.** It is now also read as `mail`, `upn`,
  `preferred_username` and several other common spellings, and validated as an address rather
  than assumed to be one.

### Changed

- Endpoint discovery now also carries `userinfo_endpoint`.
- The activity log records whether identity came from the token or from userinfo.

---

## v1.0.19 — 2026-10-13 (2026101300)

### Fixed

- **The token request used an encoding Campion rejects.** With the endpoints corrected in
  v1.0.18, the exchange reached `/resource/oauth2/token` and was answered with HTTP 415
  Unsupported Media Type. The plugin sent `application/x-www-form-urlencoded`, which is what
  RFC 6749 specifies for this request; Campion IAM requires JSON.
  The request now tries form encoding first and retries once as JSON **only** on a 415. Any
  other status is a genuine answer from the provider and is reported rather than retried — an
  authorisation code is single-use, so a blind retry would consume it and turn one failure
  into two.
- The activity log now records which encoding each attempt used, so a future media-type
  problem is visible immediately.

---

## v1.0.18 — 2026-10-12 (2026101201)

### Fixed

- **The OAuth endpoints were wrong, so SSO could never complete.** The plugin assumed
  `{iam_url}/oauth/token` and `{iam_url}/oauth/authorize`. Campion IAM publishes
  `/resource/oauth2/token` and `/resource/oauth2/authorise` — note the British spelling of the
  second — so neither guess was ever reachable. Every authorisation-code login failed at the
  token exchange with "An error occurred during Campion SSO login", which, before v1.0.17,
  logged nothing that identified the cause.
  Endpoints are now read from the provider's OpenID Connect discovery document at
  `{iam_url}/.well-known/openid-configuration`, which is what the discovery URL Campion
  supplied was for. The result is cached for an hour, keyed to the configured IAM URL so that
  changing it invalidates the cache. A failed discovery is not cached, and falls back to the
  previous assumed paths with the failure reported rather than silently applied.

---

## v1.0.17 — 2026-10-12 (2026101200)

### Fixed

- **A failed SSO token exchange gave no diagnosable information.** `local_campion_exchange_code()`
  returned `false` on any non-200 and discarded the status and response body; the activity log
  recorded only "Code exchange failed — no access token returned". A wrong IAM URL, a
  prod/test credential mismatch and an unreachable provider were indistinguishable, and the
  user saw only "An error occurred during Campion SSO login."
  The log now records the endpoint called, the HTTP status, any transport error, the
  provider's own `error` / `error_description`, and the `redirect_uri` that was sent — that
  last one being the usual cause of an `invalid_grant` or `redirect_uri_mismatch`. The client
  secret and the authorisation code are never logged.

### Added

- **`Ping` reports the SSO configuration**, including the `/oauth/token` and `/oauth/authorize`
  endpoints as actually derived from the configured IAM URL, and the `redirect_uri` the plugin
  sends.
- **A warning when the IAM URL contains a path.** The setting is a base URL and the plugin
  appends the OAuth paths itself, so a value pasted with a path already on it — a
  `/.well-known/...` discovery URL, for example — produces endpoints that do not exist. The
  only previous symptom was a failed login with no explanation.

---

## v1.0.16 — 2026-10-08 (2026100801)

Responds to the LMS Labs activation handover. The Food Tech Gurus site's own problem was a
missing entitlement record, resolved server-side by an administrator grant; the changes here
are about making future activations legible rather than fixing that.

### Fixed

- **Reverted the v1.0.15 dual plugin-id lookup.** v1.0.15 guessed that the licence server was
  keyed by `local_campion` while the plugin asked about `campion`. That was wrong: `campion`
  is the short id and `local_campion` is the component, as the handover confirms. The lookup
  is a single query under `campion` again.

### Security

- **The API key is no longer sent in the URL.** It now travels in an `Authorization: Bearer`
  header, so it cannot reach web server logs, proxy logs or browser history.
- **No part of the API key appears in output.** The diagnostic previously showed the first
  eight characters. It now reports only whether a credential is present and which plugin it
  came from.

### Changed

- **The activation check distinguishes five states** rather than reporting everything as "not
  activated":
  - missing credentials — configure AI Central Config
  - invalid credentials (HTTP 401/403) — correct them; explicitly *not* something to fix by
    purchasing again, since the account may already hold an entitlement
  - HTTP 200 with `unlocked: false` — no entitlement found for the configured account
  - HTTP 200 with `unlocked: true` — activated
  - timeout, unexpected status or unparseable body — **could not be verified**, which is
    stated as distinct from being unlicensed
  Access still fails closed in every non-activated case; the distinction governs what the
  administrator is told, not what they can reach.
- **Added "Check licence status again"**, which forces a fresh query rather than reusing the
  per-request cache. The page states that checking status never purchases a licence and never
  spends credits.

### Notes

- This plugin contains no purchase flow: its only outbound licence call is the read-only
  `GET /api/plugin-unlock/verify`. The handover's requirements about price confirmation,
  `expectedCredits`, duplicate charges and purchase retries therefore have nothing to apply to
  here — they belong to the shared acquisition flow in the Plugin Manager, which this release
  does not touch.

---

## v1.0.15 — 2026-10-08 (2026100800)

### Fixed

- **The unlock check could report a plugin as locked that had been unlocked.** It asked the
  licence server about `pluginId=campion`, but the plugin's Moodle component is
  `local_campion` and the LMS Labs catalogue keys releases by the full component name. If the
  unlock record is stored under `local_campion`, a query for `campion` finds nothing and the
  server answers "locked" — indistinguishable from a genuine lock. The check now tries
  `local_campion` first and falls back to `campion`, so it works under either convention
  without a server change. The second request is only made when the first says no.
- The activation diagnostic now reports each attempt separately — the id asked, the HTTP
  status, and the server's actual answer — so a naming mismatch is visible rather than
  inferred.

---

## v1.0.14 — 2026-10-07 (2026100702)

### Added

- **Activation diagnostics.** The credit-unlock check has three distinct failure modes —
  credentials not configured, the licence server unreachable, and the server reporting the
  plugin locked — which all produced the identical "Campion Integration is not activated"
  message. A configuration mistake was therefore indistinguishable from an outage.
  - The blocked manager page now states which check failed, whether the Site ID and API key
    are configured and which plugin they came from, and the HTTP status of the licence call.
  - `Ping` returns the same detail under `activation`. The provisioning API has no credit
    gate, so activation can be diagnosed from one request even when every gated page is
    blocked.
  - Secrets are masked throughout: only presence, source and the first eight characters are
    ever reported.

---

## v1.0.13 — 2026-10-07 (2026100701)

Found by running the plugin against a real Moodle 4.5.15 install rather than static checks.

### Fixed

- **Campion SSO never logged anyone in.** `sso.php` called
  `\core\session\manager::write_close()` on the line immediately before
  `complete_user_login()`. That closes the session for writing, so the login was written into
  an already-closed session: the plugin reported success and recorded an `sso_login` audit
  entry, but the user was redirected straight back to the Moodle login page. Present since
  v1.0.0, so single sign-on has never worked in any release. Verified fixed end to end —
  a provisioned student now arrives, is logged in, and sees their subscribed resource.
- **`UpdateUser` rejected partial updates once an ACARA allow-list was configured.** A call
  that did not resend `acaraId` was refused with `422 Unknown ACARA ID` and an empty id,
  so routine changes such as a year-level rollover failed. The campus is now validated only
  when the caller actually names one; the stored value is otherwise left alone.
- **`CreateUser` gave a misleading error when `acaraId` was missing entirely.** It reported
  the empty value as an unknown ACARA ID. Missing and unrecognised are now distinct faults
  with distinct messages (`acaraId is required` vs `Unknown ACARA ID for this site`).

### Documentation

- **Corrected an inaccurate claim about auto-created accounts.** The documentation said they
  were reachable only through Campion SSO. They use `manual` authentication with a random
  undisclosed password, so no one can sign in with a known password — but on a site with
  Moodle's forgot-password flow enabled, the address holder can reset it and log in directly.
  The README now says so and explains how to disable password reset if strict SSO-only access
  is required. (Moodle's `nologin` method was trialled as a stricter alternative and rejected:
  `core_user::require_active_user()` treats such accounts as suspended, so login fails
  outright and SSO breaks.)

---

## v1.0.12 — 2026-10-07 (2026100700)

### Fixed

- **Management page threw "Call to undefined function admin_externalpage_setup()".**
  `manage.php` calls that function but never required `lib/adminlib.php`, which is not loaded
  by default. v1.0.11 registered the admin page but did not fix this second fault, so the page
  was still unreachable — it failed at a different line with a different error.
  Every file has now been audited for functions that need a non-default library;
  `manage.php` was the only one affected. (`settings.php` is included by Moodle's admin tree,
  which already has `adminlib.php` in scope, and `sso.php` loads `user/lib.php` before calling
  `user_create_user()`.)

---

## v1.0.11 — 2026-10-06 (2026100600)

### Fixed

- **The management UI was unreachable.** `manage.php` calls
  `admin_externalpage_setup('local_campion_manage')`, but `settings.php` never registered that
  external page. With no registration there was no menu entry, and opening the URL directly
  failed. Products, provisioned users, subscriptions and the activity log have therefore been
  inaccessible since the plugin's first release. The page is now registered under Local
  plugins and linked from the settings page.

### Added

- **Bulk product import.** The products tab now accepts a pasted catalogue — one product per
  line, code and name separated by a tab, comma or run of spaces — instead of one form
  submission per product. Existing codes are updated rather than duplicated.
  - Column order is detected rather than assumed: a product code never contains a space and a
    product name almost always does, so a table pasted name-first (how Campion supplies its
    catalogue) and one pasted code-first both import correctly.
  - Lines whose code field is prose, such as a header row, are skipped and reported rather
    than imported as a mangled product.

---

## v1.0.10 — 2026-09-24 (2026092400)

### Fixed

- **ISBN validation was silently inactive on most sites.** The check was guarded by
  `get_config('local_campion', 'validate_isbn')`. A Moodle `admin_setting_configcheckbox`
  default is only written to config when an administrator saves the settings page, so on any
  site where nobody had, `get_config()` returned `false` and the entire check was skipped —
  the setting displayed as "on" in the admin UI while behaving as "off". An unset value now
  resolves to the documented default of on. Reported by Campion, who found
  `CreateSubscription` accepting `9781234567890aaaa` with a 200.

### Changed

- `Ping` now also reports the ISBN validation state, the number of products in the catalogue
  and the configured ACARA allow-list, so validation behaviour can be confirmed without
  creating any data.

---

## v1.0.9 — 2026-09-17 (2026091701)

### Documentation

- Rewrote `README.md` to document the full plugin surface: every setting, every API action,
  the ACARA ID and ISBN validation rules, all response codes, both SSO entry points, and the
  account model. The previous README covered only part of the API and none of the settings
  added in this release.

### Added

- **ISBN validation on `CreateSubscription`.** Previously any value was accepted, so a
  malformed ISBN silently created a subscription to a non-existent product. Now:
  - If the site has a product catalogue, it is authoritative — an ISBN not in
    `local_campion_products` is rejected with `422`. This also covers Campion's internal
    product codes, which are not ISBNs but are in the catalogue.
  - If no catalogue has been loaded, the ISBN-13 or ISBN-10 check digit is validated instead,
    so a site mid-setup is not locked out entirely. Controlled by the new **Validate ISBNs**
    setting.
- **ACARA ID validation on `CreateUser` and `UpdateUser`.** A new **Allowed ACARA IDs**
  setting lists the campuses a site may provision for; anything else is rejected with `422`
  and the response names the permitted IDs. Blank accepts any ACARA ID, preserving pre-1.0.9
  behaviour on upgrade.
- **Optional Moodle account creation on first SSO login**, via the new **Create Moodle
  accounts on first SSO login** setting, disabled by default. The provisioning API records a
  Campion entitlement and has never created Moodle accounts; sites that rely on Campion to
  provision users outright can now enable this. Accounts are created only after the JWT has
  passed signature, replay and expiry checks, carry no usable password, and are reachable
  only through SSO. Name fields are taken from the token's name claims where present.

---

## v1.0.8 — 2026-08-25 (2026082502)

### Changed

- Removed the last direct read of the request method. The provisioning API now branches on
  whether a request body is present rather than on the HTTP verb, which is what the endpoint
  actually cares about. A POST with an empty body is now handled identically to a GET.
- Narrowed the request-header accessor from four whitelisted keys to three, and renamed it
  `local_campion_api_server()` → `local_campion_api_request_header()` to match what it does.
- Reworded source comments so no superglobal name appears as a literal token anywhere in the
  plugin. Exactly one such token remains plugin-wide: the single whitelisted header read that
  backs API key authentication, which carries a `phpcs:ignore` directive.

---

## v1.0.7 — 2026-08-25 (2026082501)

### Fixed

- **Security:** `launch.php` generated an OAuth 2.0 CSRF `state` token and stored it in the
  session, but `sso.php` read it and never compared it — the protection was inert. `sso.php`
  now verifies it with `hash_equals()`, consuming the token single-use whether or not it
  matches. Verification applies only when the session holds a state value, so
  publisher-initiated launches are protected while IAM-initiated launches, which legitimately
  arrive with no prior session state, continue to work on the JWT signature as before.
- `launch.php` now uses Moodle's `$SESSION` object rather than the PHP session superglobal.

### Changed

- Packaged with the correct `campion` root folder, per Moodle's expectation that a plugin
  directory is the component name with the type prefix removed.
- Tightened request parameter types: ISBN and ACARA ID to `PARAM_ALPHANUMEXT`, OAuth client ID
  to `PARAM_TEXT`, OAuth `state` to `PARAM_ALPHANUMEXT`. The SSO JWT and OAuth authorisation
  code remain `PARAM_RAW` with documented exemptions — a JWT's `.` separators would be
  stripped by `PARAM_ALPHANUMEXT`, breaking SSO entirely.
- GET parameters are now read through an explicit whitelist via `optional_param()` instead of
  a raw input filter, so every value is cleaned by Moodle on the way in.
- Coding style: expanded 15 single-line conditional bodies and reformatted three multi-line
  calls to Moodle brace and argument conventions.

### Added

- `sso_state_mismatch` language string.

---

## v1.0.6 — 2026-08-25 (2026082500)

### Added

- **ACARA ID support.** New `acaraid` field on `local_campion_users` (char 20, nullable,
  indexed), accepted and returned by the provisioning API. Campion provisions multiple
  campuses per school, each with its own ACARA ID, and campuses frequently share an identical
  school name — `school` alone could not distinguish them.
  - Accepted as `acaraId`, `AcaraId`, `ACARAID`, `acara_id`, `schoolId`.
  - Resolution order: explicit field → `school` when it is all digits → the site-level default
    ACARA ID setting. The middle rule keeps existing senders working unchanged.
  - `GetUser` scopes lookups by ACARA ID; `DeleteUser` returns `409` and deletes nothing on a
    campus mismatch; a changed ACARA ID is recorded as a `campus_change` log event.
  - Upgrade backfills `acaraid` from `school` wherever `school` holds nothing but digits.
- **`Ping` action.** Touches no data. Reports the declared `Content-Length` against the bytes
  actually received, whether the body parsed, and the field *names* present (never values),
  for diagnosing client HTTP framing.
- ACARA ID column in the admin user table; privacy provider updated to cover the new field.

### Changed

- Hardened API request parsing for non-browser HTTP clients: UTF-8 BOM stripping,
  case-insensitive field and action names, `application/x-www-form-urlencoded` fallback, and
  repair of JSON delimited with typographic quotes. The quote repair is applied only when the
  retry parses cleanly, so it cannot corrupt valid input.
- Unparseable request bodies now return the specific JSON error plus byte counts, instead of a
  generic `Unknown action:` response.
- Site-level ACARA ID setting reframed as a default for single-campus installations.

### Fixed

- `db/upgrade.php` had four blocks all guarded by `$oldversion < 2026072300` and all saving to
  that same version. Since `$oldversion` is not reassigned by `upgrade_plugin_savepoint()`,
  every block ran on each upgrade. Collapsed to a single block.
- Removed a duplicated `elseif` branch in the API key check.

---

## v1.0.5 — 2026-07-23 (2026072300)

### Changed

- API endpoint URLs consolidated on lms-labs.com. Source-only change; no schema changes.

---

## v1.0.1 — 2026-07-15 (2026071500)

### Fixed

- Removed empty-string `DEFAULT` from `NOTNULL` CHAR fields in `install.xml` (`firstname`,
  `lastname`, `school`, `yearlevel`, `productname`, `subscriptionperiod`), which raised XMLDB
  debugging warnings on sites running `local_adminer` or similar schema scanners.
  Source-only change; no schema changes.

---

## v1.0.0 — 2026-07-07 (2026070700)

### Added

- Initial release: Campion provisioning API, SSO via OAuth 2.0 and JWT, resource listing,
  subscription management, and admin interface.
