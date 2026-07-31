<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Language strings for local_campion.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']               = 'Campion Integration';
$string['pluginname_desc']          = 'Integrates Campion Education IAM Single Sign-On and publisher provisioning API with Moodle.';

// Settings.
$string['settings_heading']         = 'Campion Integration Settings';
$string['settings_heading_desc']    = 'Configure your Campion IAM OAuth 2.0 credentials and provisioning API settings. Contact Campion Education to obtain these values.';
$string['client_id']                = 'OAuth 2.0 Client ID';
$string['client_id_desc']           = 'The Client ID provided by Campion IAM for your Moodle installation.';
$string['client_secret']            = 'OAuth 2.0 Client Secret';
$string['client_secret_desc']       = 'The Client Secret provided by Campion IAM. Keep this confidential.';
$string['iam_url']                  = 'Campion IAM URL';
$string['iam_url_desc']             = 'The base URL of the Campion IAM platform (e.g. https://iam.campion.com.au).';
$string['api_key']                  = 'Provisioning API Key';
$string['api_key_desc']             = 'The API key Campion uses to authenticate provisioning calls (CreateUser, CreateSubscription, etc.).';
$string['enabled']                  = 'Enable Campion Integration';
$string['enabled_desc']             = 'Globally enable or disable the Campion integration. Disabling this stops all SSO redirects and provisioning API calls.';
$string['acara_id']                 = 'School ACARA ID';
$string['acara_id_desc']            = 'Your school\'s ACARA ID (Australian Curriculum and Reporting Authority identifier). Used to match your school in the Campion platform.';
$string['sso_endpoint']             = 'SSO Callback Endpoint';
$string['sso_endpoint_desc']        = 'Share this URL with Campion as your OAuth 2.0 redirect URI for IAM-initiated SSO.';
$string['provision_endpoint']       = 'Provisioning API Endpoint';
$string['provision_endpoint_desc']  = 'Share this URL with Campion for automated account provisioning (CreateUser, CreateSubscription, etc.).';

// Credit unlock.
$string['notunlocked']              = 'Campion Integration is not activated. Please unlock it from the AI Grader Plugin Manager.';
$string['unlockplugin']             = 'Unlock Campion Integration';

// Manage page.
$string['manage']                   = 'Campion Integration Manager';
$string['manage_heading']           = 'Campion Integration Manager';
$string['manage_desc']              = 'Manage Campion Education SSO and provisioning from this page.';
$string['tab_overview']             = 'Overview';
$string['tab_users']                = 'Provisioned Users';
$string['tab_subscriptions']        = 'Subscriptions';
$string['tab_products']             = 'Products / ISBNs';
$string['tab_logs']                 = 'Activity Log';
$string['no_users']                 = 'No Campion users have been provisioned yet.';
$string['no_subscriptions']         = 'No subscriptions found.';
$string['no_products']              = 'No products registered yet.';
$string['no_logs']                  = 'No activity logged yet.';
$string['col_email']                = 'Email';
$string['col_firstname']            = 'First Name';
$string['col_lastname']             = 'Last Name';
$string['col_school']               = 'School';
$string['col_yearlevel']            = 'Year Level';
$string['col_role']                 = 'Role';
$string['col_isbn']                 = 'ISBN';
$string['col_product']              = 'Product';
$string['col_status']               = 'Status';
$string['col_period']               = 'Period';
$string['col_timecreated']          = 'Created';
$string['col_event']                = 'Event';
$string['col_detail']               = 'Detail';
$string['status_active']            = 'Active';
$string['status_inactive']          = 'Inactive';
$string['status_student']           = 'Student';
$string['status_teacher']           = 'Teacher';

// SSO page.
$string['sso_invalid_token']        = 'Invalid or expired authentication token from Campion IAM. Please try again.';
$string['sso_token_replayed']       = 'This authentication token has already been used. Please return to Campion and try again.';
$string['sso_no_email']             = 'No email address was provided in the Campion authentication token.';
$string['sso_user_not_found']       = 'Your account was not found in this Moodle installation. Please contact your administrator.';
$string['sso_login_error']          = 'An error occurred during Campion SSO login. Please try again.';

// Resources page.
$string['resources']                = 'My Campion Resources';
$string['resources_desc']           = 'Click any resource below to launch it directly in Campion myConnect using your school login.';
$string['no_resources']             = 'No Campion resources have been assigned to your account.';
$string['launch_resource']          = 'Launch Resource';

// API responses.
$string['api_success']              = 'Success';
$string['api_user_exists']          = 'User already exists';
$string['api_user_not_found']       = 'User not found';
$string['api_product_not_found']    = 'Product not found';
$string['api_invalid_key']          = 'Invalid API key';
$string['api_missing_params']       = 'Missing required parameters';

// Privacy.
$string['privacy:metadata:local_campion_users']                        = 'Information about Campion-provisioned users.';
$string['privacy:metadata:local_campion_users:email']                  = 'User email address.';
$string['privacy:metadata:local_campion_users:firstname']              = 'User first name.';
$string['privacy:metadata:local_campion_users:lastname']               = 'User last name.';
$string['privacy:metadata:local_campion_users:school']                 = 'School name.';
$string['privacy:metadata:local_campion_users:yearlevel']              = 'Year level.';
$string['privacy:metadata:local_campion_users:role']                   = 'Role (student/teacher).';
$string['privacy:metadata:local_campion_subscriptions']                = 'Campion resource subscription records.';
$string['privacy:metadata:local_campion_subscriptions:isbn']           = 'ISBN of the subscribed resource.';
$string['privacy:metadata:local_campion_subscriptions:subscriptionperiod'] = 'Subscription period.';
$string['privacy:metadata:local_campion_subscriptions:status']         = 'Subscription status.';
$string['privacy:metadata:local_campion_logs']                         = 'Activity log for Campion SSO and provisioning events.';
$string['privacy:metadata:local_campion_logs:event']                   = 'Event type.';
$string['privacy:metadata:local_campion_logs:detail']                  = 'Event detail.';
