<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Publisher-Initiated SSO — redirects authenticated Moodle users to Campion.
 *
 * When a student clicks a Campion resource link inside Moodle, this page
 * initiates the OAuth 2.0 code flow by redirecting them to Campion IAM
 * with their email as a login_hint.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/campion/launch.php'));

if (!local_campion_is_enabled()) {
    throw new moodle_exception('notunlocked', 'local_campion');
}

if (!local_campion_check_unlock()) {
    throw new moodle_exception('notunlocked', 'local_campion');
}

// CSRF state token — stored in session for verification in sso.php callback.
$state = bin2hex(random_bytes(16));
$_SESSION['campion_oauth_state'] = $state;

$redirect_uri = $CFG->wwwroot . '/local/campion/sso.php';
$auth_url     = local_campion_build_auth_url($USER->email, $state, $redirect_uri);

local_campion_log('sso_launch', 'Publisher-initiated SSO — redirecting to Campion IAM',
    $USER->email, $USER->id);

redirect($auth_url);
