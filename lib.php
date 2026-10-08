<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Core library functions for local_campion.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ─────────────────────────────────────────────────────────────────
// Credit unlock / feature gate
// ─────────────────────────────────────────────────────────────────

/**
 * Returns true if the plugin is globally enabled via site settings.
 */
function local_campion_is_enabled() {
    $val = get_config('local_campion', 'enabled');
    return ($val === false) ? true : (bool)$val;
}

/**
 * Get Site ID (from central local_aiconfig if available).
 */
function local_campion_get_siteid() {
    $aiconfig = get_config('local_aiconfig', 'siteid');
    if (!empty($aiconfig)) {
        return $aiconfig;
    }
    return get_config('local_campion', 'siteid');
}

/**
 * Get API Key (from central local_aiconfig if available).
 */
function local_campion_get_apikey() {
    $aiconfig = get_config('local_aiconfig', 'apikey');
    if (!empty($aiconfig)) {
        return $aiconfig;
    }
    return get_config('local_campion', 'apikey');
}

/**
 * Verify credit unlock with AI Grader server. Fail-closed: deny if unreachable.
 *
 * @return bool
 */
function local_campion_check_unlock($forcerecheck = false) {
    static $cache = null;
    if ($cache !== null && !$forcerecheck) {
        return $cache;
    }

    $siteid = local_campion_get_siteid();
    $apikey = local_campion_get_apikey();

    if (empty($siteid) || empty($apikey)) {
        $cache = false;
        return false;
    }

    \core\session\manager::write_close();

    // Fails closed: anything other than a confirmed unlock denies access. The distinction
    // between "confirmed locked" and "could not verify" governs what the administrator is
    // told, not what they can reach — see local_campion_unlock_diagnostic().
    $result = local_campion_query_unlock($siteid, $apikey);
    $cache = ($result['state'] === 'activated');

    return $cache;
}

/**
 * The short plugin identifier the licence server knows this plugin by.
 *
 * Deliberately the bare name, not the Moodle component: the LMS Labs contract is that
 * 'campion' is the short id and 'local_campion' is its component.
 *
 * @return string
 */
function local_campion_unlock_plugin_id() {
    return 'campion';
}

/**
 * Ask the licence server whether this plugin is unlocked for this site.
 *
 * A status check only: a GET that spends no credits and must never become a purchase. The
 * API key travels in the Authorization header rather than the query string, so it cannot
 * reach web server logs, proxy logs or browser history.
 *
 * @param  string $siteid
 * @param  string $apikey
 * @return array  ['state' => string, 'http_code' => int, 'error' => ?string]
 *                state: activated | not_entitled | invalid_credentials | unverified
 */
function local_campion_query_unlock($siteid, $apikey) {
    $url = 'https://lms-labs.com/api/plugin-unlock/verify'
        . '?pluginId=' . rawurlencode(local_campion_unlock_plugin_id())
        . '&siteId=' . rawurlencode($siteid);

    $response = false;
    $httpcode = 0;
    $error = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apikey,
            'Accept: application/json',
        ]);
        $response = curl_exec($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $error = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer " . $apikey . "\r\nAccept: application/json\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response !== false && isset($http_response_header)) {
            preg_match('/HTTP\\/\\S+\\s+(\\d+)/', $http_response_header[0], $m);
            $httpcode = isset($m[1]) ? (int)$m[1] : 0;
        }
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;

    // Classify. An unreachable server, an unexpected status or a body we cannot parse all mean
    // "could not verify" — which is NOT the same as "confirmed unlicensed".
    if ($httpcode === 0) {
        $state = 'unverified';
    } else if ($httpcode === 401 || $httpcode === 403) {
        $state = 'invalid_credentials';
    } else if ($httpcode !== 200) {
        $state = 'unverified';
    } else if (!is_array($decoded) || !array_key_exists('unlocked', $decoded)) {
        $state = 'unverified';
    } else {
        $state = !empty($decoded['unlocked']) ? 'activated' : 'not_entitled';
    }

    return [
        'state'     => $state,
        'http_code' => $httpcode,
        'error'     => $error,
    ];
}

// ─────────────────────────────────────────────────────────────────
// Campion config helpers
// ─────────────────────────────────────────────────────────────────

function local_campion_get_client_id() {
    return get_config('local_campion', 'client_id');
}

function local_campion_get_client_secret() {
    return get_config('local_campion', 'client_secret');
}

function local_campion_get_iam_url() {
    $url = get_config('local_campion', 'iam_url');
    return $url ? rtrim($url, '/') : 'https://iam.campion.com.au';
}

function local_campion_get_provision_api_key() {
    return get_config('local_campion', 'provision_api_key');
}

function local_campion_get_acara_id() {
    return get_config('local_campion', 'acara_id');
}

// ─────────────────────────────────────────────────────────────────
// JWT validation (Campion IAM → Moodle)
// ─────────────────────────────────────────────────────────────────

/**
 * Decode and validate a JWT from Campion IAM.
 *
 * Security requirements from Campion documentation:
 *  - Must validate cryptographic signature using client_secret (HMAC-SHA256)
 *  - Must reject tokens older than 5 minutes (with 1-minute skew allowance)
 *  - Must reject replayed tokens (store JTI in DB for 6 minutes)
 *  - Must reject if user is already authenticated with a different account
 *
 * @param  string      $token  Raw JWT string
 * @return array|false         Decoded payload array, or false on failure
 */
function local_campion_validate_jwt($token) {
    global $DB;

    if (empty($token)) {
        return false;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    [$header_b64, $payload_b64, $sig_b64] = $parts;

    // Verify HMAC-SHA256 signature with client_secret.
    $secret = local_campion_get_client_secret();
    if (empty($secret)) {
        // No secret configured — cannot validate.
        return false;
    }
    $expected_sig = hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $secret, true);
    $provided_sig = local_campion_base64url_decode($sig_b64);

    if (!hash_equals($expected_sig, $provided_sig)) {
        return false;
    }

    // Decode payload.
    $payload_json = local_campion_base64url_decode($payload_b64);
    $payload      = json_decode($payload_json, true);
    if (!is_array($payload)) {
        return false;
    }

    // Check expiry: deny if older than 5 minutes (with 1-minute skew = 6 minutes total).
    $now  = time();
    $iat  = isset($payload['iat']) ? (int)$payload['iat'] : 0;
    $exp  = isset($payload['exp']) ? (int)$payload['exp'] : ($iat + 300);
    $skew = 60;

    if ($now > ($exp + $skew)) {
        return false; // Token expired.
    }
    if ($iat > ($now + $skew)) {
        return false; // Token issued in the future (clock skew beyond tolerance).
    }

    // Replay prevention: check JTI.
    $jti = isset($payload['jti']) ? $payload['jti'] : hash('sha256', $token);

    // Clean up old nonces first (> 6 minutes old).
    $DB->delete_records_select('local_campion_jwt_nonces', 'timecreated < :cutoff', ['cutoff' => $now - 360]);

    if ($DB->record_exists('local_campion_jwt_nonces', ['jti' => $jti])) {
        return false; // Replayed token.
    }

    // Store this nonce.
    $DB->insert_record('local_campion_jwt_nonces', (object)[
        'jti'         => $jti,
        'timecreated' => $now,
    ]);

    return $payload;
}

/**
 * URL-safe base64 decode.
 */
function local_campion_base64url_decode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) {
        $input .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($input, '-_', '+/'));
}

// ─────────────────────────────────────────────────────────────────
// User management
// ─────────────────────────────────────────────────────────────────

/**
 * Find or create a local_campion_users record for the given email.
 *
 * @param  string $email
 * @param  array  $data  Optional: firstname, lastname, school, yearlevel, role
 * @return object        The local_campion_users record
 */
function local_campion_get_or_create_campion_user($email, array $data = []) {
    global $DB;

    $email = strtolower(trim($email));
    $record = $DB->get_record('local_campion_users', ['email' => $email]);

    if (!$record) {
        $record = (object)[
            'email'       => $email,
            'firstname'   => $data['firstname'] ?? '',
            'lastname'    => $data['lastname']  ?? '',
            'school'      => $data['school']    ?? '',
            'acaraid'     => $data['acaraid']   ?? (local_campion_get_acara_id() ?: null),
            'yearlevel'   => $data['yearlevel'] ?? '',
            'role'        => $data['role']      ?? 'student',
            'campionid'   => $data['campionid'] ?? null,
            'sso_only'    => 0,
            'timecreated' => time(),
            'timemodified'=> time(),
        ];
        $record->id = $DB->insert_record('local_campion_users', $record);
    }

    return $record;
}

/**
 * Validate an ISBN-13 or ISBN-10 check digit.
 *
 * Campion also issues internal product codes that are not ISBNs (for example CAMFTG00078ST),
 * so this only judges values that look like a 10- or 13-character book number. Anything else
 * returns false and is expected to be validated against the product catalogue instead.
 *
 * @param  string $isbn
 * @return bool   True if the value is a structurally valid ISBN-13 or ISBN-10
 */
function local_campion_validate_isbn($isbn) {
    $isbn = strtoupper(preg_replace('/[\s-]/', '', (string)$isbn));

    if (preg_match('/^\d{13}$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$isbn[$i] * (($i % 2 === 0) ? 1 : 3);
        }
        return ((10 - ($sum % 10)) % 10) === (int)$isbn[12];
    }

    if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$isbn[$i] * (10 - $i);
        }
        $sum += ($isbn[9] === 'X') ? 10 : (int)$isbn[9];
        return ($sum % 11) === 0;
    }

    return false;
}

/**
 * Explain, step by step, why the credit-unlock check passes or fails.
 *
 * local_campion_check_unlock() returns only true or false, and the three ways it can fail —
 * no credentials, the licence server unreachable, or the server reporting the plugin locked —
 * all surface to the admin as the same "not activated" message. That makes a genuine
 * configuration problem indistinguishable from an outage.
 *
 * This runs the same sequence and reports what happened at each step. Secrets are masked:
 * only whether a value is present, and its first few characters, are ever returned.
 *
 * @return array Diagnostic detail, safe to return over the provisioning API
 */
function local_campion_unlock_diagnostic() {
    $siteid = local_campion_get_siteid();
    $apikey = local_campion_get_apikey();

    // Reports whether credentials exist and where they came from, never any part of their
    // value. A key fragment in an admin page, a JSON response or a log is still a leak.
    $out = [
        'state'       => null,
        'site_id'     => [
            'present' => !empty($siteid),
            'source'  => !empty(get_config('local_aiconfig', 'siteid')) ? 'local_aiconfig'
                       : (!empty(get_config('local_campion', 'siteid')) ? 'local_campion' : 'none'),
        ],
        'api_key'     => [
            'present' => !empty($apikey),
            'source'  => !empty(get_config('local_aiconfig', 'apikey')) ? 'local_aiconfig'
                       : (!empty(get_config('local_campion', 'apikey')) ? 'local_campion' : 'none'),
        ],
        'plugin_id'   => local_campion_unlock_plugin_id(),
        'http_status' => null,
        'headline'    => null,
        'action'      => null,
    ];

    if (empty($siteid) || empty($apikey)) {
        $missing = [];
        if (empty($siteid)) {
            $missing[] = 'Site ID';
        }
        if (empty($apikey)) {
            $missing[] = 'API key';
        }
        $out['state']    = 'missing_credentials';
        $out['headline'] = implode(' and ', $missing) . (count($missing) > 1 ? ' are' : ' is')
                         . ' not configured, so the licence server was never contacted.';
        $out['action']   = 'Configure the AI Central Config (local_aiconfig) plugin with this '
                         . "site's Site ID and API key.";
        return $out;
    }

    $r = local_campion_query_unlock($siteid, $apikey);
    $out['state']       = $r['state'];
    $out['http_status'] = $r['http_code'];

    switch ($r['state']) {
        case 'activated':
            $out['headline'] = 'Activated. The licence server confirms an entitlement for this site.';
            break;

        case 'not_entitled':
            $out['headline'] = 'No Campion entitlement was found for the configured account.';
            $out['action']   = 'Unlock Campion for this site in the Plugin Manager. Checking '
                             . 'status here does not purchase a licence, and nothing has been '
                             . 'charged.';
            break;

        case 'invalid_credentials':
            $out['headline'] = 'The licence server rejected the configured credentials (HTTP '
                             . (int)$r['http_code'] . ').';
            $out['action']   = 'Correct the Site ID and API key in AI Central Config. Do not '
                             . 'purchase another licence to resolve this — the account may '
                             . 'already hold one.';
            break;

        case 'unverified':
        default:
            $out['headline'] = 'Licence status could not be verified'
                             . ($r['http_code'] ? ' (HTTP ' . (int)$r['http_code'] . ')' : '')
                             . ($r['error'] ? ': ' . $r['error'] : '')
                             . '. This is not the same as being unlicensed.';
            $out['action']   = 'Check that this server can reach lms-labs.com, then check the '
                             . 'status again. Access stays closed until the status is '
                             . 'confirmed, but no entitlement has been lost.';
            break;
    }

    return $out;
}

/**
 * Whether ISBN check-digit validation is switched on.
 *
 * A Moodle admin_setting_configcheckbox default is only written to config when an
 * administrator saves the settings page. Until that happens get_config() returns false, which
 * would silently disable validation on a freshly upgraded site — the setting reads as "on" in
 * the admin UI while behaving as "off". Treat "never saved" as the documented default instead.
 *
 * @return bool
 */
function local_campion_isbn_validation_enabled() {
    $value = get_config('local_campion', 'validate_isbn');

    if ($value === false || $value === null || $value === '') {
        return true; // Documented default: on.
    }

    return (bool)$value;
}

/**
 * The ACARA IDs this Moodle site is permitted to provision for.
 *
 * Read from the "Allowed ACARA IDs" setting, falling back to the single default ACARA ID.
 * An empty result means no allow-list is configured and ACARA IDs are not checked, which
 * keeps existing single-campus sites working unchanged after upgrade.
 *
 * @return array Zero or more ACARA ID strings
 */
function local_campion_get_allowed_acara_ids() {
    $raw = (string)get_config('local_campion', 'allowed_acara_ids');

    if (trim($raw) === '') {
        $raw = (string)get_config('local_campion', 'acara_id');
    }

    $ids = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);

    return array_values(array_unique(array_map('trim', $ids ?: [])));
}

/**
 * Check an ACARA ID against the configured allow-list.
 *
 * @param  string $acaraid
 * @return bool   True if allowed, or if no allow-list is configured
 */
function local_campion_acara_id_allowed($acaraid) {
    $allowed = local_campion_get_allowed_acara_ids();

    if (empty($allowed)) {
        return true;
    }

    return in_array(trim((string)$acaraid), $allowed, true);
}

/**
 * Find a Campion user record, optionally scoped to a single campus.
 *
 * Campuses of the same school routinely share a name, so where an ACARA ID is supplied it is
 * the authoritative discriminator and the school name is ignored.
 *
 * @param  string      $email
 * @param  string|null $acaraid  ACARA ID to scope the lookup to, or null for any campus
 * @return object|null
 */
function local_campion_find_campion_user($email, $acaraid = null) {
    global $DB;

    $email  = strtolower(trim($email));
    $params = ['email' => $email];

    if ($acaraid !== null && trim((string)$acaraid) !== '') {
        $params['acaraid'] = trim((string)$acaraid);
    }

    return $DB->get_record('local_campion_users', $params) ?: null;
}

/**
 * Find the Moodle user record that corresponds to the given email.
 * Returns null if not found.
 *
 * @param  string       $email
 * @return object|null
 */
function local_campion_find_moodle_user($email) {
    global $DB;
    return $DB->get_record('user', ['email' => strtolower(trim($email)), 'deleted' => 0]) ?: null;
}

/**
 * Log a Campion event.
 *
 * @param string      $event   Event type (e.g. 'sso_login', 'create_user')
 * @param string      $detail  Human-readable detail
 * @param string|null $email
 * @param int|null    $userid  Moodle user id
 */
function local_campion_log($event, $detail, $email = null, $userid = null) {
    global $DB;

    $DB->insert_record('local_campion_logs', (object)[
        'userid'      => $userid,
        'email'       => $email,
        'event'       => $event,
        'detail'      => $detail,
        'ip'          => getremoteaddr(),
        'timecreated' => time(),
    ]);
}

// ─────────────────────────────────────────────────────────────────
// OAuth 2.0 helpers (Publisher-Initiated SSO)
// ─────────────────────────────────────────────────────────────────

/**
 * Build the OAuth 2.0 authorisation URL to redirect the user to Campion IAM.
 *
 * @param  string $email      Pre-fill user email in Campion IAM
 * @param  string $state      Random CSRF state token
 * @param  string $redirecturi Our callback URL
 * @return string
 */
function local_campion_build_auth_url($email, $state, $redirecturi) {
    $iam_url   = local_campion_get_iam_url();
    $client_id = local_campion_get_client_id();

    $params = [
        'response_type' => 'code',
        'client_id'     => $client_id,
        'redirect_uri'  => $redirecturi,
        'state'         => $state,
        'login_hint'    => $email,
    ];

    return $iam_url . '/oauth/authorize?' . http_build_query($params);
}

/**
 * Exchange an authorisation code for an access token (containing JWT).
 *
 * @param  string      $code        OAuth 2.0 authorisation code
 * @param  string      $redirecturi Our callback URL
 * @return array|false              Token response array, or false on failure
 */
function local_campion_exchange_code($code, $redirecturi) {
    $iam_url       = local_campion_get_iam_url();
    $client_id     = local_campion_get_client_id();
    $client_secret = local_campion_get_client_secret();

    $post_data = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirecturi,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
    ]);

    $token_url = $iam_url . '/oauth/token';

    if (function_exists('curl_init')) {
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $response  = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $post_data,
                'timeout' => 15,
            ],
        ]);
        $response  = @file_get_contents($token_url, false, $ctx);
        $http_code = 0;
        if ($response !== false && isset($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $http_code = isset($m[1]) ? (int)$m[1] : 0;
        }
    }

    if ($http_code !== 200 || !$response) {
        return false;
    }

    return json_decode($response, true);
}
