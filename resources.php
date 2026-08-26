<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student Campion resources page — lists assigned ISBNs with launch links.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/campion/resources.php'));
$PAGE->set_title(get_string('resources', 'local_campion'));
$PAGE->set_heading(get_string('resources', 'local_campion'));

if (!local_campion_is_enabled()) {
    throw new moodle_exception('notunlocked', 'local_campion');
}

if (!local_campion_check_unlock()) {
    throw new moodle_exception('notunlocked', 'local_campion');
}

global $DB, $USER, $OUTPUT;

// Find the campion_users record for the logged-in user.
$campion_user = $DB->get_record('local_campion_users', ['email' => strtolower($USER->email)]);

$subscriptions = [];
if ($campion_user) {
    $subscriptions = $DB->get_records(
        'local_campion_subscriptions',
        ['campionuserid' => $campion_user->id, 'status' => 'active'],
        'timecreated DESC'
    );
}

echo $OUTPUT->header();

echo html_writer::tag('h2', get_string('resources', 'local_campion'));
echo html_writer::tag('p', get_string('resources_desc', 'local_campion'), ['class' => 'text-muted']);

if (empty($subscriptions)) {
    echo $OUTPUT->notification(get_string('no_resources', 'local_campion'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('col_isbn', 'local_campion'),
        get_string('col_product', 'local_campion'),
        get_string('col_period', 'local_campion'),
        '',
    ];
    $table->attributes = ['class' => 'generaltable table table-striped'];

    $iam_url = local_campion_get_iam_url();

    foreach ($subscriptions as $sub) {
        // Build a launch URL via Publisher-Initiated SSO.
        $launch_url = new moodle_url('/local/campion/launch.php', ['isbn' => $sub->isbn]);

        $table->data[] = [
            html_writer::tag('code', htmlspecialchars($sub->isbn)),
            htmlspecialchars($sub->productname ?: $sub->isbn),
            htmlspecialchars($sub->subscriptionperiod),
            html_writer::link(
                $launch_url,
                get_string('launch_resource', 'local_campion'),
                ['class' => 'btn btn-primary btn-sm', 'target' => '_blank']
            ),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
