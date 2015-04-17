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

$id         = required_param('id',PARAM_INT);                           // The anonymous forum to subscribe or unsubscribe to
$returnpage = optional_param('returnpage', 'index.php', PARAM_FILE);    // Page to return to.

$url = new moodle_url('/mod/anonforum/settracking.php', array('id'=>$id));
if ($returnpage !== 'index.php') {
    $url->param('returnpage', $returnpage);
}
$PAGE->set_url($url);

if (! $anonforum = $DB->get_record("anonforum", array("id" => $id))) {
    print_error('invalidanonforumid', 'anonforum');
}

if (! $course = $DB->get_record("course", array("id" => $anonforum->course))) {
    print_error('invalidcoursemodule');
}

if (! $cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $course->id)) {
    print_error('invalidcoursemodule');
}

require_course_login($course, false, $cm);

$returnto = anonforum_go_back_to($returnpage.'?id='.$course->id.'&f='.$anonforum->id);

if (!anonforum_tp_can_track_anonforums($anonforum)) {
    redirect($returnto);
}

$info = new stdClass();
$info->name  = fullname($USER);
$info->anonforum = format_string($anonforum->name);

$eventparams = array(
    'context' => context_module::instance($cm->id),
    'relateduserid' => $USER->id,
    'anonymous' => 1,
    'other' => array('anonforumid' => $anonforum->id),
);

if (anonforum_tp_is_tracked($anonforum) ) {
    if (anonforum_tp_stop_tracking($anonforum->id)) {
        if (empty($anonforum->anonymous)) {
            $event = \mod_anonforum\event\readtracking_disabled::create($eventparams);
            $event->trigger();
        }
        redirect($returnto, get_string("nownottracking", "anonforum", $info), 1);
    } else {
        print_error('cannottrack', '', $_SERVER["HTTP_REFERER"]);
    }

} else { // subscribe
    if (anonforum_tp_start_tracking($anonforum->id)) {
        if (empty($anonforum->anonymous)) {
            $event = \mod_anonforum\event\readtracking_enabled::create($eventparams);
            $event->trigger();
        }
        redirect($returnto, get_string("nowtracking", "anonforum", $info), 1);
    } else {
        print_error('cannottrack', '', $_SERVER["HTTP_REFERER"]);
    }
}


