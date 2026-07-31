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
function local_campion_check_unlock() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $siteid = local_campion_get_siteid();
    $apikey = local_campion_get_apikey();

    if (empty($siteid) || empty($apikey)) {
        $cache = false;
        return false;
    }

    $url = 'https://lms-labs.com/api/plugin-unlock/verify?pluginId=campion'
        . '&siteId=' . rawurlencode($siteid)
        . '&apiKey=' . rawurlencode($apikey);

    \core\session\manager::write_close();

    $response = false;
    $http_code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response  = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response !== false && isset($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $http_code = isset($m[1]) ? (int)$m[1] : 0;
        }
    }

    if ($http_code !== 200 || !$response) {
        $cache = false;
        return false;
    }

    $data  = json_decode($response, true);
    $cache = !empty($data['unlocked']);
    return $cache;
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
