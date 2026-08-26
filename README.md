# local_campion

Moodle plugin.

## Licence

GNU GPL v3 or later.

## Provisioning API

`POST /local/campion/api.php`

Headers:

```
Content-Type: application/json
X-Campion-API-Key: <key from Site administration → Plugins → Campion Integration>
```

Send the body as **UTF-8**, and set `Content-Length` to the number of **bytes**, not characters.

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

`acaraId` is resolved in this order, first match winning:

1. an explicit `acaraId` field (also accepted as `AcaraId`, `ACARAID`, `acara_id`, `schoolId`);
2. the value of `school`, if it consists only of digits — this supports senders that were
   configured before the dedicated field existed;
3. the site-level **Default ACARA ID** setting, for single-campus installations.

`GetUser`, `CreateUser` and `UpdateUser` all return the stored `acaraId`. Supplying an
`acaraId` to `GetUser` scopes the lookup to that campus; supplying one to `DeleteUser` that
does not match the stored value returns `409` and deletes nothing. A user whose `acaraId`
changes is recorded in the activity log as a `campus_change` event.

### Field names

Field names are matched case-insensitively, so `firstName`, `firstname` and `FIRSTNAME` are
equivalent. Action names are likewise case-insensitive. Accepted aliases: `surname`/`lastName`,
`yearLevel`/`year`, `subscriptionPeriod`/`period`, `isbn`/`ISBN`.

### Ping

`{"action":"Ping"}` touches no data and reports what the server received — request method,
content type, declared versus actual body length, whether the body parsed, and the field
*names* present (never their values). Use it to confirm a client's HTTP framing:

```json
{
  "success": true,
  "content_length": { "declared_by_client": 165, "bytes_received": 169, "match": false },
  "body_parsed": true,
  "fields_received": ["action", "email", "school"]
}
```

A `match` of `false` means the client's `Content-Length` disagrees with the bytes that
arrived — fix that before debugging anything else.
