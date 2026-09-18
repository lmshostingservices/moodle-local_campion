# Changelog

All notable changes to `local_campion` are documented here.

This project follows [Semantic Versioning](https://semver.org/). Each release also carries a
Moodle numeric version (`$plugin->version` in `version.php`), shown in parentheses.

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
