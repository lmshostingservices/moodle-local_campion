<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin management page for local_campion.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_campion_manage');

$tab = optional_param('tab', 'overview', PARAM_ALPHANUMEXT);

global $DB, $OUTPUT, $CFG;

// ── Action: register or update a product ─────────────────────────
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'save_product' && confirm_sesskey()) {
    $isbn        = required_param('isbn', PARAM_ALPHANUMEXT);
    $productname = optional_param('productname', '', PARAM_TEXT);
    $status      = optional_param('status', 'active', PARAM_ALPHANUMEXT);
    $now         = time();

    $existing = $DB->get_record('local_campion_products', ['isbn' => $isbn]);
    if ($existing) {
        $existing->productname  = $productname;
        $existing->status       = $status;
        $existing->timemodified = $now;
        $DB->update_record('local_campion_products', $existing);
    } else {
        $DB->insert_record('local_campion_products', (object)[
            'isbn'        => $isbn,
            'productname' => $productname,
            'status'      => $status,
            'timecreated' => $now,
            'timemodified'=> $now,
        ]);
    }
    redirect(new moodle_url('/local/campion/manage.php', ['tab' => 'products']));
}

// ── Credit-unlock gate display ────────────────────────────────────
$unlocked = local_campion_check_unlock();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_heading', 'local_campion'));

if (!$unlocked) {
    echo $OUTPUT->notification(get_string('notunlocked', 'local_campion'), 'error');
    echo $OUTPUT->footer();
    exit;
}

// ── Tab navigation ────────────────────────────────────────────────
$tabs = [
    'overview'      => get_string('tab_overview',      'local_campion'),
    'users'         => get_string('tab_users',         'local_campion'),
    'subscriptions' => get_string('tab_subscriptions', 'local_campion'),
    'products'      => get_string('tab_products',      'local_campion'),
    'logs'          => get_string('tab_logs',          'local_campion'),
];

$tabrow = [];
foreach ($tabs as $t => $label) {
    $url      = new moodle_url('/local/campion/manage.php', ['tab' => $t]);
    $tabrow[] = new tabobject($t, $url, $label);
}
echo $OUTPUT->tabtree($tabrow, $tab);

// ─────────────────────────────────────────────────────────────────
// Tab content
// ─────────────────────────────────────────────────────────────────

if ($tab === 'overview') {

    $num_users = $DB->count_records('local_campion_users');
    $num_subs  = $DB->count_records('local_campion_subscriptions', ['status' => 'active']);
    $num_prods = $DB->count_records('local_campion_products', ['status' => 'active']);

    $client_id  = local_campion_get_client_id();
    $iam_url    = local_campion_get_iam_url();
    $acara_id   = local_campion_get_acara_id();
    $sso_url    = $CFG->wwwroot . '/local/campion/sso.php';
    $api_url    = $CFG->wwwroot . '/local/campion/api.php';
    $configured = !empty($client_id) && !empty(local_campion_get_client_secret());

    echo html_writer::start_tag('div', ['class' => 'row mb-4']);
    foreach ([
        ['Users Provisioned', $num_users, 'primary'],
        ['Active Subscriptions', $num_subs, 'success'],
        ['Active Products', $num_prods, 'info'],
    ] as [$label, $val, $colour]) {
        echo html_writer::start_tag('div', ['class' => 'col-md-4']);
        echo html_writer::start_tag('div', ['class' => "card border-$colour mb-3"]);
        echo html_writer::start_tag('div', ['class' => "card-body text-$colour"]);
        echo html_writer::tag('h5', $label, ['class' => 'card-title']);
        echo html_writer::tag('h2', $val);
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');

    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-header']);
    echo html_writer::tag('h5', 'Configuration Status', ['class' => 'm-0']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    $badge = $configured
        ? html_writer::tag('span', 'Configured', ['class' => 'badge badge-success'])
        : html_writer::tag('span', 'Incomplete — set Client ID and Secret in Settings', ['class' => 'badge badge-warning']);
    echo html_writer::tag('p', 'OAuth credentials: ' . $badge);
    echo html_writer::tag('p', 'IAM URL: <code>' . htmlspecialchars($iam_url) . '</code>');
    $distinctacara = $DB->count_records_select('local_campion_users', "acaraid IS NOT NULL AND acaraid <> ''");
    echo html_writer::tag('p', 'Default ACARA ID: <code>' . htmlspecialchars($acara_id ?: '(not set — sent per user)') . '</code>');
    echo html_writer::tag('p', 'Users with an ACARA ID: <code>' . $distinctacara . ' / ' . $num_users . '</code>');
    echo html_writer::tag('p', 'SSO Callback URL (share with Campion):');
    echo html_writer::tag('pre', htmlspecialchars($sso_url), ['class' => 'bg-light p-2']);
    echo html_writer::tag('p', 'Provisioning API URL (share with Campion):');
    echo html_writer::tag('pre', htmlspecialchars($api_url), ['class' => 'bg-light p-2']);
    $settings_url = new moodle_url('/admin/settings.php', ['section' => 'local_campion']);
    echo html_writer::link($settings_url, 'Go to Settings', ['class' => 'btn btn-outline-primary btn-sm']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

} elseif ($tab === 'users') {

    $users = $DB->get_records('local_campion_users', null, 'timecreated DESC', '*', 0, 200);
    if (empty($users)) {
        echo $OUTPUT->notification(get_string('no_users', 'local_campion'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('col_email',       'local_campion'),
            get_string('col_firstname',   'local_campion'),
            get_string('col_lastname',    'local_campion'),
            get_string('col_school',      'local_campion'),
            get_string('col_acaraid',     'local_campion'),
            get_string('col_yearlevel',   'local_campion'),
            get_string('col_role',        'local_campion'),
            get_string('col_timecreated', 'local_campion'),
        ];
        $table->attributes = ['class' => 'generaltable table table-sm'];
        foreach ($users as $u) {
            $table->data[] = [
                htmlspecialchars($u->email),
                htmlspecialchars($u->firstname),
                htmlspecialchars($u->lastname),
                htmlspecialchars($u->school),
                htmlspecialchars($u->acaraid ?? ''),
                htmlspecialchars($u->yearlevel),
                htmlspecialchars($u->role),
                userdate($u->timecreated),
            ];
        }
        echo html_writer::table($table);
    }

} elseif ($tab === 'subscriptions') {

    $sql = 'SELECT s.*, u.email, u.firstname, u.lastname
              FROM {local_campion_subscriptions} s
              JOIN {local_campion_users} u ON u.id = s.campionuserid
          ORDER BY s.timecreated DESC
             LIMIT 500';
    $subs = $DB->get_records_sql($sql);

    if (empty($subs)) {
        echo $OUTPUT->notification(get_string('no_subscriptions', 'local_campion'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('col_email',   'local_campion'),
            get_string('col_isbn',    'local_campion'),
            get_string('col_product', 'local_campion'),
            get_string('col_period',  'local_campion'),
            get_string('col_status',  'local_campion'),
            get_string('col_timecreated', 'local_campion'),
        ];
        $table->attributes = ['class' => 'generaltable table table-sm'];
        foreach ($subs as $s) {
            $badge_class = ($s->status === 'active') ? 'badge-success' : 'badge-secondary';
            $table->data[] = [
                htmlspecialchars($s->email),
                html_writer::tag('code', htmlspecialchars($s->isbn)),
                htmlspecialchars($s->productname),
                htmlspecialchars($s->subscriptionperiod),
                html_writer::tag('span', htmlspecialchars($s->status), ['class' => 'badge ' . $badge_class]),
                userdate($s->timecreated),
            ];
        }
        echo html_writer::table($table);
    }

} elseif ($tab === 'products') {

    // Add product form.
    echo html_writer::start_tag('div', ['class' => 'card mb-4']);
    echo html_writer::start_tag('div', ['class' => 'card-header']);
    echo html_writer::tag('h5', 'Register Product ISBN', ['class' => 'm-0']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'save_product']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab',     'value' => 'products']);
    echo '<div class="form-group"><label>ISBN / Product Code</label>';
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'isbn', 'class' => 'form-control', 'placeholder' => 'e.g. CAMFTG00078ST', 'required' => 'required']);
    echo '</div>';
    echo '<div class="form-group"><label>Product Name</label>';
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'productname', 'class' => 'form-control', 'placeholder' => 'e.g. Year 7/8 Student Resources']);
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">Save Product</button>';
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    $products = $DB->get_records('local_campion_products', null, 'isbn ASC');
    if (empty($products)) {
        echo $OUTPUT->notification(get_string('no_products', 'local_campion'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('col_isbn', 'local_campion'),
            get_string('col_product', 'local_campion'),
            get_string('col_status', 'local_campion'),
        ];
        $table->attributes = ['class' => 'generaltable table table-sm'];
        foreach ($products as $p) {
            $badge_class = ($p->status === 'active') ? 'badge-success' : 'badge-secondary';
            $table->data[] = [
                html_writer::tag('code', htmlspecialchars($p->isbn)),
                htmlspecialchars($p->productname),
                html_writer::tag('span', htmlspecialchars($p->status), ['class' => 'badge ' . $badge_class]),
            ];
        }
        echo html_writer::table($table);
    }

} elseif ($tab === 'logs') {

    $logs = $DB->get_records('local_campion_logs', null, 'timecreated DESC', '*', 0, 300);
    if (empty($logs)) {
        echo $OUTPUT->notification(get_string('no_logs', 'local_campion'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            'Time',
            get_string('col_email',  'local_campion'),
            get_string('col_event',  'local_campion'),
            get_string('col_detail', 'local_campion'),
            'IP',
        ];
        $table->attributes = ['class' => 'generaltable table table-sm'];
        foreach ($logs as $log) {
            $table->data[] = [
                userdate($log->timecreated),
                htmlspecialchars($log->email ?? ''),
                html_writer::tag('code', htmlspecialchars($log->event)),
                htmlspecialchars($log->detail ?? ''),
                htmlspecialchars($log->ip ?? ''),
            ];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
