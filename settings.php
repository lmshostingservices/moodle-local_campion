<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin settings for local_campion.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_campion', get_string('pluginname', 'local_campion'));

    $ADMIN->add('localplugins', $settings);

    // ── Enable / disable ────────────────────────────────────────────
    $settings->add(new admin_setting_configcheckbox(
        'local_campion/enabled',
        get_string('enabled', 'local_campion'),
        get_string('enabled_desc', 'local_campion'),
        1
    ));

    // ── Section heading ──────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_campion/heading',
        get_string('settings_heading', 'local_campion'),
        get_string('settings_heading_desc', 'local_campion')
    ));

    // ── Campion IAM OAuth credentials ────────────────────────────────
    $settings->add(new admin_setting_configtext(
        'local_campion/client_id',
        get_string('client_id', 'local_campion'),
        get_string('client_id_desc', 'local_campion'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_campion/client_secret',
        get_string('client_secret', 'local_campion'),
        get_string('client_secret_desc', 'local_campion'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_campion/iam_url',
        get_string('iam_url', 'local_campion'),
        get_string('iam_url_desc', 'local_campion'),
        'https://iam.campion.com.au',
        PARAM_URL
    ));

    // ── Provisioning API key ─────────────────────────────────────────
    $settings->add(new admin_setting_configpasswordunmask(
        'local_campion/provision_api_key',
        get_string('api_key', 'local_campion'),
        get_string('api_key_desc', 'local_campion'),
        ''
    ));

    // ── Allowed ACARA IDs ────────────────────────────────────────────
    // Provisioning calls naming an ACARA ID outside this list are rejected with 422. Leave
    // blank to accept any ACARA ID, which is the pre-1.0.9 behaviour.
    $settings->add(new admin_setting_configtextarea(
        'local_campion/allowed_acara_ids',
        get_string('allowed_acara_ids', 'local_campion'),
        get_string('allowed_acara_ids_desc', 'local_campion'),
        '',
        PARAM_TEXT
    ));

    // ── ISBN validation ──────────────────────────────────────────────
    $settings->add(new admin_setting_configcheckbox(
        'local_campion/validate_isbn',
        get_string('validate_isbn', 'local_campion'),
        get_string('validate_isbn_desc', 'local_campion'),
        1
    ));

    // ── Auto-create Moodle accounts on first SSO ─────────────────────
    $settings->add(new admin_setting_configcheckbox(
        'local_campion/sso_autocreate',
        get_string('sso_autocreate', 'local_campion'),
        get_string('sso_autocreate_desc', 'local_campion'),
        0
    ));

    // ── School ACARA ID (default for this site) ──────────────────────
    // Used only when a provisioning call supplies no acaraId of its own. Multi-campus
    // installations should send acaraId per user rather than relying on this.
    $settings->add(new admin_setting_configtext(
        'local_campion/acara_id',
        get_string('acara_id', 'local_campion'),
        get_string('acara_id_desc', 'local_campion'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // ── Endpoint info (read-only display) ────────────────────────────
    global $CFG;
    $sso_url      = $CFG->wwwroot . '/local/campion/sso.php';
    $api_url      = $CFG->wwwroot . '/local/campion/api.php';

    $settings->add(new admin_setting_description(
        'local_campion/sso_endpoint_info',
        get_string('sso_endpoint', 'local_campion'),
        '<code>' . htmlspecialchars($sso_url) . '</code><br><small>' .
        get_string('sso_endpoint_desc', 'local_campion') . '</small>'
    ));

    $settings->add(new admin_setting_description(
        'local_campion/provision_endpoint_info',
        get_string('provision_endpoint', 'local_campion'),
        '<code>' . htmlspecialchars($api_url) . '</code><br><small>' .
        get_string('provision_endpoint_desc', 'local_campion') . '</small>'
    ));
}
