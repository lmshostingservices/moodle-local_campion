<?php
// require_login() — deliberately omitted: this endpoint uses its own authentication or is not a user-facing web page.
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Campion Publisher Provisioning API endpoint.
 *
 * Campion Education calls this endpoint to automate:
 *  GetProduct, GetActiveProducts, GetUser, CreateUser, UpdateUser,
 *  DeleteUser, CreateSubscription, EditSubscription, DeleteSubscription.
 *
 * Authentication: API key in X-Campion-API-Key header (or ?api_key= param).
 * All responses are JSON. All production traffic must use HTTPS.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

\core\session\manager::write_close();

header('Content-Type: application/json');

// ── Feature gate ─────────────────────────────────────────────────
if (!local_campion_is_enabled()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Campion integration is disabled']);
    exit;
}

// ── API key authentication ────────────────────────────────────────
$allowed_key = local_campion_get_provision_api_key();
if (empty($allowed_key)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Provisioning API key not configured']);
    exit;
}

$provided_key = '';
$headerkey = local_campion_api_request_header('HTTP_X_CAMPION_API_KEY');
if ($headerkey !== '') {
    // Trim: some HTTP clients (notably HTTPAPI on IBM i) leave trailing CR/space on headers.
    $provided_key = trim($headerkey);
} else {
    $provided_key = trim(optional_param('api_key', '', PARAM_TEXT));
}

if (!hash_equals($allowed_key, $provided_key)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

// ── Parse request body ───────────────────────────────────────────
// A request either carries a body (the documented POST transport) or it does not (a GET used
// for a quick manual check). Keying off the body itself rather than the HTTP verb keeps the
// branch honest — a POST with an empty body is handled exactly like a GET, which is what we
// want — and removes any need to inspect the request method.
$rawbody   = (string)file_get_contents('php://input');
$jsonerror = null;
$hasbody   = ($rawbody !== '');

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($hasbody) {
    $body = local_campion_api_parse_body($rawbody, $jsonerror);
} else {
    $body = local_campion_api_read_get_params();
}

if (!is_array($body)) {
    $body = [];
}

// Body 'action' wins over the query-string one.
$bodyaction = local_campion_api_field($body, 'action');
if ($bodyaction !== null && $bodyaction !== '') {
    $action = (string)$bodyaction;
}

// A request that carried a body we could not parse is a client-side error worth reporting
// precisely, rather than falling through to a bare "Unknown action:" response.
if ($hasbody && $action === '' && $jsonerror !== null) {
    $declaredlength = local_campion_api_request_header('CONTENT_LENGTH');
    http_response_code(400);
    echo json_encode([
        'success'            => false,
        'error'              => 'Request body could not be parsed as JSON',
        'json_error'         => $jsonerror,
        'bytes_received'     => strlen($rawbody),
        'content_length_hdr' => ($declaredlength === '') ? null : (int)$declaredlength,
        'content_type_hdr'   => local_campion_api_request_header('CONTENT_TYPE') ?: null,
        'hint'               => 'Check for smart/curly quotes, a UTF-8 BOM, or a Content-Length '
                              . 'counting characters instead of bytes.',
    ]);
    exit;
}

// Accept any casing of the action name ("createuser", "CREATEUSER", ...).
$action = local_campion_api_canonical_action($action);

switch ($action) {

    case 'Ping':
        api_ping($body, $rawbody, $jsonerror);
        break;

    case 'GetProduct':
        api_get_product($body);
        break;

    case 'GetActiveProducts':
        api_get_active_products();
        break;

    case 'GetUser':
        api_get_user($body);
        break;

    case 'CreateUser':
        api_create_user($body);
        break;

    case 'UpdateUser':
        api_update_user($body);
        break;

    case 'DeleteUser':
        api_delete_user($body);
        break;

    case 'CreateSubscription':
        api_create_subscription($body);
        break;

    case 'EditSubscription':
        api_edit_subscription($body);
        break;

    case 'DeleteSubscription':
        api_delete_subscription($body);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        exit;
}

// ─────────────────────────────────────────────────────────────────
// Request parsing helpers
// ─────────────────────────────────────────────────────────────────

/**
 * Read a single HTTP request header.
 *
 * Moodle's required_param()/optional_param() read GET and POST parameters only; they cannot
 * return request headers. Moodle exposes no API for headers, and core reads the server
 * environment array directly for the same purpose (see moodlelib's getremoteaddr() and
 * is_https()). This function is the one place in the plugin that touches that array, so the
 * raw access stays confined to a single reviewable line.
 *
 * Only the three header keys below are readable; anything else returns the default, so no
 * caller can reach arbitrary server state.
 *
 * @param  string $key      One of the whitelisted header keys
 * @param  string $default  Returned when the header is absent
 * @return string
 */
function local_campion_api_request_header($key, $default = '') {
    $allowed = [
        'HTTP_X_CAMPION_API_KEY',
        'CONTENT_TYPE',
        'CONTENT_LENGTH',
    ];

    if (!in_array($key, $allowed, true)) {
        return $default;
    }

    // phpcs:ignore moodle.PHP.SuperglobalUsage -- No Moodle API exposes request headers.
    return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : $default;
}

/**
 * Collect provisioning parameters from a GET request.
 *
 * Built from an explicit whitelist via optional_param() rather than by reading the query
 * string directly, so every value is cleaned by Moodle on the way in.
 *
 * Note that GET parameter names are matched exactly, whereas POST JSON field names are
 * matched case-insensitively. POST is the documented transport; GET exists for quick manual
 * checks, so the common alias spellings are listed here but arbitrary casings are not.
 *
 * @return array
 */
function local_campion_api_read_get_params() {
    $spec = [
        'action'             => PARAM_ALPHANUMEXT,
        'email'              => PARAM_TEXT,
        'newEmail'           => PARAM_TEXT,
        'firstName'          => PARAM_TEXT,
        'firstname'          => PARAM_TEXT,
        'surname'            => PARAM_TEXT,
        'lastName'           => PARAM_TEXT,
        'school'             => PARAM_TEXT,
        'schoolName'         => PARAM_TEXT,
        'acaraId'            => PARAM_ALPHANUMEXT,
        'acaraid'            => PARAM_ALPHANUMEXT,
        'acara_id'           => PARAM_ALPHANUMEXT,
        'schoolId'           => PARAM_ALPHANUMEXT,
        'yearLevel'          => PARAM_TEXT,
        'yearlevel'          => PARAM_TEXT,
        'year'               => PARAM_TEXT,
        'role'               => PARAM_TEXT,
        'isbn'               => PARAM_ALPHANUMEXT,
        'subscriptionPeriod' => PARAM_TEXT,
        'period'             => PARAM_TEXT,
        'status'             => PARAM_TEXT,
        'chargeable'         => PARAM_BOOL,
    ];

    $params = [];
    foreach ($spec as $name => $type) {
        $value = optional_param($name, null, $type);
        if ($value !== null && $value !== '') {
            $params[$name] = $value;
        }
    }

    return $params;
}

/**
 * Parse a POST body into an array, tolerating the malformations commonly produced by
 * non-browser HTTP clients (ERP middleware, IBM i HTTPAPI, mainframe gateways).
 *
 * Handled, in order:
 *  1. Leading/trailing whitespace and a UTF-8 BOM.
 *  2. Well-formed JSON — the normal path.
 *  3. JSON whose string delimiters are typographic ("smart") quotes, which happens when a
 *     payload is authored in a word processor or pasted through an email client.
 *  4. application/x-www-form-urlencoded bodies.
 *
 * @param  string      $raw        Raw request body
 * @param  string|null $jsonerror  Set to the JSON error message if JSON parsing failed
 * @return array                   Parsed fields (empty array if nothing could be parsed)
 */
function local_campion_api_parse_body($raw, &$jsonerror = null) {
    $jsonerror = null;

    $trimmed = trim($raw);

    // Strip a UTF-8 BOM if present — json_decode() rejects it.
    if (strncmp($trimmed, "\xEF\xBB\xBF", 3) === 0) {
        $trimmed = substr($trimmed, 3);
    }

    if ($trimmed === '') {
        return [];
    }

    // Path 1: straight JSON.
    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }
    $jsonerror = json_last_error_msg();

    // Path 2: JSON delimited with typographic quotes. Substitute the ASCII equivalents and
    // retry. Only accepted if the result parses cleanly, so this cannot corrupt valid input.
    $smartquotes = [
        "\xE2\x80\x9C" => '"',  // U+201C left double quotation mark
        "\xE2\x80\x9D" => '"',  // U+201D right double quotation mark
        "\xE2\x80\x98" => '"',  // U+2018 left single quotation mark
        "\xE2\x80\x99" => '"',  // U+2019 right single quotation mark
        "\xC2\xA0"     => ' ',  // U+00A0 non-breaking space
    ];
    $repaired = strtr($trimmed, $smartquotes);
    if ($repaired !== $trimmed) {
        $decoded = json_decode($repaired, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $jsonerror = null;
            return $decoded;
        }
    }

    // Path 3: form-encoded body. Parsed from the raw body rather than from PHP's populated
    // form array, so the same code path works regardless of server configuration.
    $parsed = [];
    parse_str($trimmed, $parsed);
    if (!empty($parsed)) {
        // parse_str() never fails, so only trust it if it produced something action-shaped.
        foreach ($parsed as $k => $v) {
            if (is_string($k) && strcasecmp($k, 'action') === 0 && $v !== '') {
                return $parsed;
            }
        }
    }

    return [];
}

/**
 * Case-insensitive field lookup.
 *
 * Campion's own middleware sends camelCase ("firstName", "yearLevel"). ERP systems very often
 * fold field names to upper or lower case in transit, so match on any casing rather than
 * silently dropping the value.
 *
 * @param  array  $data
 * @param  string ...$names  One or more accepted names; first match wins
 * @return mixed|null
 */
function local_campion_api_field(array $data, ...$names) {
    foreach ($names as $name) {
        if (array_key_exists($name, $data)) {
            return $data[$name];
        }
    }
    // Fall back to a case-insensitive sweep.
    foreach ($names as $name) {
        foreach ($data as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
    }
    return null;
}

/**
 * Read the ACARA ID from a request payload.
 *
 * Accepts every spelling seen in the wild: acaraId, AcaraId, ACARAID, acara_id, schoolId.
 * If none is present but 'school' contains nothing but digits, that value is the ACARA ID —
 * this is the arrangement used before the dedicated field existed, and it is still what some
 * senders do. Falls back to the site-level ACARA ID setting for single-campus installs.
 *
 * @param  array $data
 * @return string
 */
function local_campion_api_read_acaraid(array $data) {
    $value = local_campion_api_field($data, 'acaraId', 'acaraid', 'acara_id', 'AcaraID', 'schoolId', 'schoolid');

    if ($value === null || trim((string)$value) === '') {
        $school = local_campion_api_field($data, 'school');
        if ($school !== null && ctype_digit(trim((string)$school))) {
            $value = trim((string)$school);
        }
    }

    if ($value === null || trim((string)$value) === '') {
        $value = local_campion_get_acara_id();
    }

    return trim((string)$value);
}

/**
 * Normalise an action name to its canonical casing.
 *
 * @param  string $action
 * @return string  Canonical name, or the original string if unrecognised
 */
function local_campion_api_canonical_action($action) {
    $known = [
        'ping'                => 'Ping',
        'getproduct'          => 'GetProduct',
        'getactiveproducts'   => 'GetActiveProducts',
        'getuser'             => 'GetUser',
        'createuser'          => 'CreateUser',
        'updateuser'          => 'UpdateUser',
        'deleteuser'          => 'DeleteUser',
        'createsubscription'  => 'CreateSubscription',
        'editsubscription'    => 'EditSubscription',
        'deletesubscription'  => 'DeleteSubscription',
    ];
    $key = strtolower(trim((string)$action));
    return isset($known[$key]) ? $known[$key] : (string)$action;
}

// ─────────────────────────────────────────────────────────────────
// Action handlers
// ─────────────────────────────────────────────────────────────────

/**
 * Connectivity and payload diagnostic.
 *
 * Touches no data. Reports what the server actually received so a client can confirm its
 * framing is correct — in particular whether the declared Content-Length matches the number
 * of bytes that arrived, which is the usual cause of an upstream 400 from nginx.
 *
 * Only field *names* are echoed, never values, so this is safe to run against production.
 */
function api_ping($data, $rawbody, $jsonerror) {
    $declaredraw = local_campion_api_request_header('CONTENT_LENGTH');
    $declared    = ($declaredraw === '') ? null : (int)$declaredraw;
    $received    = strlen($rawbody);

    echo json_encode([
        'success'         => true,
        'message'         => 'Campion provisioning API reachable',
        'plugin_version'  => get_config('local_campion', 'version'),
        'time'            => time(),
        'content_type'    => local_campion_api_request_header('CONTENT_TYPE') ?: null,
        'content_length'  => [
            'declared_by_client' => $declared,
            'bytes_received'     => $received,
            'match'              => ($declared === null) ? null : ($declared === $received),
        ],
        'body_parsed'     => !empty($data),
        'json_error'      => $jsonerror,
        'fields_received' => array_keys(is_array($data) ? $data : []),
        'site_acara_id'   => local_campion_get_acara_id() ?: null,
    ]);
}

function api_get_product($data) {
    global $DB;

    $isbn = clean_param(trim((string)local_campion_api_field($data, 'isbn', 'ISBN')), PARAM_ALPHANUMEXT);
    if (empty($isbn)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'isbn is required']);
        return;
    }

    $product = $DB->get_record('local_campion_products', ['isbn' => $isbn]);
    if (!$product) {
        echo json_encode(['success' => false, 'error' => 'Product not found', 'isbn' => $isbn]);
        return;
    }

    echo json_encode([
        'success'     => true,
        'isbn'        => $product->isbn,
        'description' => $product->productname,
        'status'      => $product->status,
    ]);
}

function api_get_active_products() {
    global $DB;

    $products = $DB->get_records('local_campion_products', ['status' => 'active']);
    $list = [];
    foreach ($products as $p) {
        $list[] = [
            'isbn'        => $p->isbn,
            'description' => $p->productname,
            'status'      => $p->status,
        ];
    }

    echo json_encode(['success' => true, 'products' => $list]);
}

function api_get_user($data) {
    global $DB;

    $email   = strtolower(trim((string)local_campion_api_field($data, 'email')));
    $acaraid = local_campion_api_read_acaraid($data);

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email is required']);
        return;
    }

    $cu = $DB->get_record('local_campion_users', ['email' => $email]);
    if (!$cu) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // If the caller named a campus, confirm the record belongs to it. Returning a user from
    // a different campus would be wrong for schools whose campuses share a name.
    if ($acaraid !== '' && !empty($cu->acaraid) && (string)$cu->acaraid !== $acaraid) {
        echo json_encode([
            'success' => false,
            'error'   => 'User not found at the requested ACARA ID',
            'acaraId' => $acaraid,
        ]);
        return;
    }

    $subs = $DB->get_records('local_campion_subscriptions', ['campionuserid' => $cu->id]);
    $subscriptions = [];
    foreach ($subs as $s) {
        $subscriptions[] = [
            'isbn'               => $s->isbn,
            'product'            => $s->productname,
            'status'             => $s->status,
            'subscriptionPeriod' => $s->subscriptionperiod,
        ];
    }

    echo json_encode([
        'success'       => true,
        'email'         => $cu->email,
        'firstName'     => $cu->firstname,
        'surname'       => $cu->lastname,
        'school'        => $cu->school,
        'acaraId'       => isset($cu->acaraid) ? $cu->acaraid : null,
        'yearLevel'     => $cu->yearlevel,
        'role'          => $cu->role,
        'subscriptions' => $subscriptions,
    ]);
}

function api_create_user($data) {
    global $DB;

    $email = strtolower(trim((string)local_campion_api_field($data, 'email')));
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email is required']);
        return;
    }

    if (!validate_email($email)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'email is not a valid address',
            'email'   => $email,
        ]);
        return;
    }

    $acaraid   = local_campion_api_read_acaraid($data);
    $firstname = local_campion_api_field($data, 'firstName', 'firstname');
    $surname   = local_campion_api_field($data, 'surname', 'lastName', 'lastname');
    $school    = local_campion_api_field($data, 'school', 'schoolName', 'schoolname');
    $yearlevel = local_campion_api_field($data, 'yearLevel', 'yearlevel', 'year');
    $role      = local_campion_api_field($data, 'role');

    if (!local_campion_acara_id_allowed($acaraid)) {
        http_response_code(422);
        echo json_encode([
            'success'         => false,
            'error'           => 'Unknown ACARA ID for this site',
            'acaraId'         => $acaraid,
            'allowedAcaraIds' => local_campion_get_allowed_acara_ids(),
            'hint'            => 'This Moodle site is provisioned for the campuses listed above. '
                               . 'Check the ACARA ID, or have the site administrator add it under '
                               . 'Campion Integration settings.',
        ]);
        return;
    }

    $now = time();
    $existing = $DB->get_record('local_campion_users', ['email' => $email]);

    if ($existing) {
        // CreateUser doubles as an update, per the Campion spec.
        $cu = $existing;
        $previousacara = (string)(isset($cu->acaraid) ? $cu->acaraid : '');

        if ($firstname !== null) {
            $cu->firstname = clean_param($firstname, PARAM_TEXT);
        }
        if ($surname !== null) {
            $cu->lastname = clean_param($surname, PARAM_TEXT);
        }
        if ($school !== null) {
            $cu->school = clean_param($school, PARAM_TEXT);
        }
        if ($yearlevel !== null) {
            $cu->yearlevel = clean_param($yearlevel, PARAM_TEXT);
        }
        if ($role !== null) {
            $cu->role = clean_param($role, PARAM_TEXT);
        }
        if ($acaraid !== '') {
            $cu->acaraid = clean_param($acaraid, PARAM_ALPHANUMEXT);
        }

        $cu->timemodified = $now;
        $DB->update_record('local_campion_users', $cu);

        // A user arriving under a different ACARA ID has moved campus. Allowed, but recorded —
        // it is otherwise invisible and matters for billing and resource entitlement.
        if ($acaraid !== '' && $previousacara !== '' && $previousacara !== $acaraid) {
            local_campion_log(
                'campus_change',
                'ACARA ID changed from ' . $previousacara . ' to ' . $acaraid . ' for ' . $email,
                $email
            );
        }

        // Link to Moodle user if not already linked.
        if (empty($cu->moodleuserid)) {
            $mu = local_campion_find_moodle_user($email);
            if ($mu) {
                $DB->set_field('local_campion_users', 'moodleuserid', $mu->id, ['id' => $cu->id]);
            }
        }

        local_campion_log('update_user', 'Updated via CreateUser (email: ' . $email . ', acaraId: ' . ($acaraid ?: 'none') . ')', $email);
        echo json_encode([
            'success' => true,
            'created' => false,
            'message' => 'User updated',
            'email'   => $email,
            'acaraId' => isset($cu->acaraid) ? $cu->acaraid : null,
        ]);
        return;
    }

    $cu = (object)[
        'email'        => $email,
        'firstname'    => $firstname !== null ? clean_param($firstname, PARAM_TEXT) : '',
        'lastname'     => $surname   !== null ? clean_param($surname,   PARAM_TEXT) : '',
        'school'       => $school    !== null ? clean_param($school,    PARAM_TEXT) : '',
        'acaraid'      => $acaraid   !== ''   ? clean_param($acaraid,   PARAM_ALPHANUMEXT) : null,
        'yearlevel'    => $yearlevel !== null ? clean_param($yearlevel, PARAM_TEXT) : '',
        'role'         => $role      !== null ? clean_param($role,      PARAM_TEXT) : 'student',
        'campionid'    => null,
        'sso_only'     => 0,
        'timecreated'  => $now,
        'timemodified' => $now,
    ];

    // Link to existing Moodle user if one exists.
    $mu = local_campion_find_moodle_user($email);
    if ($mu) {
        $cu->moodleuserid = $mu->id;
    }

    $cu->id = $DB->insert_record('local_campion_users', $cu);
    local_campion_log('create_user', 'User created via provisioning API (email: ' . $email . ', acaraId: ' . ($acaraid ?: 'none') . ')', $email);

    echo json_encode([
        'success' => true,
        'created' => true,
        'message' => 'User created',
        'email'   => $email,
        'acaraId' => $cu->acaraid,
    ]);
}

function api_update_user($data) {
    global $DB;

    $email = strtolower(trim((string)local_campion_api_field($data, 'email')));
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email is required']);
        return;
    }

    $cu = $DB->get_record('local_campion_users', ['email' => $email]);
    if (!$cu) {
        // Auto-create if not found (per Campion spec: create/update can be combined).
        api_create_user($data);
        return;
    }

    $acaraid   = local_campion_api_read_acaraid($data);
    $firstname = local_campion_api_field($data, 'firstName', 'firstname');
    $surname   = local_campion_api_field($data, 'surname', 'lastName', 'lastname');
    $school    = local_campion_api_field($data, 'school', 'schoolName', 'schoolname');
    $yearlevel = local_campion_api_field($data, 'yearLevel', 'yearlevel', 'year');
    $role      = local_campion_api_field($data, 'role');

    if (!local_campion_acara_id_allowed($acaraid)) {
        http_response_code(422);
        echo json_encode([
            'success'         => false,
            'error'           => 'Unknown ACARA ID for this site',
            'acaraId'         => $acaraid,
            'allowedAcaraIds' => local_campion_get_allowed_acara_ids(),
        ]);
        return;
    }

    $previousacara = (string)(isset($cu->acaraid) ? $cu->acaraid : '');

    if ($firstname !== null) {
        $cu->firstname = clean_param($firstname, PARAM_TEXT);
    }
    if ($surname !== null) {
        $cu->lastname = clean_param($surname, PARAM_TEXT);
    }
    if ($school !== null) {
        $cu->school = clean_param($school, PARAM_TEXT);
    }
    if ($yearlevel !== null) {
        $cu->yearlevel = clean_param($yearlevel, PARAM_TEXT);
    }
    if ($role !== null) {
        $cu->role = clean_param($role, PARAM_TEXT);
    }
    if ($acaraid !== '') {
        $cu->acaraid = clean_param($acaraid, PARAM_ALPHANUMEXT);
    }

    $cu->timemodified = time();

    if ($acaraid !== '' && $previousacara !== '' && $previousacara !== $acaraid) {
        local_campion_log(
            'campus_change',
            'ACARA ID changed from ' . $previousacara . ' to ' . $acaraid . ' for ' . $email,
            $email
        );
    }

    // Handle email change.
    $newemail = local_campion_api_field($data, 'newEmail', 'newemail');
    if (!empty($newemail)) {
        $newemail = strtolower(trim((string)$newemail));
        if (validate_email($newemail) && !$DB->record_exists('local_campion_users', ['email' => $newemail])) {
            $cu->email = $newemail;
        }
    }

    $DB->update_record('local_campion_users', $cu);
    local_campion_log('update_user', 'User updated via provisioning API (email: ' . $email . ')', $email);

    echo json_encode([
        'success' => true,
        'message' => 'User updated',
        'email'   => $cu->email,
        'acaraId' => isset($cu->acaraid) ? $cu->acaraid : null,
    ]);
}

function api_delete_user($data) {
    global $DB;

    $email   = strtolower(trim((string)local_campion_api_field($data, 'email')));
    $acaraid = local_campion_api_read_acaraid($data);

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email is required']);
        return;
    }

    $cu = $DB->get_record('local_campion_users', ['email' => $email]);
    if (!$cu) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Refuse to delete across campuses. A destructive call naming a different ACARA ID is a
    // mismatch in the caller, not an instruction to remove this record.
    if ($acaraid !== '' && !empty($cu->acaraid) && (string)$cu->acaraid !== $acaraid) {
        http_response_code(409);
        echo json_encode([
            'success'         => false,
            'error'           => 'User exists but belongs to a different ACARA ID — not deleted',
            'requestedAcaraId' => $acaraid,
            'actualAcaraId'   => $cu->acaraid,
        ]);
        return;
    }

    $DB->delete_records('local_campion_subscriptions', ['campionuserid' => $cu->id]);
    $DB->delete_records('local_campion_users', ['id' => $cu->id]);

    local_campion_log('delete_user', 'User deleted via provisioning API (email: ' . $email . ')', $email);

    echo json_encode(['success' => true, 'message' => 'User deleted']);
}

function api_create_subscription($data) {
    global $DB;

    $email  = strtolower(trim((string)local_campion_api_field($data, 'email')));
    $isbn   = clean_param(trim((string)local_campion_api_field($data, 'isbn', 'ISBN')), PARAM_ALPHANUMEXT);
    $period = clean_param((string)local_campion_api_field($data, 'subscriptionPeriod', 'subscriptionperiod', 'period'), PARAM_TEXT);

    if (empty($email) || empty($isbn)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email and isbn are required']);
        return;
    }

    $cu = $DB->get_record('local_campion_users', ['email' => $email]);
    if (!$cu) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Validate the ISBN before creating anything.
    //
    // The product catalogue is authoritative: Campion issues internal product codes alongside
    // real ISBNs, so a value that is in the catalogue is valid whatever its shape. Only when
    // no catalogue has been loaded do we fall back to checking the ISBN check digit, so a
    // site that has not yet synced its products is not locked out entirely.
    $product = $DB->get_record('local_campion_products', ['isbn' => $isbn]);

    if (!$product) {
        $cataloguesize = $DB->count_records('local_campion_products');

        if ($cataloguesize > 0) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error'   => 'Unknown ISBN — not in this site\'s product catalogue',
                'isbn'    => $isbn,
                'hint'    => 'The catalogue holds ' . $cataloguesize . ' product(s). Check the '
                           . 'ISBN, or have the product added before subscribing users to it.',
            ]);
            return;
        }

        if (get_config('local_campion', 'validate_isbn') && !local_campion_validate_isbn($isbn)) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid ISBN — failed check-digit validation',
                'isbn'    => $isbn,
                'hint'    => 'Expected a valid ISBN-13 or ISBN-10. No product catalogue is '
                           . 'loaded on this site, so the check digit is the only check available.',
            ]);
            return;
        }
    }

    $productname = $product ? $product->productname : $isbn;

    $chargeableraw = local_campion_api_field($data, 'chargeable');
    $chargeable = ($chargeableraw === null) ? 1 : (int)(bool)$chargeableraw;
    $now = time();

    $existing = $DB->get_record('local_campion_subscriptions', ['campionuserid' => $cu->id, 'isbn' => $isbn]);
    if ($existing) {
        $existing->status             = 'active';
        $existing->subscriptionperiod = $period;
        $existing->chargeable         = $chargeable;
        $existing->timemodified       = $now;
        $DB->update_record('local_campion_subscriptions', $existing);
        local_campion_log('update_subscription', "Updated subscription isbn=$isbn for $email", $email);
        echo json_encode(['success' => true, 'created' => false, 'message' => 'Subscription updated']);
        return;
    }

    $DB->insert_record('local_campion_subscriptions', (object)[
        'campionuserid'      => $cu->id,
        'isbn'               => $isbn,
        'productname'        => $productname,
        'subscriptionperiod' => $period,
        'status'             => 'active',
        'chargeable'         => $chargeable,
        'timecreated'        => $now,
        'timemodified'       => $now,
    ]);

    local_campion_log('create_subscription', "Created subscription isbn=$isbn for $email", $email);

    echo json_encode(['success' => true, 'created' => true, 'message' => 'Subscription created']);
}

function api_edit_subscription($data) {
    global $DB;

    $email = strtolower(trim((string)local_campion_api_field($data, 'email')));
    $isbn  = clean_param(trim((string)local_campion_api_field($data, 'isbn', 'ISBN')), PARAM_ALPHANUMEXT);

    if (empty($email) || empty($isbn)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email and isbn are required']);
        return;
    }

    $cu = $DB->get_record('local_campion_users', ['email' => $email]);
    if (!$cu) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    $sub = $DB->get_record('local_campion_subscriptions', ['campionuserid' => $cu->id, 'isbn' => $isbn]);
    if (!$sub) {
        // Create if not found (combined create/update per Campion spec).
        api_create_subscription($data);
        return;
    }

    $period     = local_campion_api_field($data, 'subscriptionPeriod', 'subscriptionperiod', 'period');
    $status     = local_campion_api_field($data, 'status');
    $chargeable = local_campion_api_field($data, 'chargeable');

    if ($period !== null) {

        $sub->subscriptionperiod = clean_param($period, PARAM_TEXT);

    }
    if ($status !== null) {
        $sub->status = clean_param($status, PARAM_TEXT);
    }
    if ($chargeable !== null) {
        $sub->chargeable = (int)(bool)$chargeable;
    }
    $sub->timemodified = time();

    $DB->update_record('local_campion_subscriptions', $sub);
    local_campion_log('edit_subscription', "Edited subscription isbn=$isbn for $email", $email);

    echo json_encode(['success' => true, 'message' => 'Subscription updated']);
}

function api_delete_subscription($data) {
    global $DB;

    $email = strtolower(trim((string)local_campion_api_field($data, 'email')));
    $isbn  = clean_param(trim((string)local_campion_api_field($data, 'isbn', 'ISBN')), PARAM_ALPHANUMEXT);

    if (empty($email) || empty($isbn)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email and isbn are required']);
        return;
    }

    $cu = $DB->get_record('local_campion_users', ['email' => $email]);
    if (!$cu) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    $DB->delete_records('local_campion_subscriptions', ['campionuserid' => $cu->id, 'isbn' => $isbn]);
    local_campion_log('delete_subscription', "Deleted subscription isbn=$isbn for $email", $email);

    echo json_encode(['success' => true, 'message' => 'Subscription deleted']);
}
