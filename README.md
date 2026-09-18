# local_campion — Campion Education Integration

A Moodle local plugin that connects a Moodle site to Campion Education: provisioning of users
and product subscriptions over a REST API, and single sign-on from the Campion platform.

- **Component:** `local_campion`
- **Directory:** `local/campion`
- **Requires:** Moodle 4.0 or later (tested to 5.x)
- **Licence:** GNU GPL v3 or later

See `CHANGELOG.md` for the release history.

---

## Installation

Install to `local/campion` and run the Moodle upgrade. Then configure the plugin at
**Site administration → Plugins → Local plugins → Campion Integration**.

---

## Settings

| Setting | Key | Purpose |
|---|---|---|
| Enable integration | `enabled` | Master switch. When off, the API returns `503` and SSO is disabled. |
| Provisioning API key | `provision_api_key` | Shared secret for the provisioning API, sent as the `X-Campion-API-Key` header. |
| Campion IAM URL | `iam_url` | Base URL of the Campion identity service used for the OAuth 2.0 code exchange. |
| OAuth client ID | `client_id` | Client identifier issued by Campion. |
| OAuth client secret | `client_secret` | Client secret issued by Campion. Also used to verify SSO token signatures. |
| Allowed ACARA IDs | `allowed_acara_ids` | ACARA IDs this site may provision for, one per line or comma separated. Blank accepts any. |
| Validate ISBNs | `validate_isbn` | Reject subscriptions failing ISBN check-digit validation when no product catalogue is loaded. Default on. |
| Create Moodle accounts on first SSO login | `sso_autocreate` | Create a Moodle account for a verified SSO user who does not have one. Default **off**. |
| Default ACARA ID | `acara_id` | Fallback ACARA ID for single-campus sites, used when a call supplies none. |

---

## Provisioning API

```
POST /local/campion/api.php
Content-Type: application/json
X-Campion-API-Key: <provisioning API key>
```

Send the body as **UTF-8**, and set `Content-Length` to the number of **bytes**, not
characters. A multi-byte character such as a typographic quote is 3 bytes but 1 character; a
mismatch causes the web server to reject the request with a `400` before Moodle sees it.

Field and action names are matched case-insensitively. Accepted aliases: `surname` /
`lastName`, `yearLevel` / `year`, `subscriptionPeriod` / `period`, `isbn` / `ISBN`.

### Actions

| Action | Purpose |
|---|---|
| `Ping` | Connectivity and payload diagnostic. Touches no data. |
| `CreateUser` | Create a Campion user record, or update it if the email already exists. |
| `UpdateUser` | Update a user. Creates the record if absent. Supports `newEmail`. |
| `GetUser` | Return a user and their active subscriptions. |
| `DeleteUser` | Remove a user and their subscriptions. |
| `CreateSubscription` | Subscribe a user to a product by ISBN. |
| `EditSubscription` | Change period, status or chargeable flag on an existing subscription. |
| `DeleteSubscription` | Remove a subscription. |
| `GetProduct` | Look up a single product by ISBN. |
| `GetActiveProducts` | List products with active subscriptions. |

### ACARA ID

Campuses of the same school frequently share an identical school name, so `school` alone
cannot identify one. Send `acaraId` with every user:

```json
{
  "action":    "CreateUser",
  "email":     "student@example.edu.au",
  "acaraId":   "12345",
  "school":    "St Example College — North Campus",
  "firstName": "Tony",
  "surname":   "Ambro",
  "yearLevel": "10",
  "role":      "student"
}
```

Resolved in this order, first match winning:

1. an explicit `acaraId` — also accepted as `AcaraId`, `ACARAID`, `acara_id`, `schoolId`;
2. the value of `school`, if it consists only of digits;
3. the site-level **Default ACARA ID** setting.

**Validation.** If **Allowed ACARA IDs** is configured, `CreateUser` and `UpdateUser` reject
any ACARA ID outside that list with `422`, and the response names the permitted IDs. If the
setting is blank, any ACARA ID is accepted.

`GetUser` scopes lookups by ACARA ID when one is supplied. `DeleteUser` returns `409` and
deletes nothing if the stored ACARA ID differs. A changed ACARA ID is recorded in the activity
log as a `campus_change` event.

### ISBN validation

`CreateSubscription` validates the ISBN before creating anything:

1. **The product catalogue is authoritative.** If the ISBN exists in `local_campion_products`
   the subscription proceeds, whatever the value's shape — this covers Campion's internal
   product codes, which are catalogue entries but not ISBNs.
2. **Unknown ISBN with a catalogue loaded** returns `422`, naming the catalogue size.
3. **No catalogue loaded** falls back to validating the ISBN-13 or ISBN-10 check digit, so a
   part-configured site is not locked out. Governed by the **Validate ISBNs** setting.

```json
{
  "action":             "CreateSubscription",
  "email":              "student@example.edu.au",
  "isbn":               "9780140328721",
  "subscriptionPeriod": "2027",
  "chargeable":         false
}
```

### Ping

`{"action":"Ping"}` reports what the server received — content type, declared versus actual
body length, whether the body parsed, and the field *names* present (never their values):

```json
{
  "success": true,
  "content_length": { "declared_by_client": 165, "bytes_received": 169, "match": false },
  "body_parsed": true,
  "fields_received": ["action", "email", "school"]
}
```

A `match` of `false` means the client's `Content-Length` disagrees with the bytes that
arrived. Fix that before debugging anything else.

### Response codes

| Code | Meaning |
|---|---|
| `200` | Success. Body contains `"success": true`. |
| `400` | Malformed request — missing required field, or a body that could not be parsed. The response includes the JSON parse error and byte counts. |
| `401` | Missing or incorrect API key. |
| `404` | User or product not found. |
| `409` | Campus mismatch — the record belongs to a different ACARA ID. Nothing was changed. |
| `422` | Validation failure — unknown ACARA ID, or unknown or invalid ISBN. |
| `503` | Integration disabled in plugin settings. |

---

## Single sign-on

Two entry points:

- **Publisher-initiated** — `local/campion/launch.php` redirects a logged-in Moodle user to
  Campion IAM, carrying a single-use CSRF `state` token stored in the Moodle session.
- **IAM-initiated** — Campion posts to `local/campion/sso.php` with a signed token, or with an
  OAuth 2.0 authorisation code that is exchanged for one.

Tokens are verified for signature (HMAC-SHA256 against the OAuth client secret), expiry and
replay before any session is established. On a publisher-initiated flow the `state` token is
also verified and consumed; IAM-initiated flows legitimately carry no prior session state and
rely on the signature alone.

### Accounts

**The provisioning API does not create Moodle accounts.** `CreateUser` records a Campion
entitlement against an email address and links it to an existing Moodle user if one is found.
Users are expected to arrive through SSO, where the signed token is the authentication — there
is no password and nothing is emailed.

If the site relies on Campion to provision users outright, enable **Create Moodle accounts on
first SSO login**. A verified SSO user without a Moodle account then has one created, with no
usable password, reachable only through SSO. Name fields are taken from the token's name
claims where present. With the setting off, SSO for an unknown user fails with
`sso_user_not_found`.

---

## Privacy

The plugin stores user email, name, school, ACARA ID, year level, role, subscription records
and an activity log. All are declared through Moodle's privacy API in
`classes/privacy/provider.php` and are exported and deleted through the standard privacy
subsystem.
