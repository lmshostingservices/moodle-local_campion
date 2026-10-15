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
 * Version information for local_campion.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_campion';
$plugin->version   = 2026101501;
$plugin->release   = '1.0.23'; // FIX-OAUTH-SCOPE (v1.0.23): Campion IAM rejects an authorisation request that carries no scope — {"code":400,"error":"scope must be set."} — so every sign-in this site started failed before reaching the login form. The authorisation request now sends scope (configurable, default "openid profile email"), the username parameter Campion's endpoint takes alongside the standard login_hint, the user's acaraId when known, and an OpenID Connect nonce that is verified on return. PREVIOUS (v1.0.22): The "Log in with Campion" option rendered at the very top of the page, above the site header, because the only server-side insertion point Moodle offers on the login page emits at the top of <body>. It is now moved into the login box directly beneath the sign-in form — where core places native identity providers — and is hidden until it has been placed, so it no longer appears above the header first. PREVIOUS (v1.0.21): Adds a "Log in with Campion" option to the Moodle login page, for a user who goes to Moodle directly rather than arriving from the Campion platform. New login.php starts the authorisation-code flow for a visitor with no Moodle session, preserving the page they were trying to reach; the existing sso.php handles the return leg unchanged. The option is contributed through the standard output hook, since core builds its login-page provider list from auth plugins only, and is governed by a new "Show Log in with Campion on the login page" setting. PREVIOUS (v1.0.20): Audited the rest of the SSO flow rather than waiting for each layer to fail. The plugin treated access_token as a JWT; in OpenID Connect the claims are in id_token and the access token is usually opaque, so it now prefers id_token and falls back to the userinfo endpoint when the token is not a readable JWT. JWT validation reports which of its eight checks failed instead of a bare false, notices a non-HMAC signing algorithm instead of silently mismatching, and the email claim is read under several common names.
$plugin->requires  = 2022041900; // Moodle 4.0+
$plugin->supported = [400, 500]; // Moodle 4.0 to 5.x
$plugin->maturity  = MATURITY_STABLE;
