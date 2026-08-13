<?php
// require_login() — deliberately omitted: this endpoint uses its own authentication or is not a user-facing web page.
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Campion IAM SSO callback endpoint.
 *
 * This page handles both:
 *  (A) IAM-Initiated SSO (Option 1): Campion IAM sends the user here with
 *      an authorisation code (?code=...). We exchange the code for an access
 *      token containing a JWT, then validate the JWT and log the user in.
 *
 *  (B) Direct JWT delivery: Campion IAM posts a JWT directly as ?token=...
 *      (some Campion deployments use this simpler path).
 *
 * Security requirements (per Campion IAM documentation):
 *  - Validate JWT signature with client_secret (HMAC-SHA256)
 *  - Reject tokens older than 5 minutes (1-minute skew allowed)
 *  - Reject replayed JTIs
 *  - If authenticated user changes, sign out old user first
 *  - SSO users cannot set local passwords
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/campion/sso.php'));
$PAGE->set_title('Campion SSO');

// ── Feature gate ─────────────────────────────────────────────────
if (!local_campion_is_enabled()) {
    throw new moodle_exception('notunlocked', 'local_campion');
}

// ── Determine JWT ─────────────────────────────────────────────────
$jwt = null;

// Path A: direct token in query string (IAM-initiated, Option 1 simple)
$raw_token = optional_param('token', '', PARAM_RAW);
if (!empty($raw_token)) {
    $jwt = $raw_token;
}

// Path B: OAuth 2.0 authorisation code flow
$code  = optional_param('code', '', PARAM_RAW);
$state = optional_param('state', '', PARAM_RAW);
if (empty($jwt) && !empty($code)) {
    $redirect_uri = $CFG->wwwroot . '/local/campion/sso.php';
    $token_data   = local_campion_exchange_code($code, $redirect_uri);

    if (!$token_data || empty($token_data['access_token'])) {
        local_campion_log('sso_error', 'Code exchange failed — no access token returned');
        throw new moodle_exception('sso_login_error', 'local_campion');
    }

    // The access token itself contains (or wraps) the JWT.
    $jwt = $token_data['access_token'];
}

if (empty($jwt)) {
    // No code and no token — redirect to Moodle login.
    redirect(new moodle_url('/login/index.php'));
}

// ── Validate JWT ─────────────────────────────────────────────────
$payload = local_campion_validate_jwt($jwt);

if ($payload === false) {
    local_campion_log('sso_error', 'JWT validation failed', null, null);
    // Per Campion security requirements: if token is invalid, sign user out.
    if (isloggedin()) {
        require_logout();
    }
    throw new moodle_exception('sso_invalid_token', 'local_campion');
}

// ── Extract user info from JWT ────────────────────────────────────
$email       = isset($payload['email'])      ? strtolower(trim($payload['email'])) : '';
$resource_id = isset($payload['resource_id']) ? $payload['resource_id'] : '';
$page_id     = isset($payload['page_id'])     ? $payload['page_id']     : '';

if (empty($email)) {
    local_campion_log('sso_error', 'JWT contained no email claim');
    throw new moodle_exception('sso_no_email', 'local_campion');
}

// ── Handle already-authenticated user (per Campion security spec) ─
if (isloggedin() && !isguestuser()) {
    if ($USER->email !== $email) {
        // Token is for a different user — sign out, then sign in as the new user.
        local_campion_log('sso_user_switch',
            'Switching from ' . $USER->email . ' to ' . $email,
            $email, $USER->id);
        require_logout();
    } else {
        // Token is for the same user — just redirect to the requested resource.
        local_campion_log('sso_reauth', 'Same user re-authenticated via Campion SSO', $email, $USER->id);
        $target = local_campion_resolve_resource_url($resource_id, $page_id);
        redirect($target);
    }
}

// ── Find Moodle user ──────────────────────────────────────────────
$moodle_user = local_campion_find_moodle_user($email);

if (!$moodle_user) {
    local_campion_log('sso_error', 'No Moodle account for ' . $email, $email);
    throw new moodle_exception('sso_user_not_found', 'local_campion');
}

// ── Ensure Campion user record exists ────────────────────────────
$campion_user = local_campion_get_or_create_campion_user($email, [
    'firstname' => $moodle_user->firstname,
    'lastname'  => $moodle_user->lastname,
]);

// Mark as SSO-only and update last login time.
global $DB;
$DB->update_record('local_campion_users', (object)[
    'id'           => $campion_user->id,
    'moodleuserid' => $moodle_user->id,
    'sso_only'     => 1,
    'lastssologin' => time(),
    'timemodified' => time(),
]);

// ── Complete Moodle login ─────────────────────────────────────────
\core\session\manager::write_close();
complete_user_login($moodle_user);

local_campion_log('sso_login', 'Campion SSO login successful', $email, $moodle_user->id);

// ── Redirect to resource (or Moodle home) ─────────────────────────
$target = local_campion_resolve_resource_url($resource_id, $page_id);
redirect($target);

// ─────────────────────────────────────────────────────────────────

/**
 * Resolve the target URL after successful SSO login.
 * If a resource_id or page_id was provided in the JWT, build the Campion
 * launch URL; otherwise redirect to the Moodle dashboard.
 *
 * @param  string $resource_id
 * @param  string $page_id
 * @return moodle_url
 */
function local_campion_resolve_resource_url($resource_id, $page_id) {
    global $CFG;
    if (!empty($resource_id)) {
        // Redirect to the student's Campion resources page so they can launch
        // the specific resource.
        return new moodle_url('/local/campion/resources.php', [
            'isbn' => $resource_id,
            'page' => $page_id,
        ]);
    }
    return new moodle_url('/local/campion/resources.php');
}
