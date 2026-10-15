<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Start a Campion SSO login for a visitor who is not signed in to Moodle.
 *
 * This is the entry point behind the "Log in with Campion" option on the Moodle
 * login page. It differs from launch.php in one important respect: launch.php
 * requires an existing Moodle session, because it exists to send an
 * already-signed-in user out to a Campion resource. This page deliberately does
 * not call require_login() — an anonymous visitor is the whole point of it.
 *
 * The return leg is sso.php, which is unchanged: the authorisation code is
 * exchanged, the identity verified, and the Moodle session established there.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/campion/login.php'));

// Already signed in — there is nothing to authenticate. Send them to their resources.
if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/local/campion/resources.php'));
}

if (!local_campion_is_enabled()) {
    throw new moodle_exception('sso_login_disabled', 'local_campion');
}

// Refuse to start a flow that cannot possibly complete, rather than bouncing the
// visitor to Campion and having them fail on the way back.
if (!local_campion_sso_is_configured()) {
    throw new moodle_exception('sso_login_notconfigured', 'local_campion');
}

global $SESSION;

// ── Session writes must come first ────────────────────────────────
// Everything written to $SESSION has to happen before the licence check below,
// because local_campion_check_unlock() calls
// \core\session\manager::write_close() before its outbound request. Once the
// session is closed, a later write is discarded — Moodle reports "mutated the
// session after it was closed" and the value is gone on the next request. The
// CSRF state would then be absent when sso.php looks for it, and sso.php treats
// an absent state as an IAM-initiated flow and skips verification, so the check
// would silently pass without ever having been made.

// Remember where the visitor was trying to get to, so SSO returns them there
// instead of dumping them on the dashboard. Moodle sets $SESSION->wantsurl when
// a protected page bounced them to the login form.
$wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);
if ($wantsurl !== '') {
    $SESSION->campion_loginwantsurl = $wantsurl;
} else if (!empty($SESSION->wantsurl)) {
    $SESSION->campion_loginwantsurl = $SESSION->wantsurl;
} else {
    unset($SESSION->campion_loginwantsurl);
}

// CSRF state token, verified and consumed in sso.php. An anonymous visitor still
// has a Moodle session, so this survives the round trip to Campion.
$state = bin2hex(random_bytes(16));
$SESSION->campion_oauth_state = $state;

// OpenID Connect replay protection: echoed back in the id_token's nonce claim and
// compared there. Written here with the state, before the session closes.
$nonce = bin2hex(random_bytes(16));
$SESSION->campion_oauth_nonce = $nonce;

// Licence gate — from here on the session is closed for writing.
if (!local_campion_check_unlock()) {
    throw new moodle_exception('notunlocked', 'local_campion');
}

$redirecturi = $CFG->wwwroot . '/local/campion/sso.php';

// No identity hint: we do not know who this is yet. That is the difference between
// this flow and launch.php, and Campion prompts for credentials accordingly. The
// site-level ACARA ID is sent when configured, so a single-campus site lands the
// visitor on the right campus.
$authurl = local_campion_build_auth_url('', $state, $redirecturi, [
    'nonce'   => $nonce,
    'acaraid' => local_campion_get_acara_id(),
]);

local_campion_log(
    'sso_login_start',
    'Login-page SSO — redirecting an anonymous visitor to Campion IAM'
);

redirect($authurl);
