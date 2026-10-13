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
$plugin->version   = 2026101301;
$plugin->release   = '1.0.20'; // FIX-IDENTITY-RESOLUTION (v1.0.20): Audited the rest of the SSO flow rather than waiting for each layer to fail. The plugin treated access_token as a JWT; in OpenID Connect the claims are in id_token and the access token is usually opaque, so it now prefers id_token and falls back to the userinfo endpoint when the token is not a readable JWT. JWT validation reports which of its eight checks failed instead of a bare false, notices a non-HMAC signing algorithm instead of silently mismatching, and the email claim is read under several common names.
$plugin->requires  = 2022041900; // Moodle 4.0+
$plugin->supported = [400, 500]; // Moodle 4.0 to 5.x
$plugin->maturity  = MATURITY_STABLE;
