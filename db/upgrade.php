<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upgrade steps for local_campion.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Invalidate the opcode cache for the given plugin-relative files.
 *
 * @param array $files Plugin-relative paths, e.g. ['lib.php', 'db/upgrade.php']
 */
function local_campion_upgrade_invalidate(array $files) {
    if (function_exists('opcache_invalidate')) {
        $plugindir = realpath(__DIR__ . '/..');
        foreach ($files as $f) {
            $full = $plugindir . '/' . $f;
            if (file_exists($full)) {
                opcache_invalidate($full, true);
            }
        }
    } else if (function_exists('opcache_reset')) {
        opcache_reset();
    }
}

function xmldb_local_campion_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026070700) {
        local_campion_upgrade_invalidate(['lib.php', 'version.php', 'settings.php', 'db/upgrade.php']);
        upgrade_plugin_savepoint(true, 2026070700, 'local', 'campion');
    }

    // v1.0.1 - FIX-XMLDB-DEFAULT: Removed empty-string DEFAULT from NOTNULL CHAR fields
    // in install.xml (firstname, lastname, school, yearlevel, productname, subscriptionperiod).
    // Source-only fix — no DB schema changes. Stops XMLDB debugging warnings on sites
    // running local_adminer or similar XMLDB scanners.
    if ($oldversion < 2026071500) {
        local_campion_upgrade_invalidate(['lib.php', 'version.php', 'db/upgrade.php', 'db/install.xml']);
        upgrade_plugin_savepoint(true, 2026071500, 'local', 'campion');
    }

    // v1.0.5 - FIX-API-DOMAIN: API endpoint URLs consolidated on lms-labs.com.
    // Source-only fix — no DB schema changes.
    //
    // NOTE: v1.0.5 shipped four separate blocks all guarded by "$oldversion < 2026072300"
    // and all saving to 2026072300. Because $oldversion is not reassigned by
    // upgrade_plugin_savepoint(), every one of those blocks ran on each upgrade and the
    // savepoint was written four times. Harmless, but it is collapsed to one block here.
    if ($oldversion < 2026072300) {
        local_campion_upgrade_invalidate(['lib.php', 'version.php', 'db/upgrade.php']);
        upgrade_plugin_savepoint(true, 2026072300, 'local', 'campion');
    }

    // v1.0.6 - ADD-ACARAID: per-user ACARA school/campus identifier.
    // Campion provisions multiple campuses of the same school, each with its own ACARA ID,
    // and campuses frequently share an identical school name. 'school' alone cannot
    // distinguish them, so acaraid is stored as its own field.
    if ($oldversion < 2026082500) {

        $table = new xmldb_table('local_campion_users');
        $field = new xmldb_field('acaraid', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'school');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('acaraid', XMLDB_INDEX_NOTUNIQUE, ['acaraid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Backfill: earlier integrations had no acaraid field, so some sites were told to
        // put the ACARA ID in 'school'. Where 'school' holds nothing but digits it is an
        // ACARA ID, not a school name — copy it across so those records are not stranded.
        // Done in PHP rather than SQL because regex support differs across Moodle DB drivers.
        $candidates = $DB->get_records_select(
            'local_campion_users',
            "(acaraid IS NULL OR acaraid = '') AND school <> ''",
            [],
            '',
            'id, school'
        );
        foreach ($candidates as $c) {
            $school = trim((string)$c->school);
            if ($school !== '' && ctype_digit($school)) {
                $DB->set_field('local_campion_users', 'acaraid', $school, ['id' => $c->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2026082500, 'local', 'campion');
    }

    return true;
}
