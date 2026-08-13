<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_campion_upgrade($oldversion) {
    if ($oldversion < 2026072300) {
        upgrade_plugin_savepoint(true, 2026072300, 'local', 'campion');
    }
    return true;
}
