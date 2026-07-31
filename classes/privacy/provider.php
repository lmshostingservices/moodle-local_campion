<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Privacy provider for local_campion.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campion\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_campion_users', [
            'email'     => 'privacy:metadata:local_campion_users:email',
            'firstname' => 'privacy:metadata:local_campion_users:firstname',
            'lastname'  => 'privacy:metadata:local_campion_users:lastname',
            'school'    => 'privacy:metadata:local_campion_users:school',
            'yearlevel' => 'privacy:metadata:local_campion_users:yearlevel',
            'role'      => 'privacy:metadata:local_campion_users:role',
        ], 'privacy:metadata:local_campion_users');

        $collection->add_database_table('local_campion_subscriptions', [
            'isbn'               => 'privacy:metadata:local_campion_subscriptions:isbn',
            'subscriptionperiod' => 'privacy:metadata:local_campion_subscriptions:subscriptionperiod',
            'status'             => 'privacy:metadata:local_campion_subscriptions:status',
        ], 'privacy:metadata:local_campion_subscriptions');

        $collection->add_database_table('local_campion_logs', [
            'event'  => 'privacy:metadata:local_campion_logs:event',
            'detail' => 'privacy:metadata:local_campion_logs:detail',
        ], 'privacy:metadata:local_campion_logs');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!($context instanceof \context_system)) {
            return;
        }
        $sql = 'SELECT moodleuserid AS id FROM {local_campion_users} WHERE moodleuserid IS NOT NULL';
        $userlist->add_from_sql('id', $sql, []);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        $cu   = $DB->get_record('local_campion_users', ['moodleuserid' => $user->id]);
        if (!$cu) {
            return;
        }
        $context = \context_system::instance();
        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_campion')],
            (object)[
                'email'     => $cu->email,
                'firstname' => $cu->firstname,
                'lastname'  => $cu->lastname,
                'school'    => $cu->school,
                'yearlevel' => $cu->yearlevel,
                'role'      => $cu->role,
            ]
        );
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!($context instanceof \context_system)) {
            return;
        }
        $DB->delete_records('local_campion_logs');
        $DB->delete_records('local_campion_subscriptions');
        $DB->delete_records('local_campion_users');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        $cu   = $DB->get_record('local_campion_users', ['moodleuserid' => $user->id]);
        if (!$cu) {
            return;
        }
        $DB->delete_records('local_campion_subscriptions', ['campionuserid' => $cu->id]);
        $DB->delete_records('local_campion_logs', ['userid' => $user->id]);
        $DB->delete_records('local_campion_users', ['id' => $cu->id]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        foreach ($userlist->get_userids() as $userid) {
            $cu = $DB->get_record('local_campion_users', ['moodleuserid' => $userid]);
            if (!$cu) {
                continue;
            }
            $DB->delete_records('local_campion_subscriptions', ['campionuserid' => $cu->id]);
            $DB->delete_records('local_campion_logs', ['userid' => $userid]);
            $DB->delete_records('local_campion_users', ['id' => $cu->id]);
        }
    }
}
