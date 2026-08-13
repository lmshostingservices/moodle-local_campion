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
if (!empty($_SERVER['HTTP_X_CAMPION_API_KEY'])) {
    $provided_key = $_SERVER['HTTP_X_CAMPION_API_KEY'];
} elseif (!empty(optional_param('api_key', '', PARAM_TEXT))) {
    $provided_key = optional_param('api_key', '', PARAM_TEXT);
} elseif (!empty(optional_param('api_key', '', PARAM_TEXT))) {
    $provided_key = optional_param('api_key', '', PARAM_TEXT);
}

if (!hash_equals($allowed_key, $provided_key)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

// ── Route action ─────────────────────────────────────────────────
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = @json_decode(file_get_contents('php://input'), true);
    if (is_array($body) && isset($body['action'])) {
        $action = $body['action'];
    }
} else {
    $body = filter_input_array(INPUT_GET, FILTER_UNSAFE_RAW) ?: [];
}

switch ($action) {

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
// Action handlers
// ─────────────────────────────────────────────────────────────────

function api_get_product($data) {
    global $DB;

    $isbn = isset($data['isbn']) ? clean_param($data['isbn'], PARAM_RAW_TRIMMED) : '';
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

    $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';
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
        'yearLevel'     => $cu->yearlevel,
        'role'          => $cu->role,
        'subscriptions' => $subscriptions,
    ]);
}

function api_create_user($data) {
    global $DB;

    $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'email is required']);
        return;
    }

    $now = time();

    if ($DB->record_exists('local_campion_users', ['email' => $email])) {
        // Update existing record (CreateUser may also update).
        $cu = $DB->get_record('local_campion_users', ['email' => $email]);
        $cu->firstname    = isset($data['firstName']) ? clean_param($data['firstName'], PARAM_TEXT) : $cu->firstname;
        $cu->lastname     = isset($data['surname'])   ? clean_param($data['surname'],   PARAM_TEXT) : $cu->lastname;
        $cu->school       = isset($data['school'])    ? clean_param($data['school'],    PARAM_TEXT) : $cu->school;
        $cu->yearlevel    = isset($data['yearLevel']) ? clean_param($data['yearLevel'], PARAM_TEXT) : $cu->yearlevel;
        $cu->role         = isset($data['role'])      ? clean_param($data['role'],      PARAM_TEXT) : $cu->role;
        $cu->timemodified = $now;
        $DB->update_record('local_campion_users', $cu);

        // Link to Moodle user if not already linked.
        if (empty($cu->moodleuserid)) {
            $mu = local_campion_find_moodle_user($email);
            if ($mu) {
                $DB->set_field('local_campion_users', 'moodleuserid', $mu->id, ['id' => $cu->id]);
            }
        }

        local_campion_log('update_user', 'Updated via CreateUser (email: ' . $email . ')', $email);
        echo json_encode(['success' => true, 'created' => false, 'message' => 'User updated']);
        return;
    }

    $role = isset($data['role']) ? clean_param($data['role'], PARAM_TEXT) : 'student';
    $cu = (object)[
        'email'        => $email,
        'firstname'    => isset($data['firstName']) ? clean_param($data['firstName'], PARAM_TEXT) : '',
        'lastname'     => isset($data['surname'])   ? clean_param($data['surname'],   PARAM_TEXT) : '',
        'school'       => isset($data['school'])    ? clean_param($data['school'],    PARAM_TEXT) : '',
        'yearlevel'    => isset($data['yearLevel']) ? clean_param($data['yearLevel'], PARAM_TEXT) : '',
        'role'         => $role,
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

    $DB->insert_record('local_campion_users', $cu);
    local_campion_log('create_user', 'User created via provisioning API (email: ' . $email . ')', $email);

    echo json_encode(['success' => true, 'created' => true, 'message' => 'User created']);
}

function api_update_user($data) {
    global $DB;

    $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';
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

    if (isset($data['firstName'])) $cu->firstname  = clean_param($data['firstName'],  PARAM_TEXT);
    if (isset($data['surname']))   $cu->lastname   = clean_param($data['surname'],     PARAM_TEXT);
    if (isset($data['school']))    $cu->school     = clean_param($data['school'],      PARAM_TEXT);
    if (isset($data['yearLevel'])) $cu->yearlevel  = clean_param($data['yearLevel'],   PARAM_TEXT);
    if (isset($data['role']))      $cu->role       = clean_param($data['role'],        PARAM_TEXT);
    $cu->timemodified = time();

    // Handle email change.
    if (!empty($data['newEmail'])) {
        $new_email = strtolower(trim($data['newEmail']));
        if (!$DB->record_exists('local_campion_users', ['email' => $new_email])) {
            $cu->email = $new_email;
        }
    }

    $DB->update_record('local_campion_users', $cu);
    local_campion_log('update_user', 'User updated via provisioning API (email: ' . $email . ')', $email);

    echo json_encode(['success' => true, 'message' => 'User updated']);
}

function api_delete_user($data) {
    global $DB;

    $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';
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

    $DB->delete_records('local_campion_subscriptions', ['campionuserid' => $cu->id]);
    $DB->delete_records('local_campion_users', ['id' => $cu->id]);

    local_campion_log('delete_user', 'User deleted via provisioning API (email: ' . $email . ')', $email);

    echo json_encode(['success' => true, 'message' => 'User deleted']);
}

function api_create_subscription($data) {
    global $DB;

    $email = isset($data['email'])              ? strtolower(trim($data['email'])) : '';
    $isbn  = isset($data['isbn'])               ? clean_param($data['isbn'], PARAM_RAW_TRIMMED) : '';
    $period = isset($data['subscriptionPeriod'])? clean_param($data['subscriptionPeriod'], PARAM_TEXT) : '';

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

    // Get product name from products table if available.
    $product = $DB->get_record('local_campion_products', ['isbn' => $isbn]);
    $productname = $product ? $product->productname : $isbn;

    $chargeable = isset($data['chargeable']) ? (int)(bool)$data['chargeable'] : 1;
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

    $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';
    $isbn  = isset($data['isbn'])  ? clean_param($data['isbn'], PARAM_RAW_TRIMMED) : '';

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

    if (isset($data['subscriptionPeriod'])) $sub->subscriptionperiod = clean_param($data['subscriptionPeriod'], PARAM_TEXT);
    if (isset($data['status']))             $sub->status             = clean_param($data['status'], PARAM_TEXT);
    if (isset($data['chargeable']))         $sub->chargeable         = (int)(bool)$data['chargeable'];
    $sub->timemodified = time();

    $DB->update_record('local_campion_subscriptions', $sub);
    local_campion_log('edit_subscription', "Edited subscription isbn=$isbn for $email", $email);

    echo json_encode(['success' => true, 'message' => 'Subscription updated']);
}

function api_delete_subscription($data) {
    global $DB;

    $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';
    $isbn  = isset($data['isbn'])  ? clean_param($data['isbn'], PARAM_RAW_TRIMMED) : '';

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
