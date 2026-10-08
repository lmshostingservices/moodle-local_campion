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
$plugin->version   = 2026100801;
$plugin->release   = '1.0.16'; // ACTIVATION-STATES (v1.0.16): Reverted the v1.0.15 dual plugin-id guess — 'campion' is the correct short id, 'local_campion' the component. The API key now travels in an Authorization: Bearer header instead of the query string, and no part of it appears in any page, response or log. The activation check distinguishes five states (missing credentials, invalid credentials, no entitlement, activated, could-not-verify) so an unreachable server is no longer reported as 'unlicensed', and a credentials fault is not presented as something a purchase would fix. Added a Check licence status action that forces a fresh query; it is a GET and spends no credits.
$plugin->requires  = 2022041900; // Moodle 4.0+
$plugin->supported = [400, 500]; // Moodle 4.0 to 5.x
$plugin->maturity  = MATURITY_STABLE;
