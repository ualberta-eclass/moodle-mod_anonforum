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
 * Displays a post, and all the posts below it.
 * If no post is given, displays all posts in a discussion
 *
 * @package mod-anonforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$d      = required_param('d', PARAM_INT);                // Discussion ID
$parent = optional_param('parent', 0, PARAM_INT);        // If set, then display this post and all children.
$mode   = optional_param('mode', 0, PARAM_INT);          // If set, changes the layout of the thread
$move   = optional_param('move', 0, PARAM_INT);          // If set, moves this discussion to another  anonymous forum
$mark   = optional_param('mark', '', PARAM_ALPHA);       // Used for tracking read posts if user initiated.
$postid = optional_param('postid', 0, PARAM_INT);        // Used for tracking read posts if user initiated.

$url = new moodle_url('/mod/anonforum/discuss.php', array('d'=>$d));
if ($parent !== 0) {
    $url->param('parent', $parent);
}
$PAGE->set_url($url);

$discussion = $DB->get_record('anonforum_discussions', array('id' => $d), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $discussion->course), '*', MUST_EXIST);
$anonforum = $DB->get_record('anonforum', array('id' => $discussion->anonforum), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id, false, MUST_EXIST);

require_course_login($course, true, $cm);

// move this down fix for MDL-6926
require_once($CFG->dirroot.'/mod/anonforum/lib.php');

$modcontext = context_module::instance($cm->id);
require_capability('mod/anonforum:viewdiscussion', $modcontext, NULL, true, 'noviewdiscussionspermission', 'anonforum');

if (!empty($CFG->enablerssfeeds) && !empty($CFG->anonforum_enablerssfeeds) && $anonforum->rsstype && $anonforum->rssarticles) {
    require_once("$CFG->libdir/rsslib.php");

    $rsstitle = format_string($course->shortname, true, array('context' => context_course::instance($course->id))) . ': ' . format_string($anonforum->name);
    rss_add_http_header($modcontext, 'mod_anonforum', $anonforum, $rsstitle);
}

// move discussion if requested
if ($move > 0 and confirm_sesskey()) {
    $return = $CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$discussion->id;

    require_capability('mod/anonforum:movediscussions', $modcontext);

    if ($anonforum->type == 'single') {
        print_error('cannotmovefromsingleanonforum', 'anonforum', $return);
    }

    if (!$anonforumto = $DB->get_record('anonforum', array('id' => $move))) {
        print_error('cannotmovetonotexist', 'anonforum', $return);
    }

    if ($anonforumto->type == 'single') {
        print_error('cannotmovetosingleanonforum', 'anonforum', $return);
    }

    if (!$cmto = get_coursemodule_from_instance('anonforum', $anonforumto->id, $course->id)) {
        print_error('cannotmovetonotfound', 'anonforum', $return);
    }

    if(!\core_availability\info_module::is_user_visible($cm, $userid, false)) {
        print_error('cannotmovenotvisible', 'anonforum', $return);
    }

    require_capability('mod/anonforum:startdiscussion', context_module::instance($cmto->id));

    if (!anonforum_move_attachments($discussion, $anonforum->id, $anonforumto->id)) {
        echo $OUTPUT->notification("Errors occurred while moving attachment directories - check your file permissions");
    }
    $DB->set_field('anonforum_discussions', 'anonforum', $anonforumto->id, array('id' => $discussion->id));
    $DB->set_field('anonforum_read', 'anonforumid', $anonforumto->id, array('discussionid' => $discussion->id));

    $params = array(
        'context' => $destinationctx,
        'objectid' => $discussion->id,
        'anonymous' => 1,
        'other' => array(
            'fromanonforumid' => $anonforum->id,
            'toanonforumid' => $forumto->id
        )
    );
    $event = \mod_anonforum\event\discussion_moved::create($params);
    $event->add_record_snapshot('anonforum_discussions', $discussion);
    $event->add_record_snapshot('anonforum', $anonforum);
    $event->add_record_snapshot('anonforum', $forumto);
    $event->trigger();

    // Delete the RSS files for the 2  anonymous forums to force regeneration of the feeds
    require_once($CFG->dirroot.'/mod/anonforum/rsslib.php');
    anonforum_rss_delete_file($anonforum);
    anonforum_rss_delete_file($anonforumto);

    redirect($return.'&moved=-1&sesskey='.sesskey());
}

if(empty($anonforum->anonymous)) {
    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
        'anonymous' => 1
    );
    $event = \mod_anonforum\event\discussion_viewed::create($params);
    $event->add_record_snapshot('anonforum_discussions', $discussion);
    $event->add_record_snapshot('anonforum', $anonforum);
    $event->trigger();
}

unset($SESSION->fromdiscussion);

if ($mode) {
    set_user_preference('anonforum_displaymode', $mode);
}

$displaymode = get_user_preferences('anonforum_displaymode', $CFG->anonforum_displaymode);

if ($parent) {
    // If flat AND parent, then force nested display this time
    if ($displaymode == ANONFORUM_MODE_FLATOLDEST or $displaymode == ANONFORUM_MODE_FLATNEWEST) {
        $displaymode = ANONFORUM_MODE_NESTED;
    }
} else {
    $parent = $discussion->firstpost;
}

if (! $post = anonforum_get_post_full($parent)) {
    print_error("notexists", 'anonforum', "$CFG->wwwroot/mod/anonforum/view.php?f=$anonforum->id");
}

if (!anonforum_user_can_see_post($anonforum, $discussion, $post, null, $cm)) {
    print_error('noviewdiscussionspermission', 'anonforum', "$CFG->wwwroot/mod/anonforum/view.php?id=$anonforum->id");
}

if ($mark == 'read' or $mark == 'unread') {
    if ($CFG->anonforum_usermarksread && anonforum_tp_can_track_anonforums($anonforum) && anonforum_tp_is_tracked($anonforum)) {
        if ($mark == 'read') {
            anonforum_tp_add_read_record($USER->id, $postid);
        } else {
            // unread
            anonforum_tp_delete_read_records($USER->id, $postid);
        }
    }
}

$searchform = forum_search_form($course);

$anonforumnode = $PAGE->navigation->find($cm->id, navigation_node::TYPE_ACTIVITY);
if (empty($anonforumnode)) {
    $anonforumnode = $PAGE->navbar;
} else {
    $anonforumnode->make_active();
}
$node = $anonforumnode->add(format_string($discussion->name), new moodle_url('/mod/anonforum/discuss.php', array('d'=>$discussion->id)));
$node->display = false;
if ($node && $post->id != $discussion->firstpost) {
    $node->add(format_string($post->subject), $PAGE->url);
}

$PAGE->set_title("$course->shortname: ".format_string($discussion->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($anonforum->name), 2);

/// Check to see if groups are being used in this anonymous forum
/// If so, make sure the current person is allowed to see this discussion
/// Also, if we know they should be able to reply, then explicitly set $canreply for performance reasons

$canreply = anonforum_user_can_post($anonforum, $discussion, $USER, $cm, $course, $modcontext);
if (!$canreply and $anonforum->type !== 'news') {
    if (isguestuser() or !isloggedin()) {
        $canreply = true;
    }
    if (!is_enrolled($modcontext) and !is_viewing($modcontext)) {
        // allow guests and not-logged-in to see the link - they are prompted to log in after clicking the link
        // normal users with temporary guest access see this link too, they are asked to enrol instead
        $canreply = enrol_selfenrol_available($course->id);
    }
}

/// Print the controls across the top
echo '<div class="discussioncontrols clearfix">';

if (!empty($CFG->enableportfolios) && has_capability('mod/anonforum:exportdiscussion', $modcontext)) {
    require_once($CFG->libdir.'/portfoliolib.php');
    $button = new portfolio_add_button();
    $button->set_callback_options('anonforum_portfolio_caller', array('discussionid' => $discussion->id), 'mod_anonforum');
    $button = $button->to_html(PORTFOLIO_ADD_FULL_FORM, get_string('exportdiscussion', 'mod_anonforum'));
    $buttonextraclass = '';
    if (empty($button)) {
        // no portfolio plugin available.
        $button = '&nbsp;';
        $buttonextraclass = ' noavailable';
    }
    echo html_writer::tag('div', $button, array('class' => 'discussioncontrol exporttoportfolio'.$buttonextraclass));
} else {
    echo html_writer::tag('div', '&nbsp;', array('class'=>'discussioncontrol nullcontrol'));
}

// groups selector not needed here
echo '<div class="discussioncontrol displaymode">';
anonforum_print_mode_form($discussion->id, $displaymode);
echo "</div>";

if ($anonforum->type != 'single'
            && has_capability('mod/anonforum:movediscussions', $modcontext)) {

    echo '<div class="discussioncontrol movediscussion">';
    // Popup menu to move discussions to other anonymous forums. The discussion in a
    // single discussion anonymous forum can't be moved.
    $modinfo = get_fast_modinfo($course);
    if (isset($modinfo->instances['anonforum'])) {
        $anonforummenu = array();
        // Check anonymous forum types and eliminate simple discussions.
        $anonforumcheck = $DB->get_records('anonforum', array('course' => $course->id),'', 'id, type');
        foreach ($modinfo->instances['anonforum'] as $anonforumcm) {
            if (!$anonforumcm->uservisible || !has_capability('mod/anonforum:startdiscussion',
                context_module::instance($anonforumcm->id))) {
                continue;
            }
            $section = $anonforumcm->sectionnum;
            $sectionname = get_section_name($course, $section);
            if (empty($anonforummenu[$section])) {
                $anonforummenu[$section] = array($sectionname => array());
            }
            $anonforumidcompare = $anonforumcm->instance != $anonforum->id;
            $anonforumtypecheck = $anonforumcheck[$anonforumcm->instance]->type !== 'single';
            if ($anonforumidcompare and $anonforumtypecheck) {
                $url = "/mod/anonforum/discuss.php?d=$discussion->id&move=$anonforumcm->instance&sesskey=".sesskey();
                $anonforummenu[$section][$sectionname][$url] = format_string($anonforumcm->name);
            }
        }
        if (!empty($anonforummenu)) {
            echo '<div class="movediscussionoption">';
            $select = new url_select($anonforummenu, '',
                    array(''=>get_string("movethisdiscussionto", "anonforum")),
                    'anonforummenu', get_string('move'));
            echo $OUTPUT->render($select);
            echo "</div>";
        }
    }
    echo "</div>";
}
echo '<div class="clearfloat">&nbsp;</div>';
echo "</div>";

if (!empty($anonforum->blockafter) && !empty($anonforum->blockperiod)) {
    $a = new stdClass();
    $a->blockafter  = $anonforum->blockafter;
    $a->blockperiod = get_string('secondstotime'.$anonforum->blockperiod);
    echo $OUTPUT->notification(get_string('thisanonforumisthrottled','anonforum',$a));
}

if ($anonforum->type == 'qanda' && !has_capability('mod/anonforum:viewqandawithoutposting', $modcontext) &&
            !anonforum_user_has_posted($anonforum->id,$discussion->id,$USER->id)) {
    echo $OUTPUT->notification(get_string('qandanotify','anonforum'));
}

if ($move == -1 and confirm_sesskey()) {
    echo $OUTPUT->notification(get_string('discussionmoved', 'anonforum', format_string($anonforum->name,true)));
}

$canrate = has_capability('mod/anonforum:rate', $modcontext);
anonforum_print_discussion($course, $cm, $anonforum, $discussion, $post, $displaymode, $canreply, $canrate);

echo $OUTPUT->footer();



