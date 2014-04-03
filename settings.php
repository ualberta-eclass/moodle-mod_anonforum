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
 * @package mod-anonforum
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/mod/anonforum/lib.php');

    $settings->add(new admin_setting_configselect('anonforum_displaymode', get_string('displaymode', 'anonforum'),
                       get_string('configdisplaymode', 'anonforum'), ANONFORUM_MODE_NESTED, anonforum_get_layout_modes()));

    $settings->add(new admin_setting_configcheckbox('anonforum_replytouser', get_string('replytouser', 'anonforum'),
                       get_string('configreplytouser', 'anonforum'), 1));

    // Less non-HTML characters than this is short
    $settings->add(new admin_setting_configtext('anonforum_shortpost', get_string('shortpost', 'anonforum'),
                       get_string('configshortpost', 'anonforum'), 300, PARAM_INT));

    // More non-HTML characters than this is long
    $settings->add(new admin_setting_configtext('anonforum_longpost', get_string('longpost', 'anonforum'),
                       get_string('configlongpost', 'anonforum'), 600, PARAM_INT));

    // Number of discussions on a page
    $settings->add(new admin_setting_configtext('anonforum_manydiscussions', get_string('manydiscussions', 'anonforum'),
                       get_string('configmanydiscussions', 'anonforum'), 100, PARAM_INT));

    if (isset($CFG->maxbytes)) {
        $maxbytes = 0;
        if (isset($CFG->anonforum_maxbytes)) {
            $maxbytes = $CFG->anonforum_maxbytes;
        }
        $settings->add(new admin_setting_configselect('anonforum_maxbytes', get_string('maxattachmentsize', 'anonforum'),
                           get_string('configmaxbytes', 'anonforum'), 512000, get_max_upload_sizes($CFG->maxbytes, 0, 0, $maxbytes)));
    }

    // Default number of attachments allowed per post in all anonymous forums
    $settings->add(new admin_setting_configtext('anonforum_maxattachments', get_string('maxattachments', 'anonforum'),
                       get_string('configmaxattachments', 'anonforum'), 9, PARAM_INT));

    // Default Read Tracking setting.
    $options = array();
    $options[ANONFORUM_TRACKING_OPTIONAL] = get_string('trackingoptional', 'anonforum');
    $options[ANONFORUM_TRACKING_OFF] = get_string('trackingoff', 'anonforum');
    $options[ANONFORUM_TRACKING_FORCED] = get_string('trackingon', 'anonforum');
    $settings->add(new admin_setting_configselect('anonforum_trackingtype', get_string('trackingtype', 'anonforum'),
                       get_string('configtrackingtype', 'anonforum'), ANONFORUM_TRACKING_OPTIONAL, $options));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('anonforum_trackreadposts', get_string('trackanonforum', 'anonforum'),
                       get_string('configtrackreadposts', 'anonforum'), 1));

    // Default whether user needs to mark a post as read.
    $settings->add(new admin_setting_configcheckbox('anonforum_allowforcedreadtracking', get_string('forcedreadtracking', 'anonforum'),
                       get_string('forcedreadtracking_desc', 'anonforum'), 0));

    // Default number of days that a post is considered old
    $settings->add(new admin_setting_configtext('anonforum_oldpostdays', get_string('oldpostdays', 'anonforum'),
                       get_string('configoldpostdays', 'anonforum'), 14, PARAM_INT));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('anonforum_usermarksread', get_string('usermarksread', 'anonforum'),
                       get_string('configusermarksread', 'anonforum'), 0));

    $options = array();
    for ($i = 0; $i < 24; $i++) {
        $options[$i] = sprintf("%02d",$i);
    }
    // Default time (hour) to execute 'clean_read_records' cron
    $settings->add(new admin_setting_configselect('anonforum_cleanreadtime', get_string('cleanreadtime', 'anonforum'),
                       get_string('configcleanreadtime', 'anonforum'), 2, $options));

    // Default time (hour) to send digest email
    $settings->add(new admin_setting_configselect('digestmailtime', get_string('digestmailtime', 'anonforum'),
                       get_string('configdigestmailtime', 'anonforum'), 17, $options));

    if (empty($CFG->enablerssfeeds)) {
        $options = array(0 => get_string('rssglobaldisabled', 'admin'));
        $str = get_string('configenablerssfeeds', 'anonforum').'<br />'.get_string('configenablerssfeedsdisabled2', 'admin');

    } else {
        $options = array(0=>get_string('no'), 1=>get_string('yes'));
        $str = get_string('configenablerssfeeds', 'anonforum');
    }
    $settings->add(new admin_setting_configselect('anonforum_enablerssfeeds', get_string('enablerssfeeds', 'admin'),
                       $str, 0, $options));

    $settings->add(new admin_setting_configcheckbox('anonforum_enabletimedposts', get_string('timedposts', 'anonforum'),
                       get_string('configenabletimedposts', 'anonforum'), 0));
}

