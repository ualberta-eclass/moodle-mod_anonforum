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
 * Set tracking option for the anonforum.
 *
 * @package mod-anonforum
 * @copyright 2005 mchurch
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$f          = required_param('f',PARAM_INT); // The anonymous forum to mark
$mark       = required_param('mark',PARAM_ALPHA); // Read or unread?
$d          = optional_param('d',0,PARAM_INT); // Discussion to mark.
$returnpage = optional_param('returnpage', 'index.php', PARAM_FILE);    // Page to return to.

$url = new moodle_url('/mod/anonforum/markposts.php', array('f'=>$f, 'mark'=>$mark));
if ($d !== 0) {
    $url->param('d', $d);
}
if ($returnpage !== 'index.php') {
    $url->param('returnpage', $returnpage);
}
$PAGE->set_url($url);

if (! $anonforum = $DB->get_record("anonforum", array("id" => $f))) {
    print_error('invalidanonforumid', 'anonforum');
}

if (! $course = $DB->get_record("course", array("id" => $anonforum->course))) {
    print_error('invalidcourseid');
}

if (!$cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $course->id)) {
    print_error('invalidcoursemodule');
}

$user = $USER;

require_login($course, false, $cm);

if ($returnpage == 'index.php') {
    $returnto = anonforum_go_back_to($returnpage.'?id='.$course->id);
} else {
    $returnto = anonforum_go_back_to($returnpage.'?f='.$anonforum->id);
}

if (isguestuser()) {   // Guests can't change anonforum
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguesttracking', 'anonforum').'<br /><br />'.get_string('liketologin'), get_login_url(), $returnto);
    echo $OUTPUT->footer();
    exit;
}

$info = new stdClass();
$info->name  = fullname($user);
$info->anonforum = format_string($anonforum->name);

if ($mark == 'read') {
    if (!empty($d)) {
        if (! $discussion = $DB->get_record('anonforum_discussions', array('id'=> $d, 'anonforum'=> $anonforum->id))) {
            print_error('invaliddiscussionid', 'anonforum');
        }

        if (anonforum_tp_mark_discussion_read($user, $d) && empty($anonforum->anonymous)) {
            add_to_log($course->id, "discussion", "mark read", "view.php?f=$anonforum->id", $d, $cm->id);
        }
    } else {
        // Mark all messages read in current group
        $currentgroup = groups_get_activity_group($cm);
        if(!$currentgroup) {
            // mark_anonymous forum_read requires ===false, while get_activity_group
            // may return 0
            $currentgroup=false;
        }
        if (anonforum_tp_mark_anonforum_read($user, $anonforum->id,$currentgroup) && empty($anonforum->anonymous)) {
            add_to_log($course->id, "anonforum", "mark read", "view.php?f=$anonforum->id", $anonforum->id, $cm->id);
        }
    }

/// FUTURE - Add ability to mark them as unread.
//    } else { // subscribe
//        if (anonforum_tp_start_tracking($anonymous forum->id, $user->id)) {
//            add_to_log($course->id, "anonforum", "mark unread", "view.php?f=$anonforum->id", $anonymous forum->id, $cm->id);
//            redirect($returnto, get_string("nowtracking", "anonymous forum", $info), 1);
//        } else {
//            print_error("Could not start tracking that anonymous forum", $_SERVER["HTTP_REFERER"]);
//        }
}

redirect($returnto);

