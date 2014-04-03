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
 * This file is used to display and organise anonforum subscribers
 *
 * @package mod-anonforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$id    = required_param('id',PARAM_INT);           // anonymous forum
$group = optional_param('group',0,PARAM_INT);      // change of group
$edit  = optional_param('edit',-1,PARAM_BOOL);     // Turn editing on and off

$url = new moodle_url('/mod/anonforum/subscribers.php', array('id'=>$id));
if ($group !== 0) {
    $url->param('group', $group);
}
if ($edit !== 0) {
    $url->param('edit', $edit);
}
$PAGE->set_url($url);

$anonforum = $DB->get_record('anonforum', array('id'=>$id), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id'=>$anonforum->course), '*', MUST_EXIST);
if (! $cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id)) {
    $cm->id = 0;
}

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
if (!has_capability('mod/anonforum:viewsubscribers', $context)) {
    print_error('nopermissiontosubscribe', 'anonforum');
}

unset($SESSION->fromdiscussion);

if (empty($anonforum->anonymous)) {
    add_to_log($course->id, "anonforum", "view subscribers", "subscribers.php?id=$anonforum->id", $anonforum->id, $cm->id);
}

$anonforumoutput = $PAGE->get_renderer('mod_anonforum');
$currentgroup = groups_get_activity_group($cm);
$options = array('anonforumid'=>$anonforum->id, 'currentgroup'=>$currentgroup, 'context'=>$context);
$existingselector = new anonforum_existing_subscriber_selector('existingsubscribers', $options);
$subscriberselector = new anonforum_potential_subscriber_selector('potentialsubscribers', $options);
$subscriberselector->set_existing_subscribers($existingselector->find_users(''));

if (data_submitted()) {
    require_sesskey();
    $subscribe = (bool)optional_param('subscribe', false, PARAM_RAW);
    $unsubscribe = (bool)optional_param('unsubscribe', false, PARAM_RAW);
    /** It has to be one or the other, not both or neither */
    if (!($subscribe xor $unsubscribe)) {
        print_error('invalidaction');
    }
    if ($subscribe) {
        $users = $subscriberselector->get_selected_users();
        foreach ($users as $user) {
            if (!anonforum_subscribe($user->id, $id)) {
                print_error('cannotaddsubscriber', 'anonforum', '', $user->id);
            }
        }
    } else if ($unsubscribe) {
        $users = $existingselector->get_selected_users();
        foreach ($users as $user) {
            if (!anonforum_unsubscribe($user->id, $id)) {
                print_error('cannotremovesubscriber', 'anonforum', '', $user->id);
            }
        }
    }
    $subscriberselector->invalidate_selected_users();
    $existingselector->invalidate_selected_users();
    $subscriberselector->set_existing_subscribers($existingselector->find_users(''));
}

$strsubscribers = get_string("subscribers", "anonforum");
$PAGE->navbar->add($strsubscribers);
$PAGE->set_title($strsubscribers);
$PAGE->set_heading($COURSE->fullname);
if (has_capability('mod/anonforum:managesubscriptions', $context)) {
    $PAGE->set_button(anonforum_update_subscriptions_button($course->id, $id));
    if ($edit != -1) {
        $USER->subscriptionsediting = $edit;
    }
} else {
    unset($USER->subscriptionsediting);
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('anonforum', 'anonforum').' '.$strsubscribers);

// If the anonymous forum is anonymous we should never show the subscribers as such information could identify users
if (!empty($anonforum->anonymous)) {
    echo $anonforumoutput->subscribed_anonymous();
} else if (empty($USER->subscriptionsediting)) {
    echo $anonforumoutput->subscriber_overview(anonforum_subscribed_users($course, $anonforum, $currentgroup, $context), $anonforum, $course);
} else if (anonforum_is_forcesubscribed($anonforum)) {
    $subscriberselector->set_force_subscribed(true);
    echo $anonforumoutput->subscribed_users($subscriberselector);
} else {
    echo $anonforumoutput->subscriber_selection_form($existingselector, $subscriberselector);
}
echo $OUTPUT->footer();