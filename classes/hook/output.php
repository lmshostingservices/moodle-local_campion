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

        // The markup core would produce for a native identity provider: a divider,
        // the standard heading, and a full-width button carrying core's own classes,
        // so the site theme styles it exactly as it styles the real ones.
        $block = \html_writer::div('', 'login-divider') .
            \html_writer::tag(
                'h2',
                get_string('potentialidps', 'auth'),
                ['class' => 'login-heading']
            ) .
            \html_writer::link(
                $url,
                get_string('sso_login_button', 'local_campion'),
                ['class' => 'btn login-identityprovider-btn btn-block']
            );

        $html = \html_writer::div(
            $block,
            'login-identityproviders local-campion-loginoption local-campion-unplaced',
            ['id' => 'local-campion-loginoption']
        );

        // This hook is the only server-side insertion point Moodle offers on the
        // login page — core's login template renders standard_top_of_body_html and
        // the main content, and nothing in between — so the markup necessarily
        // starts at the top of <body>, above the header. The script below moves it
        // into the login box, directly beneath the sign-in form, which is where core
        // puts native identity providers.
        //
        // It is hidden until it has been moved so it does not appear at the top of
        // the page first. The class is removed whether or not the move succeeded, so
        // a theme with different markup still shows a working button rather than
        // hiding it; a browser with JavaScript disabled gets the same fallback via
        // the <noscript> rule. Core itself emits inline script on this page, so this
        // introduces no constraint the login page did not already have.
        $html .= <<<HTML
<style>.local-campion-unplaced{display:none}</style>
<noscript><style>.local-campion-unplaced{display:block}</style></noscript>
<script>
(function () {
    var place = function () {
        var opt = document.getElementById('local-campion-loginoption');
        if (!opt) {
            return;
        }
        var form = document.querySelector('.loginform form.login-form')
            || document.querySelector('.login-container form')
            || document.querySelector('#region-main form');
        if (form && form.parentNode) {
            form.parentNode.insertBefore(opt, form.nextSibling);
        }
        opt.className = opt.className.replace(/\s*local-campion-unplaced\s*/, ' ');
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', place);
    } else {
        place();
    }
}());
</script>
HTML;

        $hook->add_html($html);
    }
}
