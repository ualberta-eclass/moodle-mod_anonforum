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
 * Edit and save a new post to a discussion
 *
 * @package mod-anonforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later773
 */

require_once('../../config.php');
require_once('lib.php');
require_once($CFG->libdir.'/completionlib.php');

$reply   = optional_param('reply', 0, PARAM_INT);
$anonforum   = optional_param('anonforum', 0, PARAM_INT);
$edit    = optional_param('edit', 0, PARAM_INT);
$delete  = optional_param('delete', 0, PARAM_INT);
$prune   = optional_param('prune', 0, PARAM_INT);
$name    = optional_param('name', '', PARAM_CLEAN);
$confirm = optional_param('confirm', 0, PARAM_INT);
$groupid = optional_param('groupid', null, PARAM_INT);

$PAGE->set_url('/mod/anonforum/post.php', array(
        'reply' => $reply,
        'anonforum' => $anonforum,
        'edit'  => $edit,
        'delete'=> $delete,
        'prune' => $prune,
        'name'  => $name,
        'confirm'=>$confirm,
        'groupid'=>$groupid,
        ));
//these page_params will be passed as hidden variables later in the form.
$page_params = array('reply'=>$reply, 'anonforum'=>$anonforum, 'edit'=>$edit);

$sitecontext = context_system::instance();

if (!isloggedin() or isguestuser()) {

    if (!isloggedin() and !get_referer()) {
        // No referer+not logged in - probably coming in via email  See MDL-9052
        require_login();
    }

    if (!empty($anonforum)) {      // User is starting a new discussion in a anonforum
        if (! $anonforum = $DB->get_record('anonforum', array('id' => $anonforum))) {
            print_error('invalidanonforumid', 'anonforum');
        }
    } else if (!empty($reply)) {      // User is writing a new reply
        if (! $parent = anonforum_get_post_full($reply)) {
            print_error('invalidparentpostid', 'anonforum');
        }
        if (! $discussion = $DB->get_record('anonforum_discussions', array('id' => $parent->discussion))) {
            print_error('notpartofdiscussion', 'anonforum');
        }
        if (! $anonforum = $DB->get_record('anonforum', array('id' => $discussion->anonforum))) {
            print_error('invalidanonforumid');
        }
    }
    if (! $course = $DB->get_record('course', array('id' => $anonforum->course))) {
        print_error('invalidcourseid');
    }

    if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $anonforum);
    $PAGE->set_context($modcontext);
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguestpost', 'anonforum').'<br /><br />'.get_string('liketologin'), get_login_url(), get_referer(false));
    echo $OUTPUT->footer();
    exit;
}

require_login(0, false);   // Script is useless unless they're logged in

if (!empty($anonforum)) {      // User is starting a new discussion in a anonforum
    if (! $anonforum = $DB->get_record("anonforum", array("id" => $anonforum))) {
        print_error('invalidanonforumid', 'anonforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $anonforum->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $course->id)) {
        print_error("invalidcoursemodule");
    }

    $coursecontext = context_course::instance($course->id);

    if (! anonforum_user_can_post_discussion($anonforum, $groupid, -1, $cm)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {
                if (enrol_selfenrol_available($course->id)) {
                    $SESSION->wantsurl = qualified_me();
                    $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                    redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
                }
            }
        }
        print_error('nopostanonforum', 'anonforum');
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    if (isset($_SERVER["HTTP_REFERER"])) {
        $SESSION->fromurl = $_SERVER["HTTP_REFERER"];
    } else {
        $SESSION->fromurl = '';
    }


    // Load up the $post variable.

    $post = new stdClass();
    $post->course        = $course->id;
    $post->anonforum         = $anonforum->id;
    $post->discussion    = 0;           // ie discussion # not defined yet
    $post->parent        = 0;
    $post->subject       = '';
    $post->userid        = $USER->id;
    $post->message       = '';
    $post->messageformat = editors_get_preferred_format();
    $post->messagetrust  = 0;
    $post->anonymouspost = $anonforum->anonymous;

    if (isset($groupid)) {
        $post->groupid = $groupid;
    } else {
        $post->groupid = groups_get_activity_group($cm);
    }

    // Unsetting this will allow the correct return URL to be calculated later.
    unset($SESSION->fromdiscussion);

} else if (!empty($reply)) {      // User is writing a new reply

    if (! $parent = anonforum_get_post_full($reply)) {
        print_error('invalidparentpostid', 'anonforum');
    }
    if (! $discussion = $DB->get_record("anonforum_discussions", array("id" => $parent->discussion))) {
        print_error('notpartofdiscussion', 'anonforum');
    }
    if (! $anonforum = $DB->get_record("anonforum", array("id" => $discussion->anonforum))) {
        print_error('invalidanonforumid', 'anonforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $course->id)) {
        print_error('invalidcoursemodule');
    }

    // Ensure lang, theme, etc. is set up properly. MDL-6926
    $PAGE->set_cm($cm, $course, $anonforum);

    $coursecontext = context_course::instance($course->id);
    $modcontext    = context_module::instance($cm->id);

    if (! anonforum_user_can_post($anonforum, $discussion, $USER, $cm, $course, $modcontext)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {  // User is a guest here!
                $SESSION->wantsurl = qualified_me();
                $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
            }
        }
        print_error('nopostanonforum', 'anonforum');
    }

    // Make sure user can post here
    if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
        $groupmode =  $cm->groupmode;
    } else {
        $groupmode = $course->groupmode;
    }
    if ($groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $modcontext)) {
        if ($discussion->groupid == -1) {
            print_error('nopostanonforum', 'anonforum');
        } else {
            if (!groups_is_member($discussion->groupid)) {
                print_error('nopostanonforum', 'anonforum');
            }
        }
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    // Load up the $post variable.

    $post = new stdClass();
    $post->course      = $course->id;
    $post->anonforum       = $anonforum->id;
    $post->discussion  = $parent->discussion;
    $post->parent      = $parent->id;
    $post->subject     = $parent->subject;
    $post->userid      = $USER->id;
    $post->message     = '';
    $post->anonymouspost   = $anonforum->anonymous;
    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $strre = get_string('re', 'anonforum');
    if (!(substr($post->subject, 0, strlen($strre)) == $strre)) {
        $post->subject = $strre.' '.$post->subject;
    }

    // Unsetting this will allow the correct return URL to be calculated later.
    unset($SESSION->fromdiscussion);

} else if (!empty($edit)) {  // User is editing their own post

    if (! $post = anonforum_get_post_full($edit)) {
        print_error('invalidpostid', 'anonforum');
    }

    if ($post->parent) {
        if (! $parent = anonforum_get_post_full($post->parent)) {
            print_error('invalidparentpostid', 'anonforum');
        }
    }

    if (! $discussion = $DB->get_record("anonforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'anonforum');
    }
    if (! $anonforum = $DB->get_record("anonforum", array("id" => $discussion->anonforum))) {
        print_error('invalidanonforumid', 'anonforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $course->id)) {
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $anonforum);

    if (!($anonforum->type == 'news' && !$post->parent && $discussion->timestart > time())) {
        if (((time() - $post->created) > $CFG->maxeditingtime) and
                    !has_capability('mod/anonforum:editanypost', $modcontext)) {
            print_error('maxtimehaspassed', 'anonforum', '', format_time($CFG->maxeditingtime));
        }
    }
    if (($post->userid <> $USER->id) and
                !has_capability('mod/anonforum:editanypost', $modcontext)) {
        print_error('cannoteditposts', 'anonforum');
    }


    // Load up the $post variable.
    $post->edit   = $edit;
    $post->course = $course->id;
    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $post = trusttext_pre_edit($post, 'message', $modcontext);

    // Unsetting this will allow the correct return URL to be calculated later.
    unset($SESSION->fromdiscussion);

}else if (!empty($delete)) {  // User is deleting a post

    if (! $post = anonforum_get_post_full($delete)) {
        print_error('invalidpostid', 'anonforum');
    }
    if (! $discussion = $DB->get_record("anonforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'anonforum');
    }
    if (! $anonforum = $DB->get_record("anonforum", array("id" => $discussion->anonforum))) {
        print_error('invalidanonforumid', 'anonforum');
    }
    if (!$cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $anonforum->course)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $anonforum->course))) {
        print_error('invalidcourseid');
    }

    require_login($course, false, $cm);
    $modcontext = context_module::instance($cm->id);

    if ( !(($post->userid == $USER->id && has_capability('mod/anonforum:deleteownpost', $modcontext))
                || has_capability('mod/anonforum:deleteanypost', $modcontext)) ) {
        print_error('cannotdeletepost', 'anonforum');
    }


    $replycount = anonforum_count_replies($post);

    if (!empty($confirm) && confirm_sesskey()) {    // User has confirmed the delete
        //check user capability to delete post.
        $timepassed = time() - $post->created;
        if (($timepassed > $CFG->maxeditingtime) && !has_capability('mod/anonforum:deleteanypost', $modcontext)) {
            print_error("cannotdeletepost", "anonforum",
                      anonforum_go_back_to("discuss.php?d=$post->discussion"));
        }

        if ($post->totalscore) {
            notice(get_string('couldnotdeleteratings', 'rating'),
                    anonforum_go_back_to("discuss.php?d=$post->discussion"));

        } else if ($replycount && !has_capability('mod/anonforum:deleteanypost', $modcontext)) {
            print_error("couldnotdeletereplies", "anonforum",
                    anonforum_go_back_to("discuss.php?d=$post->discussion"));

        } else {
            if (! $post->parent) {  // post is a discussion topic as well, so delete discussion
                if ($anonforum->type == 'single') {
                    notice("Sorry, but you are not allowed to delete that discussion!",
                            anonforum_go_back_to("discuss.php?d=$post->discussion"));
                }
                anonforum_delete_discussion($discussion, false, $course, $cm, $anonforum);

                if (empty($post->anonymouspost)) {
                    add_to_log($discussion->course, "anonforum", "delete discussion",
                        "view.php?id=$cm->id", "$anonforum->id", $cm->id);
                }
                redirect("view.php?f=$discussion->anonforum");

            } else if (anonforum_delete_post($post, has_capability('mod/anonforum:deleteanypost', $modcontext),
                $course, $cm, $anonforum)) {

                if ($anonforum->type == 'single') {
                    // Single discussion anonymous forums are an exception. We show
                    // the anonymous forum itself since it only has one discussion
                    // thread.
                    $discussionurl = "view.php?f=$anonforum->id";
                } else {
                    $discussionurl = "discuss.php?d=$post->discussion";
                }
                if (empty($post->anonymouspost)) {
                    add_to_log($discussion->course, "anonforum", "delete post", $discussionurl, "$post->id", $cm->id);
                }
                redirect(anonforum_go_back_to($discussionurl));
            } else {
                print_error('errorwhiledelete', 'anonforum');
            }
        }


    } else { // User just asked to delete something

        anonforum_set_return();
        $PAGE->navbar->add(get_string('delete', 'anonforum'));
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);

        if ($replycount) {
            if (!has_capability('mod/anonforum:deleteanypost', $modcontext)) {
                print_error("couldnotdeletereplies", "anonforum",
                      anonforum_go_back_to("discuss.php?d=$post->discussion"));
            }
            echo $OUTPUT->header();
            echo $OUTPUT->heading(format_string($anonforum->name), 2);
            echo $OUTPUT->confirm(get_string("deletesureplural", "anonforum", $replycount+1),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'#p'.$post->id);

            anonforum_print_post($post, $discussion, $anonforum, $cm, $course, false, false, false);

            if (empty($post->edit)) {
                $anonforumtracked = anonforum_tp_is_tracked($anonforum);
                $posts = anonforum_get_all_discussion_posts($discussion->id, "created ASC", $anonforumtracked);
                anonforum_print_posts_nested($course, $cm, $anonforum, $discussion, $post, false, false, $anonforumtracked, $posts);
            }
        } else {
            echo $OUTPUT->header();
            echo $OUTPUT->heading(format_string($anonforum->name), 2);
            echo $OUTPUT->confirm(get_string("deletesure", "anonforum", $replycount),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'#p'.$post->id);
            anonforum_print_post($post, $discussion, $anonforum, $cm, $course, false, false, false);
        }

    }
    echo $OUTPUT->footer();
    die;


} else if (!empty($prune)) {  // Pruning

    if (!$post = anonforum_get_post_full($prune)) {
        print_error('invalidpostid', 'anonforum');
    }
    if (!$discussion = $DB->get_record("anonforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'anonforum');
    }
    if (!$anonforum = $DB->get_record("anonforum", array("id" => $discussion->anonforum))) {
        print_error('invalidanonforumid', 'anonforum');
    }
    if ($anonforum->type == 'single') {
        print_error('cannotsplit', 'anonforum');
    }
    if (!$post->parent) {
        print_error('alreadyfirstpost', 'anonforum');
    }
    if (!$cm = get_coursemodule_from_instance("anonforum", $anonforum->id, $anonforum->course)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }
    if (!has_capability('mod/anonforum:splitdiscussions', $modcontext)) {
        print_error('cannotsplit', 'anonforum');
    }

    if (!empty($name) && confirm_sesskey()) {    // User has confirmed the prune

        $newdiscussion = new stdClass();
        $newdiscussion->course       = $discussion->course;
        $newdiscussion->anonforum        = $discussion->anonforum;
        $newdiscussion->name         = $name;
        $newdiscussion->firstpost    = $post->id;
        $newdiscussion->userid       = $discussion->userid;
        $newdiscussion->groupid      = $discussion->groupid;
        $newdiscussion->assessed     = $discussion->assessed;
        $newdiscussion->usermodified = $post->userid;
        $newdiscussion->timestart    = $discussion->timestart;
        $newdiscussion->timeend      = $discussion->timeend;

        $newid = $DB->insert_record('anonforum_discussions', $newdiscussion);

        $newpost = new stdClass();
        $newpost->id      = $post->id;
        $newpost->parent  = 0;
        $newpost->subject = $name;

        $DB->update_record("anonforum_posts", $newpost);

        anonforum_change_discussionid($post->id, $newid);

        // update last post in each discussion
        anonforum_discussion_update_last_post($discussion->id);
        anonforum_discussion_update_last_post($newid);

        if (empty($post->anonymouspost)) {
            add_to_log($discussion->course, "anonforum", "prune post", "discuss.php?d=$newid", "$post->id", $cm->id);
        }
        redirect(anonforum_go_back_to("discuss.php?d=$newid"));

    } else { // User just asked to prune something

        $course = $DB->get_record('course', array('id' => $anonforum->course));

        $PAGE->set_cm($cm);
        $PAGE->set_context($modcontext);
        $PAGE->navbar->add(format_string($post->subject, true), new moodle_url('/mod/anonforum/discuss.php', array('d'=>$discussion->id)));
        $PAGE->navbar->add(get_string("prune", "anonforum"));
        $PAGE->set_title(format_string($discussion->name).": ".format_string($post->subject));
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->heading(format_string($anonforum->name), 2);
        echo $OUTPUT->heading(get_string('pruneheading', 'anonforum'), 3);
        echo '<center>';

        include('prune.html');

        anonforum_print_post($post, $discussion, $anonforum, $cm, $course, false, false, false);
        echo '</center>';
    }
    echo $OUTPUT->footer();
    die;
} else {
    print_error('unknowaction');

}

if (!isset($coursecontext)) {
    // Has not yet been set by post.php.
    $coursecontext = context_course::instance($anonforum->course);
}


// from now on user must be logged on properly

if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id)) { // For the logs
    print_error('invalidcoursemodule');
}
$modcontext = context_module::instance($cm->id);
require_login($course, false, $cm);

if (isguestuser()) {
    // just in case
    print_error('noguest');
}

if (!isset($anonforum->maxattachments)) {  // TODO - delete this once we add a field to the anonforum table
    $anonforum->maxattachments = 3;
}

$thresholdwarning = anonforum_check_throttling($anonforum, $cm);
$mform_post = new mod_anonforum_post_form('post.php', array('course' => $course,
                                                        'cm' => $cm,
                                                        'coursecontext' => $coursecontext,
                                                        'modcontext' => $modcontext,
                                                        'anonforum' => $anonforum,
                                                        'post' => $post,
                                                        'thresholdwarning' => $thresholdwarning,
                                                        'edit' => $edit), 'post', '', array('id' => 'mformanonforum'));

$draftitemid = file_get_submitted_draft_itemid('attachments');
file_prepare_draft_area($draftitemid, $modcontext->id, 'mod_anonforum', 'attachment', empty($post->id)?null:$post->id, mod_anonforum_post_form::attachment_options($anonforum));

//load data into form NOW!

if ($USER->id != $post->userid) {   // Not the original author, so add a message to the end
    $data = new stdClass();
    $data->date = userdate($post->modified);
    if ($post->messageformat == FORMAT_HTML) {
        $data->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$USER->id.'&course='.$post->course.'">'.
                       fullname($USER).'</a>';
        $post->message .= '<p><span class="edited">('.get_string('editedby', 'anonforum', $data).')</span></p>';
    } else {
        $data->name = fullname($USER);
        $post->message .= "\n\n(".get_string('editedby', 'anonforum', $data).')';
    }
    unset($data);
}

$formheading = '';
if (!empty($parent)) {
    $heading = get_string("yourreply", "anonforum");
    $formheading = get_string('reply', 'anonforum');
} else {
    if ($anonforum->type == 'qanda') {
        $heading = get_string('yournewquestion', 'anonforum');
    } else {
        $heading = get_string('yournewtopic', 'anonforum');
    }
}

if (anonforum_is_subscribed($USER->id, $anonforum->id)) {
    $subscribe = true;

} else if (anonforum_user_has_posted($anonforum->id, 0, $USER->id)) {
    $subscribe = false;

} else {
    // user not posted yet - use subscription default specified in profile
    $subscribe = !empty($USER->autosubscribe);
}

$postid = empty($post->id) ? null : $post->id;
$draftid_editor = file_get_submitted_draft_itemid('message');
$currenttext = file_prepare_draft_area($draftid_editor, $modcontext->id, 'mod_anonforum', 'post', $postid, mod_anonforum_post_form::editor_options($modcontext, $postid), $post->message);

$mform_post->set_data(array(        'attachments'=>$draftitemid,
                                    'general'=>$heading,
                                    'subject'=>$post->subject,
                                    'message'=>array(
                                        'text'=>$currenttext,
                                        'format'=>empty($post->messageformat) ? editors_get_preferred_format() : $post->messageformat,
                                        'itemid'=>$draftid_editor
                                    ),
                                    'subscribe'=>$subscribe?1:0,
                                    'mailnow'=>!empty($post->mailnow),
                                    'userid'=>$post->userid,
                                    'parent'=>$post->parent,
                                    'discussion'=>$post->discussion,
                                    'anonymouspost'=> $post->anonymouspost,
                                    'course'=>$course->id) +
                                    $page_params +

                            (isset($post->format)?array(
                                    'format'=>$post->format):
                                array())+

                            (isset($discussion->timestart)?array(
                                    'timestart'=>$discussion->timestart):
                                array())+

                            (isset($discussion->timeend)?array(
                                    'timeend'=>$discussion->timeend):
                                array())+

                            (isset($post->groupid)?array(
                                    'groupid'=>$post->groupid):
                                array())+

                            (isset($discussion->id)?
                                    array('discussion'=>$discussion->id):
                                    array()));

if ($fromform = $mform_post->get_data()) {

    if (empty($SESSION->fromurl)) {
        $errordestination = "$CFG->wwwroot/mod/anonforum/view.php?f=$anonforum->id";
    } else {
        $errordestination = $SESSION->fromurl;
    }

    $fromform->itemid        = $fromform->message['itemid'];
    $fromform->messageformat = $fromform->message['format'];
    $fromform->message       = $fromform->message['text'];
    // WARNING: the $fromform->message array has been overwritten, do not use it anymore!
    $fromform->messagetrust  = trusttext_trusted($modcontext);

    $contextcheck = isset($fromform->groupinfo) && has_capability('mod/anonforum:movediscussions', $modcontext);

    // If the box has been unchecked no value will come through from the anonymous forum.
    if (empty($fromform->anonymouspost)) {
        $fromform->anonymouspost = 0;
    }

    if ($fromform->edit) {           // Updating a post
        unset($fromform->groupid);
        $fromform->id = $fromform->edit;
        $message = '';

        //fix for bug #4314
        if (!$realpost = $DB->get_record('anonforum_posts', array('id' => $fromform->id))) {
            $realpost = new stdClass();
            $realpost->userid = -1;
        }

        // if user has edit any post capability
        // or has either startnewdiscussion or reply capability and is editting own post
        // then he can proceed
        // MDL-7066
        if ( !(($realpost->userid == $USER->id && (has_capability('mod/anonforum:replypost', $modcontext)
                            || has_capability('mod/anonforum:startdiscussion', $modcontext))) ||
                            has_capability('mod/anonforum:editanypost', $modcontext)) ) {
            print_error('cannotupdatepost', 'anonforum');
        }

        // If the user has access to all groups and they are changing the group, then update the post.
        if ($contextcheck) {
            if (empty($fromform->groupinfo)) {
                $fromform->groupinfo = -1;
            }
            $DB->set_field('anonforum_discussions' ,'groupid' , $fromform->groupinfo, array('firstpost' => $fromform->id));
        }

        $updatepost = $fromform; //realpost
        $updatepost->anonforum = $anonforum->id;
        if (!anonforum_update_post($updatepost, $mform_post, $message)) {
            print_error("couldnotupdate", "anonforum", $errordestination);
        }

        // MDL-11818
        if (($anonforum->type == 'single') && ($updatepost->parent == '0')){ // updating first post of single discussion type -> updating anonforum intro
            $anonforum->intro = $updatepost->message;
            $anonforum->timemodified = time();
            $DB->update_record("anonforum", $anonforum);
        }

        $timemessage = 2;
        if (!empty($message)) { // if we're printing stuff about the file upload
            $timemessage = 4;
        }

        if ($realpost->userid == $USER->id) {
            $message .= '<br />'.get_string("postupdated", "anonforum");
        } else {
            if (empty($realpost->anonymouspost)) {
                $realuser = $DB->get_record('user', array('id' => $realpost->userid));
                $name = fullname($realuser);
            } else {
                $name = get_string('anonymoususer', 'anonforum');
            }
            $message .= '<br />'.get_string("editedpostupdated", "anonforum", $name);
        }

        if ($subscribemessage = anonforum_post_subscription($fromform, $anonforum)) {
            $timemessage = 4;
        }
        if ($anonforum->type == 'single') {
            // Single discussion anonymous forums are an exception. We show
            // the anonymous forum itself since it only has one discussion
            // thread.
            $discussionurl = "view.php?f=$anonforum->id";
        } else {
            $discussionurl = "discuss.php?d=$discussion->id#p$fromform->id";
        }
        if (empty($fromform->anonymouspost)) {
            add_to_log($course->id, "anonforum", "update post",
                "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);
        }
        redirect(anonforum_go_back_to("$discussionurl"), $message.$subscribemessage, $timemessage);

        exit;


    } else if ($fromform->discussion) { // Adding a new post to an existing discussion
        // Before we add this we must check that the user will not exceed the blocking threshold.
        anonforum_check_blocking_threshold($thresholdwarning);

        unset($fromform->groupid);
        $message = '';
        $addpost = $fromform;
        $addpost->anonforum=$anonforum->id;
        if ($fromform->id = anonforum_add_new_post($addpost, $mform_post, $message)) {

            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($subscribemessage = anonforum_post_subscription($fromform, $anonforum)) {
                $timemessage = 4;
            }

            if (!empty($fromform->mailnow)) {
                $message .= get_string("postmailnow", "anonforum");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "anonforum") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "anonforum", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($anonforum->type == 'single') {
                // Single discussion anonymous forums are an exception. We show
                // the anonymous forum itself since it only has one discussion
                // thread.
                $discussionurl = "view.php?f=$anonforum->id";
            } else {
                $discussionurl = "discuss.php?d=$discussion->id";
            }
            if (empty($fromform->anonymouspost)) {
                add_to_log($course->id, "anonforum", "add post",
                      "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);
            }
            // Update completion state
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($anonforum->completionreplies || $anonforum->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(anonforum_go_back_to("$discussionurl#p$fromform->id"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "anonforum", $errordestination);
        }
        exit;

    } else { // Adding a new discussion.
        // Before we add this we must check that the user will not exceed the blocking threshold.
        anonforum_check_blocking_threshold($thresholdwarning);

        if (!anonforum_user_can_post_discussion($anonforum, $fromform->groupid, -1, $cm, $modcontext)) {
            print_error('cannotcreatediscussion', 'anonforum');
        }
        // If the user has access all groups capability let them choose the group.
        if ($contextcheck) {
            $fromform->groupid = $fromform->groupinfo;
        }
        if (empty($fromform->groupid)) {
            $fromform->groupid = -1;
        }

        $fromform->mailnow = empty($fromform->mailnow) ? 0 : 1;

        $discussion = $fromform;
        $discussion->name    = $fromform->subject;

        $newstopic = false;
        if ($anonforum->type == 'news' && !$fromform->parent) {
            $newstopic = true;
        }
        $discussion->timestart = $fromform->timestart;
        $discussion->timeend = $fromform->timeend;
        $discussion->anonymouspost = empty($fromform->anonymouspost) ? 0 : $fromform->anonymouspost;
        $message = '';
        if ($discussion->id = anonforum_add_discussion($discussion, $mform_post, $message)) {

            if (empty($discussion->anonymouspost)) {
                add_to_log($course->id, "anonforum", "add discussion",
                    "discuss.php?d=$discussion->id", "$discussion->id", $cm->id);
            }
            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($fromform->mailnow) {
                $message .= get_string("postmailnow", "anonforum");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "anonforum") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "anonforum", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($subscribemessage = anonforum_post_subscription($discussion, $anonforum)) {
                $timemessage = 6;
            }

            // Update completion status
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($anonforum->completiondiscussions || $anonforum->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(anonforum_go_back_to("view.php?f=$fromform->anonforum"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "anonforum", $errordestination);
        }

        exit;
    }
}



// To get here they need to edit a post, and the $post
// variable will be loaded with all the particulars,
// so bring up the form.

// $course, $anonymous forum are defined.  $discussion is for edit and reply only.

if ($post->discussion) {
    if (! $toppost = $DB->get_record("anonforum_posts", array("discussion" => $post->discussion, "parent" => 0))) {
        print_error('cannotfindparentpost', 'anonforum', '', $post->id);
    }
} else {
    $toppost = new stdClass();
    $toppost->subject = ($anonforum->type == "news") ? get_string("addanewtopic", "anonforum") :
                                                   get_string("addanewdiscussion", "anonforum");
}

if (empty($post->edit)) {
    $post->edit = '';
}

if (empty($discussion->name)) {
    if (empty($discussion)) {
        $discussion = new stdClass();
    }
    $discussion->name = $anonforum->name;
}
if ($anonforum->type == 'single') {
    // There is only one discussion thread for this anonymous forum type. We should
    // not show the discussion name (same as anonymous forum name in this case) in
    // the breadcrumbs.
    $strdiscussionname = '';
} else {
    // Show the discussion name in the breadcrumbs.
    $strdiscussionname = format_string($discussion->name).':';
}

$forcefocus = empty($reply) ? NULL : 'message';

if (!empty($discussion->id)) {
    $PAGE->navbar->add(format_string($toppost->subject, true), "discuss.php?d=$discussion->id");
}

if ($post->parent) {
    $PAGE->navbar->add(get_string('reply', 'anonforum'));
}

if ($edit) {
    $PAGE->navbar->add(get_string('edit', 'anonforum'));
}

$PAGE->set_title("$course->shortname: $strdiscussionname ".format_string($toppost->subject));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($anonforum->name), 2);

// checkup
if (!empty($parent) && !anonforum_user_can_see_post($anonforum, $discussion, $post, null, $cm)) {
    print_error('cannotreply', 'anonforum');
}
if (empty($parent) && empty($edit) && !anonforum_user_can_post_discussion($anonforum, $groupid, -1, $cm, $modcontext)) {
    print_error('cannotcreatediscussion', 'anonforum');
}

if ($anonforum->type == 'qanda'
            && !has_capability('mod/anonforum:viewqandawithoutposting', $modcontext)
            && !empty($discussion->id)
            && !anonforum_user_has_posted($anonforum->id, $discussion->id, $USER->id)) {
    echo $OUTPUT->notification(get_string('qandanotify','anonforum'));
}

// If there is a warning message and we are not editing a post we need to handle the warning.
if (!empty($thresholdwarning) && !$edit) {
    // Here we want to throw an exception if they are no longer allowed to post.
    anonforum_check_blocking_threshold($thresholdwarning);
}

if (!empty($parent)) {
    if (!$discussion = $DB->get_record('anonforum_discussions', array('id' => $parent->discussion))) {
        print_error('notpartofdiscussion', 'anonforum');
    }

    anonforum_print_post($parent, $discussion, $anonforum, $cm, $course, false, false, false);
    if (empty($post->edit)) {
        if ($anonforum->type != 'qanda' || anonforum_user_can_see_discussion($anonforum, $discussion, $modcontext)) {
            $anonforumtracked = anonforum_tp_is_tracked($anonforum);
            $posts = anonforum_get_all_discussion_posts($discussion->id, "created ASC", $anonforumtracked);
            anonforum_print_posts_threaded($course, $cm, $anonforum, $discussion, $parent, 0, false, $anonforumtracked, $posts);
        }
    }
} else {
    if (!empty($anonforum->intro)) {
        echo $OUTPUT->box(format_module_intro('anonforum', $anonforum, $cm->id), 'generalbox', 'intro');

        if (!empty($CFG->enableplagiarism)) {
            require_once($CFG->libdir.'/plagiarismlib.php');
            echo plagiarism_print_disclosure($cm->id);
        }
    }
}

if (!empty($formheading)) {
    echo $OUTPUT->heading($formheading, 2, array('class' => 'accesshide'));
}
$mform_post->display();

echo $OUTPUT->footer();

