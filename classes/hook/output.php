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

namespace local_campion\hook;

/**
 * Output hook callbacks.
 *
 * Moodle builds the "Log in using your account on:" list on its login page from
 * auth plugins only — core asks each plugin in the enabled auth sequence for
 * loginpage_idp_list(), and a local plugin is never asked. Rather than ship a
 * second component purely to contribute one button, this adds the option through
 * the standard output hook, styled with core's own login button classes so it
 * matches whatever the site theme does with the native providers.
 *
 * @package   local_campion
 * @copyright 2026 AI Grader
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class output {

    /**
     * Add a "Log in with Campion" option to the Moodle login page.
     *
     * Kept deliberately cheap: it runs on every page render, so it bails on the
     * page type first and reads only local configuration. No licence check and no
     * network call happen here — those belong on login.php, once the visitor has
     * actually chosen to sign in with Campion.
     *
     * @param  \core\hook\output\before_standard_top_of_body_html_generation $hook
     * @return void
     */
    public static function add_login_button(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $PAGE, $CFG;

        if (during_initial_install()) {
            return;
        }

        // A local plugin's lib.php is not loaded on every request, so the helpers
        // below are not guaranteed to be defined yet when this hook fires.
        require_once($CFG->dirroot . '/local/campion/lib.php');

        // The login form only. Not the signup, forgotten-password or OTP pages,
        // which share the 'login' page layout.
        if ($PAGE->pagetype !== 'login-index') {
            return;
        }

        if (!local_campion_is_enabled() || !local_campion_login_button_enabled()) {
            return;
        }

        if (!local_campion_sso_is_configured()) {
            return;
        }

        $url = new \moodle_url('/local/campion/login.php');

        $html = \html_writer::div(
            \html_writer::tag(
                'h2',
                get_string('potentialidps', 'auth'),
                ['class' => 'login-heading']
            ) .
            \html_writer::link(
                $url,
                get_string('sso_login_button', 'local_campion'),
                ['class' => 'btn login-identityprovider-btn btn-block btn-secondary']
            ),
            'login-identityproviders local-campion-loginoption mx-auto my-3',
            ['style' => 'max-width: 26rem;']
        );

        $hook->add_html($html);
    }
}
