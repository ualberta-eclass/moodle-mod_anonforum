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
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    require_once('../../config.php');
    require_once('lib.php');
    require_once($CFG->libdir.'/completionlib.php');

    $id          = optional_param('id', 0, PARAM_INT);       // Course Module ID
    $f           = optional_param('f', 0, PARAM_INT);        // Forum ID
    $mode        = optional_param('mode', 0, PARAM_INT);     // Display mode (for single anonymous forum)
    $showall     = optional_param('showall', '', PARAM_INT); // show all discussions on one page
    $changegroup = optional_param('group', -1, PARAM_INT);   // choose the current group
    $page        = optional_param('page', 0, PARAM_INT);     // which page to show
    $search      = optional_param('search', '', PARAM_CLEAN);// search string

    $params = array();
    if ($id) {
        $params['id'] = $id;
    } else {
        $params['f'] = $f;
    }
    if ($page) {
        $params['page'] = $page;
    }
    if ($search) {
        $params['search'] = $search;
    }
    $PAGE->set_url('/mod/anonforum/view.php', $params);

    if ($id) {
        if (! $cm = get_coursemodule_from_id('anonforum', $id)) {
            print_error('invalidcoursemodule');
        }
        if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
            print_error('coursemisconf');
        }
        if (! $anonforum = $DB->get_record("anonforum", array("id" => $cm->instance))) {
            print_error('invalidanonforumid', 'anonforum');
        }
        if ($anonforum->type == 'single') {
            $PAGE->set_pagetype('mod-anonforum-discuss');
        }
        // move require_course_login here to use forced language for course
        // fix for MDL-6926
        require_course_login($course, true, $cm);
        $stranonforums = get_string("modulenameplural", "anonforum");
        $stranonforum = get_string("modulename", "anonforum");
    } else if ($f) {

        if (! $anonforum = $DB->get_record("anonforum", array("id" => $f))) {
            print_error('invalidanonforumid', 'anonforum');
        }
        if (! $course = $DB->get_record("course", array("id" => $anonforum->course))) {
            print_error('coursemisconf');
        }

        if (!$cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $course->id)) {
            print_error('missingparameter');
        }
        // move require_course_login here to use forced language for course
        // fix for MDL-6926
        require_course_login($course, true, $cm);
        $stranonforums = get_string("modulenameplural", "anonforum");
        $stranonforum = get_string("modulename", "anonforum");
    } else {
        print_error('missingparameter');
    }

    if (!$PAGE->button) {
        $PAGE->set_button(anonforum_search_form($course, $search));
    }

    $context = context_module::instance($cm->id);
    $PAGE->set_context($context);

    if (!empty($CFG->enablerssfeeds) && !empty($CFG->anonforum_enablerssfeeds) && $anonforum->rsstype && $anonforum->rssarticles) {
        require_once("$CFG->libdir/rsslib.php");

        $rsstitle = format_string($course->shortname, true, array('context' => context_course::instance($course->id))) . ': ' . format_string($anonforum->name);
        rss_add_http_header($context, 'mod_anonforum', $anonforum, $rsstitle);
    }

    // Mark viewed if required
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);

/// Print header.

    $PAGE->set_title($anonforum->name);
    $PAGE->add_body_class('anonforumtype-'.$anonforum->type);
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();

/// Some capability checks.
    if (empty($cm->visible) and !has_capability('moodle/course:viewhiddenactivities', $context)) {
        notice(get_string("activityiscurrentlyhidden"));
    }

    if (!has_capability('mod/anonforum:viewdiscussion', $context)) {
        notice(get_string('noviewdiscussionspermission', 'anonforum'));
    }

    echo $OUTPUT->heading(format_string($anonforum->name), 2);
    if (!empty($anonforum->intro) && $anonforum->type != 'single' && $anonforum->type != 'teacher') {
        echo $OUTPUT->box(format_module_intro('anonforum', $anonforum, $cm->id), 'generalbox', 'intro');
    }

/// find out current groups mode
    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/anonforum/view.php?id=' . $cm->id);

/// Okay, we can show the discussions. Log the anonymous forum view.
    if (empty($anonforum->anonymous)) {
        if ($cm->id) {
            add_to_log($course->id, "anonforum", "view anonforum", "view.php?id=$cm->id", "$anonforum->id", $cm->id);
        } else {
            add_to_log($course->id, "anonforum", "view anonforum", "view.php?f=$anonforum->id", "$anonforum->id");
        }
    }

    $SESSION->fromdiscussion = qualified_me();   // Return here if we post or set subscription etc


/// Print settings and things across the top

    // If it's a simple single discussion anonymous forum, we need to print the display
    // mode control.
    if ($anonforum->type == 'single') {
        $discussion = NULL;
        $discussions = $DB->get_records('anonforum_discussions', array('anonforum'=>$anonforum->id), 'timemodified ASC');
        if (!empty($discussions)) {
            $discussion = array_pop($discussions);
        }
        if ($discussion) {
            if ($mode) {
                set_user_preference("anonforum_displaymode", $mode);
            }
            $displaymode = get_user_preferences("anonforum_displaymode", $CFG->anonforum_displaymode);
            anonforum_print_mode_form($anonforum->id, $displaymode, $anonforum->type);
        }
    }

    if (!empty($anonforum->blockafter) && !empty($anonforum->blockperiod)) {
        $a = new stdClass();
        $a->blockafter = $anonforum->blockafter;
        $a->blockperiod = get_string('secondstotime'.$anonforum->blockperiod);
        echo $OUTPUT->notification(get_string('thisanonforumisthrottled', 'anonforum', $a));
    }

    if ($anonforum->type == 'qanda' && !has_capability('moodle/course:manageactivities', $context)) {
        echo $OUTPUT->notification(get_string('qandanotify','anonforum'));
    }

    switch ($anonforum->type) {
        case 'single':
            if (!empty($discussions) && count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'anonforum'));
            }
            if (! $post = anonforum_get_post_full($discussion->firstpost)) {
                print_error('cannotfindfirstpost', 'anonforum');
            }
            if ($mode) {
                set_user_preference("anonforum_displaymode", $mode);
            }

            $canreply    = anonforum_user_can_post($anonforum, $discussion, $USER, $cm, $course, $context);
            $canrate     = has_capability('mod/anonforum:rate', $context);
            $displaymode = get_user_preferences("anonforum_displaymode", $CFG->anonforum_displaymode);

            echo '&nbsp;'; // this should fix the floating in FF
            anonforum_print_discussion($course, $cm, $anonforum, $discussion, $post, $displaymode, $canreply, $canrate);
            break;

        case 'eachuser':
            echo '<p class="mdl-align">';
            if (anonforum_user_can_post_discussion($anonforum, null, -1, $cm)) {
                print_string("allowsdiscussions", "anonforum");
            } else {
                echo '&nbsp;';
            }
            echo '</p>';
            if (!empty($showall)) {
                anonforum_print_latest_discussions($course, $anonforum, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                anonforum_print_latest_discussions($course, $anonforum, -1, 'header', '', -1, -1, $page, $CFG->anonforum_manydiscussions, $cm);
            }
            break;

        case 'teacher':
            if (!empty($showall)) {
                anonforum_print_latest_discussions($course, $anonforum, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                anonforum_print_latest_discussions($course, $anonforum, -1, 'header', '', -1, -1, $page, $CFG->anonforum_manydiscussions, $cm);
            }
            break;

        case 'blog':
            echo '<br />';
            if (!empty($showall)) {
                anonforum_print_latest_discussions($course, $anonforum, 0, 'plain', '', -1, -1, -1, 0, $cm);
            } else {
                anonforum_print_latest_discussions($course, $anonforum, -1, 'plain', '', -1, -1, $page, $CFG->anonforum_manydiscussions, $cm);
            }
            break;

        default:
            echo '<br />';
            if (!empty($showall)) {
                anonforum_print_latest_discussions($course, $anonforum, 0, 'header', '', -1, -1, -1, 0, $cm);
            } else {
                anonforum_print_latest_discussions($course, $anonforum, -1, 'header', '', -1, -1, $page, $CFG->anonforum_manydiscussions, $cm);
            }


            break;
    }

    echo $OUTPUT->footer($course);


