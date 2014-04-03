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
 * @package    mod
 * @subpackage anonforum
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** Include required files */
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/eventslib.php');
require_once($CFG->dirroot.'/user/selector/lib.php');

/// CONSTANTS ///////////////////////////////////////////////////////////

define('ANONFORUM_MODE_FLATOLDEST', 1);
define('ANONFORUM_MODE_FLATNEWEST', -1);
define('ANONFORUM_MODE_THREADED', 2);
define('ANONFORUM_MODE_NESTED', 3);

define('ANONFORUM_CHOOSESUBSCRIBE', 0);
define('ANONFORUM_FORCESUBSCRIBE', 1);
define('ANONFORUM_INITIALSUBSCRIBE', 2);
define('ANONFORUM_DISALLOWSUBSCRIBE',3);

/**
 * ANONFORUM_TRACKING_OFF - Tracking is not available for this anonforum.
 */
define('ANONFORUM_TRACKING_OFF', 0);

/**
 * ANONFORUM_TRACKING_OPTIONAL - Tracking is based on user preference.
 */
define('ANONFORUM_TRACKING_OPTIONAL', 1);

/**
 * ANONFORUM_TRACKING_FORCED - Tracking is on, regardless of user setting.
 * Treated as ANONFORUM_TRACKING_OPTIONAL if $CFG->anonforum_allowforcedreadtracking is off.
 */
define('ANONFORUM_TRACKING_FORCED', 2);

/**
 * ANONFORUM_TRACKING_ON - deprecated alias for ANONFORUM_TRACKING_FORCED.
 * @deprecated since 2.6
 */
define('ANONFORUM_TRACKING_ON', 2);

define('ANONFORUM_MAILED_PENDING', 0);
define('ANONFORUM_MAILED_SUCCESS', 1);
define('ANONFORUM_MAILED_ERROR', 2);

if (!defined('ANONFORUM_CRON_USER_CACHE')) {
    /** Defines how many full user records are cached in anonforum cron. */
    define('ANONFORUM_CRON_USER_CACHE', 5000);
}

/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param stdClass $anonforum add anonforum instance
 * @param mod_anonforum_mod_form $mform
 * @return int intance id
 */
function anonforum_add_instance($anonforum, $mform = null) {
    global $CFG, $DB;

    $anonforum->timemodified = time();

    if (empty($anonforum->assessed)) {
        $anonforum->assessed = 0;
    }

    if (empty($anonforum->ratingtime) or empty($anonforum->assessed)) {
        $anonforum->assesstimestart  = 0;
        $anonforum->assesstimefinish = 0;
    }

    $anonforum->id = $DB->insert_record('anonforum', $anonforum);
    $modcontext = context_module::instance($anonforum->coursemodule);

    if ($anonforum->type == 'single') {  // Create related discussion.
        $discussion = new stdClass();
        $discussion->course        = $anonforum->course;
        $discussion->anonforum     = $anonforum->id;
        $discussion->name          = $anonforum->name;
        $discussion->assessed      = $anonforum->assessed;
        $discussion->message       = $anonforum->intro;
        $discussion->messageformat = $anonforum->introformat;
        $discussion->messagetrust  = trusttext_trusted(context_course::instance($anonforum->course));
        $discussion->mailnow       = false;
        $discussion->groupid       = -1;
        $discussion->anonymouspost = empty($anonforum->anonymous) ? 0 : $anonforum->anonymous;
        $message = '';


        $discussion->id = anonforum_add_discussion($discussion, null, $message);

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $discussion = $DB->get_record('anonforum_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('anonforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $options = array('subdirs'=>true); // Use the same options as intro field!
            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_anonforum', 'post', $post->id, $options, $post->message);
            $DB->set_field('anonforum_posts', 'message', $post->message, array('id'=>$post->id));
        }
    }

    if ($anonforum->forcesubscribe == ANONFORUM_INITIALSUBSCRIBE) {
        $users = anonforum_get_potential_subscribers($modcontext, 0, 'u.id, u.email');
        foreach ($users as $user) {
            anonforum_subscribe($user->id, $anonforum->id);
        }
    }

    anonforum_grade_item_update($anonforum);

    return $anonforum->id;
}


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global object
 * @param object $anonforum anonforum instance (with magic quotes)
 * @return bool success
 */
function anonforum_update_instance($anonforum, $mform) {
    global $DB, $OUTPUT, $USER;

    $anonforum->timemodified = time();
    $anonforum->id           = $anonforum->instance;

    if (empty($anonforum->assessed)) {
        $anonforum->assessed = 0;
    }

    if (empty($anonforum->ratingtime) or empty($anonforum->assessed)) {
        $anonforum->assesstimestart  = 0;
        $anonforum->assesstimefinish = 0;
    }

    $oldanonforum = $DB->get_record('anonforum', array('id'=>$anonforum->id));

    if ($anonforum->type == 'single') {  // Update related discussion and post.
        $discussions = $DB->get_records('anonforum_discussions', array('anonforum'=>$anonforum->id), 'timemodified ASC');
        if (!empty($discussions)) {
            if (count($discussions) > 1) {
                echo $OUTPUT->notification(get_string('warnformorepost', 'anonforum'));
            }
            $discussion = array_pop($discussions);
        } else {
            // try to recover by creating initial discussion - MDL-16262
            $discussion = new stdClass();
            $discussion->course          = $anonforum->course;
            $discussion->anonforum           = $anonforum->id;
            $discussion->name            = $anonforum->name;
            $discussion->assessed        = $anonforum->assessed;
            $discussion->message         = $anonforum->intro;
            $discussion->messageformat   = $anonforum->introformat;
            $discussion->messagetrust    = true;
            $discussion->mailnow         = false;
            $discussion->groupid         = -1;
            $discussion->anonymouspost   = $anonforum->anonymous;

            $message = '';

            anonforum_add_discussion($discussion, null, $message);

            if (! $discussion = $DB->get_record('anonforum_discussions', array('anonforum'=>$anonforum->id))) {
                print_error('cannotadd', 'anonforum');
            }
        }
        if (! $post = $DB->get_record('anonforum_posts', array('id'=>$discussion->firstpost))) {
            print_error('cannotfindfirstpost', 'anonforum');
        }

        $cm         = get_coursemodule_from_instance('anonforum', $anonforum->id);
        $modcontext = context_module::instance($cm->id, MUST_EXIST);

        $post = $DB->get_record('anonforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);
        $post->subject       = $anonforum->name;
        $post->message       = $anonforum->intro;
        $post->messageformat = $anonforum->introformat;
        $post->messagetrust  = trusttext_trusted($modcontext);
        $post->modified      = $anonforum->timemodified;
        $post->userid        = $USER->id;    // MDL-18599, so that current teacher can take ownership of activities.

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // Ugly hack - we need to copy the files somehow.
            $options = array('subdirs'=>true); // Use the same options as intro field!
            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_anonforum', 'post', $post->id, $options, $post->message);
        }

        $DB->update_record('anonforum_posts', $post);
        $discussion->name = $anonforum->name;
        $DB->update_record('anonforum_discussions', $discussion);
    }

    $DB->update_record('anonforum', $anonforum);

    $modcontext = context_module::instance($anonforum->coursemodule);
    if (($anonforum->forcesubscribe == ANONFORUM_INITIALSUBSCRIBE) && ($oldanonforum->forcesubscribe <> $anonforum->forcesubscribe)) {
        $users = anonforum_get_potential_subscribers($modcontext, 0, 'u.id, u.email', '');
        foreach ($users as $user) {
            anonforum_subscribe($user->id, $anonforum->id);
        }
    }

    anonforum_grade_item_update($anonforum);

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id anonforum instance id
 * @return bool success
 */
function anonforum_delete_instance($id) {
    global $DB;

    if (!$anonforum = $DB->get_record('anonforum', array('id'=>$id))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        return false;
    }

    $context = context_module::instance($cm->id);

    // now get rid of all files
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    if ($discussions = $DB->get_records('anonforum_discussions', array('anonforum'=>$anonforum->id))) {
        foreach ($discussions as $discussion) {
            if (!anonforum_delete_discussion($discussion, true, $course, $cm, $anonforum)) {
                $result = false;
            }
        }
    }

    if (!$DB->delete_records('anonforum_digests', array('anonforum' => $anonforum->id))) {
        $result = false;
    }

    if (!$DB->delete_records('anonforum_subscriptions', array('anonforum'=>$anonforum->id))) {
        $result = false;
    }

    anonforum_tp_delete_read_records(-1, -1, -1, $anonforum->id);

    if (!$DB->delete_records('anonforum', array('id'=>$anonforum->id))) {
        $result = false;
    }

    anonforum_grade_item_delete($anonforum);

    return $result;
}


/**
 * Indicates API features that the anonforum supports.
 *
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_GROUPMEMBERSONLY
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_COMPLETION_HAS_RULES
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature
 * @return mixed True if yes (some features may use other values)
 */
function anonforum_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_RATE:                    return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_PLAGIARISM:              return true;

        default: return null;
    }
}


/**
 * Obtains the automatic completion state for this anonforum based on any conditions
 * in anonforum settings.
 *
 * @global object
 * @global object
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function anonforum_get_completion_state($course,$cm,$userid,$type) {
    global $CFG,$DB;

    // Get anonymous forum details
    if (!($anonforum=$DB->get_record('anonforum',array('id'=>$cm->instance)))) {
        throw new Exception("Can't find anonforum {$cm->instance}");
    }

    $result=$type; // Default return value

    $postcountparams=array('userid'=>$userid,'anonforumid'=>$anonforum->id);
    $postcountsql="
SELECT
    COUNT(1)
FROM
    {anonforum_posts} fp
    INNER JOIN {anonforum_discussions} fd ON fp.discussion=fd.id
WHERE
    fp.userid=:userid AND fd.anonforum=:anonforumid";

    if ($anonforum->completiondiscussions) {
        $value = $anonforum->completiondiscussions <=
                 $DB->count_records('anonforum_discussions',array('anonforum'=>$anonforum->id,'userid'=>$userid));
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($anonforum->completionreplies) {
        $value = $anonforum->completionreplies <=
                 $DB->get_field_sql( $postcountsql.' AND fp.parent<>0',$postcountparams);
        if ($type==COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($anonforum->completionposts) {
        $value = $anonforum->completionposts <= $DB->get_field_sql($postcountsql,$postcountparams);
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }

    return $result;
}

/**
 * Create a message-id string to use in the custom headers of anonforum notification emails
 *
 * message-id is used by email clients to identify emails and to nest conversations
 *
 * @param int $postid The ID of the anonforum post we are notifying the user about
 * @param int $usertoid The ID of the user being notified
 * @param string $hostname The server's hostname
 * @return string A unique message-id
 */
function anonforum_get_email_message_id($postid, $usertoid, $hostname) {
    return '<'.hash('sha256',$postid.'to'.$usertoid).'@'.$hostname.'>';
}

/**
 * Removes properties from user record that are not necessary
 * for sending post notifications.
 * @param stdClass $user
 * @return void, $user parameter is modified
 */
function anonforum_cron_minimise_user_record(stdClass $user) {

    // We store large amount of users in one huge array,
    // make sure we do not store info there we do not actually need
    // in mail generation code or messaging.

    unset($user->institution);
    unset($user->department);
    unset($user->address);
    unset($user->city);
    unset($user->url);
    unset($user->currentlogin);
    unset($user->description);
    unset($user->descriptionformat);
}

/**
 * Function to be run periodically according to the moodle cron
 * Finds all posts that have yet to be mailed out, and mails them
 * out to all subscribers
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses CONTEXT_COURSE
 * @uses SITEID
 * @uses FORMAT_PLAIN
 * @return void
 */
function anonforum_cron() {
    global $CFG, $USER, $DB;

    $site = get_site();

    // All users that are subscribed to any post that needs sending,
    // please increase $CFG->extramemorylimit on large sites that
    // send notifications to a large number of users.
    $users = array();
    $userscount = 0; // Cached user counter - count($users) in PHP is horribly slow!!!

    // status arrays
    $mailcount  = array();
    $errorcount = array();

    // caches
    $discussions     = array();
    $anonforums          = array();
    $courses         = array();
    $coursemodules   = array();
    $subscribedusers = array();


    // Posts older than 2 days will not be mailed.  This is to avoid the problem where
    // cron has not been running for a long time, and then suddenly people are flooded
    // with mail from the past few weeks or months
    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 48 * 3600;   // Two days earlier

    // Get the list of anonforum subscriptions for per-user per-anonymous forum maildigest settings.
    $digestsset = $DB->get_recordset('anonforum_digests', null, '', 'id, userid, anonforum, maildigest');
    $digests = array();
    foreach ($digestsset as $thisrow) {
        if (!isset($digests[$thisrow->anonforum])) {
            $digests[$thisrow->anonforum] = array();
        }
        $digests[$thisrow->anonforum][$thisrow->userid] = $thisrow->maildigest;
    }
    $digestsset->close();

    if ($posts = anonforum_get_unmailed_posts($starttime, $endtime, $timenow)) {
        // Mark them all now as being mailed.  It's unlikely but possible there
        // might be an error later so that a post is NOT actually mailed out,
        // but since mail isn't crucial, we can accept this risk.  Doing it now
        // prevents the risk of duplicated mails, which is a worse problem.

        if (!anonforum_mark_old_posts_as_mailed($endtime)) {
            mtrace('Errors occurred while trying to mark some posts as being mailed.');
            return false;  // Don't continue trying to mail them, in case we are in a cron loop
        }

        // checking post validity, and adding users to loop through later
        foreach ($posts as $pid => $post) {

            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {
                if ($discussion = $DB->get_record('anonforum_discussions', array('id'=> $post->discussion))) {
                    $discussions[$discussionid] = $discussion;
                } else {
                    mtrace('Could not find discussion '.$discussionid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $anonforumid = $discussions[$discussionid]->anonforum;
            if (!isset($anonforums[$anonforumid])) {
                if ($anonforum = $DB->get_record('anonforum', array('id' => $anonforumid))) {
                    $anonforums[$anonforumid] = $anonforum;
                } else {
                    mtrace('Could not find anonforum '.$anonforumid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $anonforums[$anonforumid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$anonforumid])) {
                if ($cm = get_coursemodule_from_instance('anonforum', $anonforumid, $courseid)) {
                    $coursemodules[$anonforumid] = $cm;
                } else {
                    mtrace('Could not find course module for anonforum '.$anonforumid);
                    unset($posts[$pid]);
                    continue;
                }
            }


            // caching subscribed users of each anonymous forum
            if (!isset($subscribedusers[$anonforumid])) {
                $modcontext = context_module::instance($coursemodules[$anonforumid]->id);
                if ($subusers = anonforum_subscribed_users($courses[$courseid], $anonforums[$anonforumid], 0, $modcontext, "u.*")) {
                    foreach ($subusers as $postuser) {
                        // this user is subscribed to this anonymous forum
                        $subscribedusers[$anonforumid][$postuser->id] = $postuser->id;
                        $userscount++;
                        if ($userscount > ANONFORUM_CRON_USER_CACHE) {
                            // Store minimal user info.
                            $minuser = new stdClass();
                            $minuser->id = $postuser->id;
                            $users[$postuser->id] = $minuser;
                        } else {
                            // Cache full user record.
                            anonforum_cron_minimise_user_record($postuser);
                            $users[$postuser->id] = $postuser;
                        }
                    }
                    // Release memory.
                    unset($subusers);
                    unset($postuser);
                }
            }

            $mailcount[$pid] = 0;
            $errorcount[$pid] = 0;
        }
    }

    if ($users && $posts) {

        $urlinfo = parse_url($CFG->wwwroot);
        $hostname = $urlinfo['host'];

        foreach ($users as $userto) {

            @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

            mtrace('Processing user '.$userto->id);

            // Init user caches - we keep the cache for one cycle only,
            // otherwise it could consume too much memory.
            if (isset($userto->username)) {
                $userto = clone($userto);
            } else {
                $userto = $DB->get_record('user', array('id' => $userto->id));
                anonforum_cron_minimise_user_record($userto);
            }
            $userto->viewfullnames = array();
            $userto->canpost       = array();
            $userto->markposts     = array();

            // set this so that the capabilities are cached, and environment matches receiving user
            cron_setup_user($userto);

            // reset the caches
            foreach ($coursemodules as $anonforumid=>$unused) {
                $coursemodules[$anonforumid]->cache       = new stdClass();
                $coursemodules[$anonforumid]->cache->caps = array();
                unset($coursemodules[$anonforumid]->uservisible);
            }

            foreach ($posts as $pid => $post) {

                // Set up the environment for the post, discussion, anonymous forum, course
                $discussion = $discussions[$post->discussion];
                $anonforum      = $anonforums[$discussion->anonforum];
                $course     = $courses[$anonforum->course];
                $cm         =& $coursemodules[$anonforum->id];

                // Do some checks  to see if we can bail out now
                // Only active enrolled users are in the list of subscribers
                if (!isset($subscribedusers[$anonforum->id][$userto->id])) {
                    continue; // user does not subscribe to this anonymous forum
                }

                // Don't send email if the anonymous forum is Q&A and the user has not posted
                // Initial topics are still mailed
                if ($anonforum->type == 'qanda' && !anonforum_get_user_posted_time($discussion->id, $userto->id) && $pid != $discussion->firstpost) {
                    mtrace('Did not email '.$userto->id.' because user has not posted in discussion');
                    continue;
                }

                // Get info about the sending user
                if (array_key_exists($post->userid, $users)) { // we might know him/her already
                    $userfrom = $users[$post->userid];
                    if (!isset($userfrom->idnumber)) {
                        // Minimalised user info, fetch full record.
                        $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                        anonforum_cron_minimise_user_record($userfrom);
                    }

                } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                    anonforum_cron_minimise_user_record($userfrom);
                    // Fetch only once if possible, we can add it to user list, it will be skipped anyway.
                    if ($userscount <= ANONFORUM_CRON_USER_CACHE) {
                        $userscount++;
                        $users[$userfrom->id] = $userfrom;
                    }

                } else {
                    mtrace('Could not find user '.$post->userid);
                    continue;
                }

                //if we want to check that userto and userfrom are not the same person this is probably the spot to do it

                // setup global $COURSE properly - needed for roles and languages
                cron_setup_user($userto, $course);

                // Fill caches
                if (!isset($userto->viewfullnames[$anonforum->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->viewfullnames[$anonforum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->canpost[$discussion->id] = anonforum_user_can_post($anonforum, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$anonforum->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        if (isset($users[$userfrom->id])) {
                            $users[$userfrom->id]->groups = array();
                        }
                    }
                    $userfrom->groups[$anonforum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    if (isset($users[$userfrom->id])) {
                        $users[$userfrom->id]->groups[$anonforum->id] = $userfrom->groups[$anonforum->id];
                    }
                }

                // Make sure groups allow this user to see this email
                if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
                    if (!groups_group_exists($discussion->groupid)) { // Can't find group
                        continue;                           // Be safe and don't send it to anyone
                    }

                    if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                        // do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
                        continue;
                    }
                }

                // Make sure we're allowed to see it...
                if (!anonforum_user_can_see_post($anonforum, $discussion, $post, NULL, $cm)) {
                    mtrace('user '.$userto->id. ' can not see '.$post->id);
                    continue;
                }

                // OK so we need to send the email.

                // Does the user want this post in a digest?  If so postpone it for now.
                $maildigest = anonforum_get_user_maildigest_bulk($digests, $userto, $anonforum->id);

                if ($maildigest > 0) {
                    // This user wants the mails to be in digest form
                    $queue = new stdClass();
                    $queue->userid       = $userto->id;
                    $queue->discussionid = $discussion->id;
                    $queue->postid       = $post->id;
                    $queue->timemodified = $post->created;
                    $DB->insert_record('anonforum_queue', $queue);
                    continue;
                }


                // Prepare to actually send the post now, and build up the content

                $cleanforumname = str_replace('"', "'", strip_tags(format_string($anonforum->name)));

                $userfrom->customheaders = array (  // Headers to make emails easier to track
                           'Precedence: Bulk',
                           'List-Id: "'.$cleanforumname.'" <moodleanonforum'.$anonforum->id.'@'.$hostname.'>',
                           'List-Help: '.$CFG->wwwroot.'/mod/anonforum/view.php?f='.$anonforum->id,
                           'Message-ID: '.anonforum_get_email_message_id($post->id, $userto->id, $hostname),
                           'X-Course-Id: '.$course->id,
                           'X-Course-Name: '.format_string($course->fullname, true)
                );

                if ($post->parent) {  // This post is a reply, so add headers for threading (see MDL-22551)
                    $userfrom->customheaders[] = 'In-Reply-To: '.anonforum_get_email_message_id($post->parent, $userto->id, $hostname);
                    $userfrom->customheaders[] = 'References: '.anonforum_get_email_message_id($post->parent, $userto->id, $hostname);
                }

                $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

                $postsubject = html_to_text("$shortname: ".format_string($post->subject, true));
                $posttext = anonforum_make_mail_text($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto);
                $posthtml = anonforum_make_mail_html($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto);

                // Send the post now!

                mtrace('Sending ', '');

                $eventdata = new stdClass();
                $eventdata->component        = 'mod_anonforum';
                $eventdata->name             = 'posts';
                $eventdata->userfrom         = $userfrom;
                $eventdata->userto           = $userto;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $posttext;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $posthtml;
                $eventdata->notification = 1;

                // If anonymous forum_replytouser is not set then send mail using the noreplyaddress.
                // If the post is anonymous then use the no reply address
                if (empty($CFG->anonforum_replytouser)) {
                    // Clone userfrom as it is referenced by $users.
                    $cloneduserfrom = clone($userfrom);
                    $cloneduserfrom->email = $CFG->noreplyaddress;
                    $eventdata->userfrom = $cloneduserfrom;
                }

                if (!empty($post->anonymouspost)) {
                    // Clone userfrom as it is referenced by $users.
                    $cloneduserfrom = clone($userfrom);
                    $cloneduserfrom->email = $CFG->noreplyaddress;
                    $cloneduserfrom->firstname = get_string('anonymoususer', 'anonforum');
                    $cloneduserfrom->lastname = '';
                    $eventdata->userfrom = $cloneduserfrom;
                }

                $smallmessagestrings = new stdClass();
                $smallmessagestrings->user = empty($post->anonymouspost) ? fullname($userfrom) : get_string('anonymoususer', 'anonforum');
                $smallmessagestrings->anonforumname = "$shortname: ".format_string($anonforum->name, true).": ".$discussion->name;
                $smallmessagestrings->message = $post->message;
                //make sure strings are in message recipients language
                $eventdata->smallmessage = get_string_manager()->get_string('smallmessage', 'anonforum', $smallmessagestrings, $userto->lang);

                $eventdata->contexturl = "{$CFG->wwwroot}/mod/anonforum/discuss.php?d={$discussion->id}#p{$post->id}";
                $eventdata->contexturlname = $discussion->name;

                $mailresult = message_send($eventdata);
                if (!$mailresult){
                    mtrace("Error: mod/anonforum/lib.php anonforum_cron(): Could not send out mail for id $post->id to user $userto->id".
                         " ($userto->email) .. not trying again.");
                    if (empty($post->anonymouspost)) {
                        add_to_log($course->id, 'anonforum', 'mail error', "discuss.php?d=$discussion->id#p$post->id",
                            substr(format_string($post->subject, true), 0, 30), $cm->id, $userto->id);
                    }
                    $errorcount[$post->id]++;
                } else {
                    $mailcount[$post->id]++;

                // Mark post as read if anonymous forum_usermarksread is set off
                    if (!$CFG->anonforum_usermarksread) {
                        $userto->markposts[$post->id] = $post->id;
                    }
                }

                mtrace('post '.$post->id. ': '.$post->subject);
            }

            // mark processed posts as read
            anonforum_tp_mark_posts_read($userto, $userto->markposts);
            unset($userto);
        }
    }

    if ($posts) {
        foreach ($posts as $post) {
            mtrace($mailcount[$post->id]." users were sent post $post->id, '$post->subject'");
            if ($errorcount[$post->id]) {
                $DB->set_field('anonforum_posts', 'mailed', ANONFORUM_MAILED_ERROR, array('id' => $post->id));
            }
        }
    }

    // release some memory
    unset($subscribedusers);
    unset($mailcount);
    unset($errorcount);

    cron_setup_user();

    $sitetimezone = $CFG->timezone;

    // Now see if there are any digest mails waiting to be sent, and if we should send them

    mtrace('Starting digest processing...');

    @set_time_limit(300); // terminate if not able to fetch all digests in 5 minutes

    if (!isset($CFG->digestmailtimelast)) {    // To catch the first time
        set_config('digestmailtimelast', 0);
    }

    $timenow = time();
    $digesttime = usergetmidnight($timenow, $sitetimezone) + ($CFG->digestmailtime * 3600);

    // Delete any really old ones (normally there shouldn't be any)
    $weekago = $timenow - (7 * 24 * 3600);
    $DB->delete_records_select('anonforum_queue', "timemodified < ?", array($weekago));
    mtrace ('Cleaned old digest records');

    if ($CFG->digestmailtimelast < $digesttime and $timenow > $digesttime) {

        mtrace('Sending anonforum digests: '.userdate($timenow, '', $sitetimezone));

        $digestposts_rs = $DB->get_recordset_select('anonforum_queue', "timemodified < ?", array($digesttime));

        if ($digestposts_rs->valid()) {

            // We have work to do
            $usermailcount = 0;

            //caches - reuse the those filled before too
            $discussionposts = array();
            $userdiscussions = array();

            foreach ($digestposts_rs as $digestpost) {
                if (!isset($posts[$digestpost->postid])) {
                    if ($post = $DB->get_record('anonforum_posts', array('id' => $digestpost->postid))) {
                        $posts[$digestpost->postid] = $post;
                    } else {
                        continue;
                    }
                }
                $discussionid = $digestpost->discussionid;
                if (!isset($discussions[$discussionid])) {
                    if ($discussion = $DB->get_record('anonforum_discussions', array('id' => $discussionid))) {
                        $discussions[$discussionid] = $discussion;
                    } else {
                        continue;
                    }
                }
                $anonforumid = $discussions[$discussionid]->anonforum;
                if (!isset($anonforums[$anonforumid])) {
                    if ($anonforum = $DB->get_record('anonforum', array('id' => $anonforumid))) {
                        $anonforums[$anonforumid] = $anonforum;
                    } else {
                        continue;
                    }
                }

                $courseid = $anonforums[$anonforumid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$anonforumid])) {
                    if ($cm = get_coursemodule_from_instance('anonforum', $anonforumid, $courseid)) {
                        $coursemodules[$anonforumid] = $cm;
                    } else {
                        continue;
                    }
                }
                $userdiscussions[$digestpost->userid][$digestpost->discussionid] = $digestpost->discussionid;
                $discussionposts[$digestpost->discussionid][$digestpost->postid] = $digestpost->postid;
            }
            $digestposts_rs->close(); /// Finished iteration, let's close the resultset

            // Data collected, start sending out emails to each user
            foreach ($userdiscussions as $userid => $thesediscussions) {

                @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

                cron_setup_user();

                mtrace(get_string('processingdigest', 'anonforum', $userid), '... ');

                // First of all delete all the queue entries for this user
                $DB->delete_records_select('anonforum_queue', "userid = ? AND timemodified < ?", array($userid, $digesttime));

                // Init user caches - we keep the cache for one cycle only,
                // otherwise it would unnecessarily consume memory.
                if (array_key_exists($userid, $users) and isset($users[$userid]->username)) {
                    $userto = clone($users[$userid]);
                } else {
                    $userto = $DB->get_record('user', array('id' => $userid));
                    anonforum_cron_minimise_user_record($userto);
                }
                $userto->viewfullnames = array();
                $userto->canpost       = array();
                $userto->markposts     = array();

                // Override the language and timezone of the "current" user, so that
                // mail is customised for the receiver.
                cron_setup_user($userto);

                $postsubject = get_string('digestmailsubject', 'anonforum', format_string($site->shortname, true));

                $headerdata = new stdClass();
                $headerdata->sitename = format_string($site->fullname, true);
                $headerdata->userprefs = $CFG->wwwroot.'/user/edit.php?id='.$userid.'&amp;course='.$site->id;

                $posttext = get_string('digestmailheader', 'anonforum', $headerdata)."\n\n";
                $headerdata->userprefs = '<a target="_blank" href="'.$headerdata->userprefs.'">'.get_string('digestmailprefs', 'anonforum').'</a>';

                $posthtml = "<head>";
/*                foreach ($CFG->stylesheets as $stylesheet) {
                    //TODO: MDL-21120
                    $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
                }*/
                $posthtml .= "</head>\n<body id=\"email\">\n";
                $posthtml .= '<p>'.get_string('digestmailheader', 'anonforum', $headerdata).'</p><br /><hr size="1" noshade="noshade" />';

                foreach ($thesediscussions as $discussionid) {

                    @set_time_limit(120);   // to be reset for each post

                    $discussion = $discussions[$discussionid];
                    $anonforum      = $anonforums[$discussion->anonforum];
                    $course     = $courses[$anonforum->course];
                    $cm         = $coursemodules[$anonforum->id];

                    //override language
                    cron_setup_user($userto, $course);

                    // Fill caches
                    if (!isset($userto->viewfullnames[$anonforum->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->viewfullnames[$anonforum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                    }
                    if (!isset($userto->canpost[$discussion->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->canpost[$discussion->id] = anonforum_user_can_post($anonforum, $discussion, $userto, $cm, $course, $modcontext);
                    }

                    $stranonforums      = get_string('anonforums', 'anonforum');
                    $canunsubscribe = ! anonforum_is_forcesubscribed($anonforum);
                    $canreply       = $userto->canpost[$discussion->id];
                    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

                    $posttext .= "\n \n";
                    $posttext .= '=====================================================================';
                    $posttext .= "\n \n";
                    $posttext .= "$shortname -> $stranonforums -> ".format_string($anonforum->name,true);
                    if ($discussion->name != $anonforum->name) {
                        $posttext  .= " -> ".format_string($discussion->name,true);
                    }
                    $posttext .= "\n";
                    $posttext .= $CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$discussion->id;
                    $posttext .= "\n";

                    $posthtml .= "<p><font face=\"sans-serif\">".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$shortname</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/anonforum/index.php?id=$course->id\">$stranonforums</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/anonforum/view.php?f=$anonforum->id\">".format_string($anonforum->name,true)."</a>";
                    if ($discussion->name == $anonforum->name) {
                        $posthtml .= "</font></p>";
                    } else {
                        $posthtml .= " -> <a target=\"_blank\" href=\"$CFG->wwwroot/mod/anonforum/discuss.php?d=$discussion->id\">".format_string($discussion->name,true)."</a></font></p>";
                    }
                    $posthtml .= '<p>';

                    $postsarray = $discussionposts[$discussionid];
                    sort($postsarray);

                    foreach ($postsarray as $postid) {
                        $post = $posts[$postid];

                        if (array_key_exists($post->userid, $users)) { // we might know him/her already
                            $userfrom = $users[$post->userid];
                            if (!isset($userfrom->idnumber)) {
                                $userfrom = $DB->get_record('user', array('id' => $userfrom->id));
                                anonforum_cron_minimise_user_record($userfrom);
                            }

                        } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                            anonforum_cron_minimise_user_record($userfrom);
                            if ($userscount <= ANONFORUM_CRON_USER_CACHE) {
                                $userscount++;
                                $users[$userfrom->id] = $userfrom;
                            }

                        } else {
                            mtrace('Could not find user '.$post->userid);
                            continue;
                        }

                        if (!isset($userfrom->groups[$anonforum->id])) {
                            if (!isset($userfrom->groups)) {
                                $userfrom->groups = array();
                                if (isset($users[$userfrom->id])) {
                                    $users[$userfrom->id]->groups = array();
                                }
                            }
                            $userfrom->groups[$anonforum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                            if (isset($users[$userfrom->id])) {
                                $users[$userfrom->id]->groups[$anonforum->id] = $userfrom->groups[$anonforum->id];
                            }
                        }

                        $userfrom->customheaders = array ("Precedence: Bulk");

                        $maildigest = anonforum_get_user_maildigest_bulk($digests, $userto, $anonforum->id);
                        if ($maildigest == 2) {
                            // Subjects and link only
                            $posttext .= "\n";
                            $posttext .= $CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$discussion->id;
                            $by = new stdClass();
                            $by->date = userdate($post->modified);
                            if (empty($post->anonymouspost)) {
                                $by->name = "<a target=\"_blank\" href=\"$CFG->wwwroot/user/view.php?id=$userfrom->id&amp;course=$course->id\">fullname($userfrom);</a>";
                            } else {
                                $by->name = get_string('anonymoususer', 'anonforum');
                            }
                            $posttext .= "\n".format_string($post->subject,true).' '.get_string("bynameondate", "anonforum", $by);
                            $posttext .= "\n---------------------------------------------------------------------";

                            $posthtml .= '<div><a target="_blank" href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$discussion->id.'#p'.$post->id.'">'.format_string($post->subject,true).'</a> '.get_string("bynameondate", "anonforum", $by).'</div>';

                        } else {
                            // The full treatment
                            $posttext .= anonforum_make_mail_text($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto, true);
                            $posthtml .= anonforum_make_mail_post($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

                        // Create an array of postid's for this user to mark as read.
                            if (!$CFG->anonforum_usermarksread) {
                                $userto->markposts[$post->id] = $post->id;
                            }
                        }
                    }
                    $footerlinks = array();
                    if ($canunsubscribe) {
                        $footerlinks[] = "<a href=\"$CFG->wwwroot/mod/anonforum/subscribe.php?id=$anonforum->id\">" . get_string("unsubscribe", "anonforum") . "</a>";
                    } else {
                        $footerlinks[] = get_string("everyoneissubscribed", "anonforum");
                    }
                    $footerlinks[] = "<a href='{$CFG->wwwroot}/mod/anonforum/index.php?id={$anonforum->course}'>" . get_string("digestmailpost", "anonforum") . '</a>';
                    $posthtml .= "\n<div class='mdl-right'><font size=\"1\">" . implode('&nbsp;', $footerlinks) . '</font></div>';
                    $posthtml .= '<hr size="1" noshade="noshade" /></p>';
                }
                $posthtml .= '</body>';

                if (empty($userto->mailformat) || $userto->mailformat != 1) {
                    // This user DOESN'T want to receive HTML
                    $posthtml = '';
                }

                $attachment = $attachname='';
                // Directly email anonymous forum digests rather than sending them via messaging, use the
                // site shortname as 'from name', the noreply address will be used by email_to_user.
                $mailresult = email_to_user($userto, $site->shortname, $postsubject, $posttext, $posthtml, $attachment, $attachname);

                if (!$mailresult) {
                    mtrace("ERROR!");
                    echo "Error: mod/anonforum/cron.php: Could not send out digest mail to user $userto->id ($userto->email)... not trying again.\n";
                    if (empty($post->anonymouspost)) {
                        add_to_log($course->id, 'anonforum', 'mail digest error', '', '', $cm->id, $userto->id);
                    }
                } else {
                    mtrace("success.");
                    $usermailcount++;

                    // Mark post as read if anonymous forum_usermarksread is set off
                    anonforum_tp_mark_posts_read($userto, $userto->markposts);
                }
            }
        }
    /// We have finishied all digest emails, update $CFG->digestmailtimelast
        set_config('digestmailtimelast', $timenow);
    }

    cron_setup_user();

    if (!empty($usermailcount)) {
        mtrace(get_string('digestsentusers', 'anonforum', $usermailcount));
    }

    if (!empty($CFG->anonforum_lastreadclean)) {
        $timenow = time();
        if ($CFG->anonforum_lastreadclean + (24*3600) < $timenow) {
            set_config('anonforum_lastreadclean', $timenow);
            mtrace('Removing old anonforum read tracking info...');
            anonforum_tp_clean_read_records();
        }
    } else {
        set_config('anonforum_lastreadclean', time());
    }


    return true;
}

/**
 * Builds and returns the body of the email notification in plain text.
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param object $course
 * @param object $cm
 * @param object $anonforum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @param boolean $bare
 * @return string The email body in plain text format.
 */
function anonforum_make_mail_text($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto, $bare = false) {
    global $CFG, $USER;

    $modcontext = context_module::instance($cm->id);

    if (!isset($userto->viewfullnames[$anonforum->id])) {
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$anonforum->id];
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = anonforum_user_can_post($anonforum, $discussion, $userto, $cm, $course, $modcontext);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $by = New stdClass;
    if (empty($post->anonymouspost)) {
        $by->name = fullname($userfrom, $viewfullnames);
    } else {
        $by->name = get_string('anonymoususer', 'anonforum');
    }

    $by->date = userdate($post->modified, "", $userto->timezone);

    $strbynameondate = get_string('bynameondate', 'anonforum', $by);

    $stranonforums = get_string('anonforums', 'anonforum');

    $canunsubscribe = ! anonforum_is_forcesubscribed($anonforum);

    $posttext = '';

    if (!$bare) {
        $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));
        $posttext  = "$shortname -> $stranonforums -> ".format_string($anonforum->name,true);

        if ($discussion->name != $anonforum->name) {
            $posttext  .= " -> ".format_string($discussion->name,true);
        }
    }

    // add absolute file links
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_anonforum', 'post', $post->id);

    $posttext .= "\n";
    $posttext .= $CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$discussion->id;
    $posttext .= "\n---------------------------------------------------------------------\n";
    $posttext .= format_string($post->subject,true);
    if ($bare) {
        $posttext .= " ($CFG->wwwroot/mod/anonforum/discuss.php?d=$discussion->id#p$post->id)";
    }
    $posttext .= "\n".$strbynameondate."\n";
    $posttext .= "---------------------------------------------------------------------\n";
    $posttext .= format_text_email($post->message, $post->messageformat);
    $posttext .= "\n\n";
    $posttext .= anonforum_print_attachments($post, $cm, "text");

    if (!$bare && $canreply) {
        $posttext .= "---------------------------------------------------------------------\n";
        $posttext .= get_string("postmailinfo", "anonforum", $shortname)."\n";
        $posttext .= "$CFG->wwwroot/mod/anonforum/post.php?reply=$post->id\n";
    }
    if (!$bare && $canunsubscribe) {
        $posttext .= "\n---------------------------------------------------------------------\n";
        $posttext .= get_string("unsubscribe", "anonforum");
        $posttext .= ": $CFG->wwwroot/mod/anonforum/subscribe.php?id=$anonforum->id\n";
    }

    $posttext .= "\n---------------------------------------------------------------------\n";
    $posttext .= get_string("digestmailpost", "anonforum");
    $posttext .= ": {$CFG->wwwroot}/mod/anonforum/index.php?id={$anonforum->course}\n";

    return $posttext;
}

/**
 * Builds and returns the body of the email notification in html format.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $anonforum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @return string The email text in HTML format
 */
function anonforum_make_mail_html($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto) {
    global $CFG;

    if ($userto->mailformat != 1) {  // Needs to be HTML
        return '';
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = anonforum_user_can_post($anonforum, $discussion, $userto, $cm, $course);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $stranonforums = get_string('anonforums', 'anonforum');
    $canunsubscribe = ! anonforum_is_forcesubscribed($anonforum);
    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));

    $posthtml = '<head>';
/*    foreach ($CFG->stylesheets as $stylesheet) {
        //TODO: MDL-21120
        $posthtml .= '<link rel="stylesheet" type="text/css" href="'.$stylesheet.'" />'."\n";
    }*/
    $posthtml .= '</head>';
    $posthtml .= "\n<body id=\"email\">\n\n";

    $posthtml .= '<div class="navbar">'.
    '<a target="_blank" href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id.'">'.$shortname.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/anonforum/index.php?id='.$course->id.'">'.$stranonforums.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/anonforum/view.php?f='.$anonforum->id.'">'.format_string($anonforum->name,true).'</a>';
    if ($discussion->name == $anonforum->name) {
        $posthtml .= '</div>';
    } else {
        $posthtml .= ' &raquo; <a target="_blank" href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$discussion->id.'">'.
                     format_string($discussion->name,true).'</a></div>';
    }
    $posthtml .= anonforum_make_mail_post($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

    $footerlinks = array();
    if ($canunsubscribe) {
        $footerlinks[] = '<a href="' . $CFG->wwwroot . '/mod/anonforum/subscribe.php?id=' . $anonforum->id . '">' . get_string('unsubscribe', 'anonforum') . '</a>';
        $footerlinks[] = '<a href="' . $CFG->wwwroot . '/mod/anonforum/unsubscribeall.php">' . get_string('unsubscribeall', 'anonforum') . '</a>';
    }
    $footerlinks[] = "<a href='{$CFG->wwwroot}/mod/anonforum/index.php?id={$anonforum->course}'>" . get_string('digestmailpost', 'anonforum') . '</a>';
    $posthtml .= '<hr /><div class="mdl-align unsubscribelink">' . implode('&nbsp;', $footerlinks) . '</div>';

    $posthtml .= '</body>';

    return $posthtml;
}


/**
 *
 * @param object $course
 * @param object $user
 * @param object $mod TODO this is not used in this function, refactor
 * @param object $anonforum
 * @return object A standard object with 2 variables: info (number of posts for this user) and time (last modified)
 */
function anonforum_user_outline($course, $user, $mod, $anonforum) {
    global $CFG;
    require_once("$CFG->libdir/gradelib.php");
    $grades = grade_get_grades($course->id, 'mod', 'anonforum', $anonforum->id, $user->id);
    if (empty($grades->items[0]->grades)) {
        $grade = false;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $count = anonforum_count_user_posts($anonforum->id, $user->id);

    if ($count && $count->postcount > 0) {
        $result = new stdClass();
        $result->info = get_string("numposts", "anonforum", $count->postcount);
        $result->time = $count->lastpost;
        if ($grade) {
            $result->info .= ', ' . get_string('grade') . ': ' . $grade->str_long_grade;
        }
        return $result;
    } else if ($grade) {
        $result = new stdClass();
        $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

        //datesubmitted == time created. dategraded == time modified or time overridden
        //if grade was last modified by the user themselves use date graded. Otherwise use date submitted
        //TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704
        if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
            $result->time = $grade->dategraded;
        } else {
            $result->time = $grade->datesubmitted;
        }

        return $result;
    }
    return NULL;
}


/**
 * @global object
 * @global object
 * @param object $coure
 * @param object $user
 * @param object $mod
 * @param object $anonforum
 */
function anonforum_user_complete($course, $user, $mod, $anonforum) {
    global $CFG,$USER, $OUTPUT;
    require_once("$CFG->libdir/gradelib.php");

    $grades = grade_get_grades($course->id, 'mod', 'anonforum', $anonforum->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($posts = anonforum_get_user_posts($anonforum->id, $user->id)) {

        if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $discussions = anonforum_get_user_involved_discussions($anonforum->id, $user->id);

        foreach ($posts as $post) {
            if (!isset($discussions[$post->discussion])) {
                continue;
            }
            $discussion = $discussions[$post->discussion];

            anonforum_print_post($post, $discussion, $anonforum, $cm, $course, false, false, false);
        }
    } else {
        echo "<p>".get_string("noposts", "anonforum")."</p>";
    }
}






/**
 * @global object
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray
 */
function anonforum_print_overview($courses,&$htmlarray) {
    global $USER, $CFG, $DB, $SESSION;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$anonforums = get_all_instances_in_courses('anonforum',$courses)) {
        return;
    }

    // Courses to search for new posts
    $coursessqls = array();
    $params = array();
    foreach ($courses as $course) {

        // If the user has never entered into the course all posts are pending
        if ($course->lastaccess == 0) {
            $coursessqls[] = '(f.course = ?)';
            $params[] = $course->id;

        // Only posts created after the course last access
        } else {
            $coursessqls[] = '(f.course = ? AND p.created > ?)';
            $params[] = $course->id;
            $params[] = $course->lastaccess;
        }
    }
    $params[] = $USER->id;
    $coursessql = implode(' OR ', $coursessqls);

    $sql = "SELECT f.id, COUNT(*) as count "
                .'FROM {anonforum} f '
                .'JOIN {anonforum_discussions} d ON d.anonforum  = f.id '
                .'JOIN {anonforum_posts} p ON p.discussion = d.id '
                ."WHERE ($coursessql) "
                .'AND p.userid != ? '
                .'GROUP BY f.id';

    if (!$new = $DB->get_records_sql($sql, $params)) {
        $new = array(); // avoid warnings
    }

    // also get all anonymous forum tracking stuff ONCE.
    $trackinganonforums = array();
    foreach ($anonforums as $anonforum) {
        if (anonforum_tp_can_track_anonforums($anonforum)) {
            $trackinganonforums[$anonforum->id] = $anonforum;
        }
    }

    if (count($trackinganonforums) > 0) {
        $cutoffdate = isset($CFG->anonforum_oldpostdays) ? (time() - ($CFG->anonforum_oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.anonforum,d.course,COUNT(p.id) AS count '.
            ' FROM {anonforum_posts} p '.
            ' JOIN {anonforum_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {anonforum_read} r ON r.postid = p.id AND r.userid = ? WHERE (';
        $params = array($USER->id);

        foreach ($trackinganonforums as $track) {
            $sql .= '(d.anonforum = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
            $params[] = $track->id;
            if (isset($SESSION->currentgroup[$track->course])) {
                $groupid =  $SESSION->currentgroup[$track->course];
            } else {
                // get first groupid
                $groupids = groups_get_all_groups($track->course, $USER->id);
                if ($groupids) {
                    reset($groupids);
                    $groupid = key($groupids);
                    $SESSION->currentgroup[$track->course] = $groupid;
                } else {
                    $groupid = 0;
                }
                unset($groupids);
            }
            $params[] = $groupid;
        }
        $sql = substr($sql,0,-3); // take off the last OR
        $sql .= ') AND p.modified >= ? AND r.id is NULL GROUP BY d.anonforum,d.course';
        $params[] = $cutoffdate;

        if (!$unread = $DB->get_records_sql($sql, $params)) {
            $unread = array();
        }
    } else {
        $unread = array();
    }

    if (empty($unread) and empty($new)) {
        return;
    }

    $stranonforum = get_string('modulename','anonforum');

    foreach ($anonforums as $anonforum) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($anonforum->id, $new) && !empty($new[$anonforum->id])) {
            $count = $new[$anonforum->id]->count;
        }
        if (array_key_exists($anonforum->id,$unread)) {
            $thisunread = $unread[$anonforum->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview anonforum"><div class="name">'.$stranonforum.': <a title="'.$stranonforum.'" href="'.$CFG->wwwroot.'/mod/anonforum/view.php?f='.$anonforum->id.'">'.
                $anonforum->name.'</a></div>';
            $str .= '<div class="info"><span class="postsincelogin">';
            $str .= get_string('overviewnumpostssince', 'anonforum', $count)."</span>";
            if (!empty($showunread)) {
                $str .= '<div class="unreadposts">'.get_string('overviewnumunread', 'anonforum', $thisunread).'</div>';
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($anonforum->course,$htmlarray)) {
                $htmlarray[$anonforum->course] = array();
            }
            if (!array_key_exists('anonforum',$htmlarray[$anonforum->course])) {
                $htmlarray[$anonforum->course]['anonforum'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$anonforum->course]['anonforum'] .= $str;
        }
    }
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return bool success
 */
function anonforum_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    // do not use log table if possible, it may be huge and is expensive to join with other tables

    $allnamefields = user_picture::fields('u', null, 'duserid');
    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS anonforumtype, d.anonforum, d.groupid,
                                              d.timestart, d.timeend, $allnamefields
                                         FROM {anonforum_posts} p
                                              JOIN {anonforum_discussions} d ON d.id = p.discussion
                                              JOIN {anonforum} f             ON f.id = d.anonforum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.course = ? AND p.anonymouspost = 0
                                     ORDER BY p.id ASC", array($timestart, $course->id))) { // order by initial posting date
         return false;
    }

    $modinfo = get_fast_modinfo($course);

    $groupmodes = array();
    $cms    = array();

    $strftimerecent = get_string('strftimerecent');

    $printposts = array();
    foreach ($posts as $post) {
        if (!isset($modinfo->instances['anonforum'][$post->anonforum])) {
            // not visible
            continue;
        }
        $cm = $modinfo->instances['anonforum'][$post->anonforum];
        if (!$cm->uservisible) {
            continue;
        }
        $context = context_module::instance($cm->id);

        if (!has_capability('mod/anonforum:viewdiscussion', $context)) {
            continue;
        }

        if (!empty($CFG->anonforum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!has_capability('mod/anonforum:viewhiddentimedposts', $context)) {
                continue;
            }
        }

        $groupmode = groups_get_activity_groupmode($cm, $course);

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $context)) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (!in_array($post->groupid, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }
    unset($posts);

    if (!$printposts) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newanonforumposts', 'anonforum').':', 3);
    echo "\n<ul class='unlist'>\n";

    foreach ($printposts as $post) {
        $subjectclass = empty($post->parent) ? ' bold' : '';

        if (empty($post->anonymouspost)) {
            $name = fullname($post, $viewfullnames);
        }  else {
            $name = get_string('anonymoususer', 'anonforum');
        }


        echo '<li><div class="head">'.
               '<div class="date">'.userdate($post->modified, $strftimerecent).'</div>'.
               '<div class="name">'.$name.'</div>'.
             '</div>';
        echo '<div class="info'.$subjectclass.'">';
        if (empty($post->parent)) {
            echo '"<a href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'">';
        } else {
            echo '"<a href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'&amp;parent='.$post->parent.'#p'.$post->id.'">';
        }
        $post->subject = break_up_long_words(format_string($post->subject, true));
        echo $post->subject;
        echo "</a>\"</div></li>\n";
    }

    echo "</ul>\n";

    return true;
}

/**
 * Return grade for given user or all users.
 *
 * @global object
 * @global object
 * @param object $anonforum
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function anonforum_get_user_grades($anonforum, $userid = 0) {
    global $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ratingoptions = new stdClass;
    $ratingoptions->component = 'mod_anonforum';
    $ratingoptions->ratingarea = 'post';

    //need these to work backwards to get a context id. Is there a better way to get contextid from a module instance?
    $ratingoptions->modulename = 'anonforum';
    $ratingoptions->moduleid   = $anonforum->id;
    $ratingoptions->userid = $userid;
    $ratingoptions->aggregationmethod = $anonforum->assessed;
    $ratingoptions->scaleid = $anonforum->scale;
    $ratingoptions->itemtable = 'anonforum_posts';
    $ratingoptions->itemtableusercolumn = 'userid';

    $rm = new rating_manager();
    return $rm->get_user_grades($ratingoptions);
}

/**
 * Update activity grades
 *
 * @category grade
 * @param object $anonforum
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function anonforum_update_grades($anonforum, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if (!$anonforum->assessed) {
        anonforum_grade_item_update($anonforum);

    } else if ($grades = anonforum_get_user_grades($anonforum, $userid)) {
        anonforum_grade_item_update($anonforum, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        anonforum_grade_item_update($anonforum, $grade);

    } else {
        anonforum_grade_item_update($anonforum);
    }
}

/**
 * Update all grades in gradebook.
 * @global object
 */
function anonforum_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {anonforum} f, {course_modules} cm, {modules} m
             WHERE m.name='anonforum' AND m.id=cm.module AND cm.instance=f.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT f.*, cm.idnumber AS cmidnumber, f.course AS courseid
              FROM {anonforum} f, {course_modules} cm, {modules} m
             WHERE m.name='anonforum' AND m.id=cm.module AND cm.instance=f.id";
    $rs = $DB->get_recordset_sql($sql);
    if ($rs->valid()) {
        $pbar = new progress_bar('anonforumupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $anonforum) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            anonforum_update_grades($anonforum, 0, false);
            $pbar->update($i, $count, "Updating Forum grades ($i/$count).");
        }
    }
    $rs->close();
}

/**
 * Create/update grade item for given anonforum
 *
 * @category grade
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param stdClass $anonforum Forum object with extra cmidnumber
 * @param mixed $grades Optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function anonforum_grade_item_update($anonforum, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = array('itemname'=>$anonforum->name, 'idnumber'=>$anonforum->cmidnumber);

    if (!$anonforum->assessed or $anonforum->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($anonforum->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $anonforum->scale;
        $params['grademin']  = 0;

    } else if ($anonforum->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$anonforum->scale;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/anonforum', $anonforum->course, 'mod', 'anonforum', $anonforum->id, 0, $grades, $params);
}

/**
 * Delete grade item for given anonforum
 *
 * @category grade
 * @param stdClass $anonforum Forum object
 * @return grade_item
 */
function anonforum_grade_item_delete($anonforum) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/anonforum', $anonforum->course, 'mod', 'anonforum', $anonforum->id, 0, NULL, array('deleted'=>1));
}


/**
 * This function returns if a scale is being used by one anonforum
 *
 * @global object
 * @param int $anonforumid
 * @param int $scaleid negative number
 * @return bool
 */
function anonforum_scale_used ($anonforumid,$scaleid) {
    global $DB;
    $return = false;

    $rec = $DB->get_record("anonforum",array("id" => "$anonforumid","scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of anonforum
 *
 * This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any anonforum
 */
function anonforum_scale_used_anywhere($scaleid) {
    global $DB;
    if ($scaleid and $DB->record_exists('anonforum', array('scale' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

// SQL FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Gets a post with all info ready for anonforum_print_post
 * Most of these joins are just to get the anonforum id
 *
 * @global object
 * @global object
 * @param int $postid
 * @return mixed array of posts or false
 */
function anonforum_get_post_full($postid) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_record_sql("SELECT p.*, d.anonforum, $allnames, u.email, u.picture, u.imagealt
                             FROM {anonforum_posts} p
                                  JOIN {anonforum_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                            WHERE p.id = ?", array($postid));
}

/**
 * Gets posts with all info ready for anonforum_print_post
 * We pass anonforumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @return mixed array of posts or false
 */
function anonforum_get_discussion_posts($discussion, $sort, $anonforumid) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_records_sql("SELECT p.*, $anonforumid AS anonforum, $allnames, u.email, u.picture, u.imagealt
                              FROM {anonforum_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.discussion = ?
                               AND p.parent > 0 $sort", array($discussion));
}

/**
 * Gets all posts in discussion including top parent.
 *
 * @global object
 * @global object
 * @global object
 * @param int $discussionid
 * @param string $sort
 * @param bool $tracking does user track the anonforum?
 * @return array of posts
 */
function anonforum_get_all_discussion_posts($discussionid, $sort, $tracking=false) {
    global $CFG, $DB, $USER;

    $tr_sel  = "";
    $tr_join = "";
    $params = array();

    if ($tracking) {
        $now = time();
        $cutoffdate = $now - ($CFG->anonforum_oldpostdays * 24 * 3600);
        $tr_sel  = ", fr.id AS postread";
        $tr_join = "LEFT JOIN {anonforum_read} fr ON (fr.postid = p.id AND fr.userid = ?)";
        $params[] = $USER->id;
    }

    $allnames = get_all_user_name_fields(true, 'u');
    $params[] = $discussionid;
    if (!$posts = $DB->get_records_sql("SELECT p.*, $allnames, u.email, u.picture, u.imagealt $tr_sel
                                     FROM {anonforum_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          $tr_join
                                    WHERE p.discussion = ?
                                 ORDER BY $sort", $params)) {
        return array();
    }

    foreach ($posts as $pid=>$p) {
        if ($tracking) {
            if (anonforum_tp_is_post_old($p)) {
                 $posts[$pid]->postread = true;
            }
        }
        if (!$p->parent) {
            continue;
        }
        if (!isset($posts[$p->parent])) {
            continue; // parent does not exist??
        }
        if (!isset($posts[$p->parent]->children)) {
            $posts[$p->parent]->children = array();
        }
        $posts[$p->parent]->children[$pid] =& $posts[$pid];

        if (!empty($p->anonymouspost)) {
            $p->firstname = get_string('anonymoususer', 'anonforum');
            $p->lastname = '';
            $p->email = '';
        }
    }

    return $posts;
}

/**
 * Gets posts with all info ready for anonforum_print_post
 * We pass anonforumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @param int $parent
 * @param int $anonforumid
 * @return array
 */
function anonforum_get_child_posts($parent, $anonforumid) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_records_sql("SELECT p.*, $anonforumid AS anonforum, $allnames, u.email, u.picture, u.imagealt
                              FROM {anonforum_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.parent = ?
                          ORDER BY p.created ASC", array($parent));
}

/**
 * An array of anonforum objects that the user is allowed to read/search through.
 *
 * @global object
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid if 0, we look for anonforums throughout the whole site.
 * @return array of anonforum objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function anonforum_get_readable_anonforums($userid, $courseid=0) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');

    if (!$anonforummod = $DB->get_record('modules', array('name' => 'anonforum'))) {
        print_error('notinstalled', 'anonforum');
    }

    if ($courseid) {
        $courses = $DB->get_records('course', array('id' => $courseid));
    } else {
        // If no course is specified, then the user can see SITE + his courses.
        $courses1 = $DB->get_records('course', array('id' => SITEID));
        $courses2 = enrol_get_users_courses($userid, true, array('modinfo'));
        $courses = array_merge($courses1, $courses2);
    }
    if (!$courses) {
        return array();
    }

    $readableanonforums = array();

    foreach ($courses as $course) {

        $modinfo = get_fast_modinfo($course);

        if (empty($modinfo->instances['anonforum'])) {
            // hmm, no anonymous forums?
            continue;
        }

        $courseanonforums = $DB->get_records('anonforum', array('course' => $course->id));

        foreach ($modinfo->instances['anonforum'] as $anonforumid => $cm) {
            if (!$cm->uservisible or !isset($courseanonforums[$anonforumid])) {
                continue;
            }
            $context = context_module::instance($cm->id);
            $anonforum = $courseanonforums[$anonforumid];
            $anonforum->context = $context;
            $anonforum->cm = $cm;

            if (!has_capability('mod/anonforum:viewdiscussion', $context)) {
                continue;
            }

         /// group access
            if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {

                $anonforum->onlygroups = $modinfo->get_groups($cm->groupingid);
                $anonforum->onlygroups[] = -1;
            }

        /// hidden timed discussions
            $anonforum->viewhiddentimedposts = true;
            if (!empty($CFG->anonforum_enabletimedposts)) {
                if (!has_capability('mod/anonforum:viewhiddentimedposts', $context)) {
                    $anonforum->viewhiddentimedposts = false;
                }
            }

        /// qanda access
            if ($anonforum->type == 'qanda'
                    && !has_capability('mod/anonforum:viewqandawithoutposting', $context)) {

                // We need to check whether the user has posted in the qanda anonymous forum.
                $anonforum->onlydiscussions = array();  // Holds discussion ids for the discussions
                                                    // the user is allowed to see in this anonymous forum.
                if ($discussionspostedin = anonforum_discussions_user_has_posted_in($anonforum->id, $USER->id)) {
                    foreach ($discussionspostedin as $d) {
                        $anonforum->onlydiscussions[] = $d->id;
                    }
                }
            }

            $readableanonforums[$anonforum->id] = $anonforum;
        }

        unset($modinfo);

    } // End foreach $courses

    return $readableanonforums;
}

/**
 * Returns a list of posts found using an array of search terms.
 *
 * @global object
 * @global object
 * @global object
 * @param array $searchterms array of search terms, e.g. word +word -word
 * @param int $courseid if 0, we search through the whole site
 * @param int $limitfrom
 * @param int $limitnum
 * @param int &$totalcount
 * @param string $extrasql
 * @return array|bool Array of posts found or false
 */
function anonforum_search_posts($searchterms, $courseid=0, $limitfrom=0, $limitnum=50,
                            &$totalcount, $extrasql='') {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir.'/searchlib.php');

    $anonforums = anonforum_get_readable_anonforums($USER->id, $courseid);

    if (count($anonforums) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = round(time(), -2); // db friendly

    $fullaccess = array();
    $where = array();
    $params = array();

    foreach ($anonforums as $anonforumid => $anonforum) {
        $select = array();

        if (!$anonforum->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid{$anonforumid} OR (d.timestart < :timestart{$anonforumid} AND (d.timeend = 0 OR d.timeend > :timeend{$anonforumid})))";
            $params = array_merge($params, array('userid'.$anonforumid=>$USER->id, 'timestart'.$anonforumid=>$now, 'timeend'.$anonforumid=>$now));
        }

        $cm = $anonforum->cm;
        $context = $anonforum->context;

        if ($anonforum->type == 'qanda'
            && !has_capability('mod/anonforum:viewqandawithoutposting', $context)) {
            if (!empty($anonforum->onlydiscussions)) {
                list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($anonforum->onlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$anonforumid.'_');
                $params = array_merge($params, $discussionid_params);
                $select[] = "(d.id $discussionid_sql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($anonforum->onlygroups)) {
            list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($anonforum->onlygroups, SQL_PARAMS_NAMED, 'grps'.$anonforumid.'_');
            $params = array_merge($params, $groupid_params);
            $select[] = "d.groupid $groupid_sql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.anonforum = :anonforum{$anonforumid} AND $selects)";
            $params['anonforum'.$anonforumid] = $anonforumid;
        } else {
            $fullaccess[] = $anonforumid;
        }
    }

    if ($fullaccess) {
        list($fullid_sql, $fullid_params) = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula');
        $params = array_merge($params, $fullid_params);
        $where[] = "(d.anonforum $fullid_sql)";
    }

    $selectdiscussion = "(".implode(" OR ", $where).")";

    $messagesearch = '';
    $searchstring = '';

    // Need to concat these back together for parser to work.
    foreach($searchterms as $searchterm){
        if ($searchstring != '') {
            $searchstring .= ' ';
        }
        $searchstring .= $searchterm;
    }

    // We need to allow quoted strings for the search. The quotes *should* be stripped
    // by the parser, but this should be examined carefully for security implications.
    $searchstring = str_replace("\\\"","\"",$searchstring);
    $parser = new search_parser();
    $lexer = new search_lexer($parser);

    if ($lexer->parse($searchstring)) {
        $parsearray = $parser->get_parsed_array();
    // Experimental feature under 1.8! MDL-8830
    // Use alternative text searches if defined
    // This feature only works under mysql until properly implemented for other DBs
    // Requires manual creation of text index for anonymous forum_posts before enabling it:
    // CREATE FULLTEXT INDEX foru_post_tix ON [prefix]anonymous forum_posts (subject, message)
    // Experimental feature under 1.8! MDL-8830
        if (!empty($CFG->anonforum_usetextsearches)) {
            list($messagesearch, $msparams) = search_generate_text_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.anonforum');
        } else {
            list($messagesearch, $msparams) = search_generate_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.anonforum');
        }
        $params = array_merge($params, $msparams);
    }

    $fromsql = "{anonforum_posts} p,
                  {anonforum_discussions} d,
                  {user} u";

    // Never return an anonymous post in a search result
    $selectsql = " $messagesearch
               AND p.discussion = d.id
               AND p.userid = u.id
               AND p.anonymouspost = 0
               AND $selectdiscussion
                   $extrasql";

    $countsql = "SELECT COUNT(*)
                   FROM $fromsql
                  WHERE $selectsql";

    $allnames = get_all_user_name_fields(true, 'u');
    $searchsql = "SELECT p.*,
                         d.anonforum,
                         $allnames,
                         u.email,
                         u.picture,
                         u.imagealt
                    FROM $fromsql
                   WHERE $selectsql
                ORDER BY p.modified DESC";

    $totalcount = $DB->count_records_sql($countsql, $params);

    return $DB->get_records_sql($searchsql, $params, $limitfrom, $limitnum);
}

/**
 * Returns a list of ratings for a particular post - sorted.
 *
 * TODO: Check if this function is actually used anywhere.
 * Up until the fix for MDL-27471 this function wasn't even returning.
 *
 * @param stdClass $context
 * @param int $postid
 * @param string $sort
 * @return array Array of ratings or false
 */
function anonforum_get_ratings($context, $postid, $sort = "u.firstname ASC") {
    $options = new stdClass;
    $options->context = $context;
    $options->component = 'mod_anonforum';
    $options->ratingarea = 'post';
    $options->itemid = $postid;
    $options->sort = "ORDER BY $sort";

    $rm = new rating_manager();
    return $rm->get_all_ratings_for_item($options);
}

/**
 * Returns a list of all new posts that have not been mailed yet
 *
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function anonforum_get_unmailed_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array();
    $params['mailed'] = ANONFORUM_MAILED_PENDING;
    $params['ptimestart'] = $starttime;
    $params['ptimeend'] = $endtime;
    $params['mailnow'] = 1;

    if (!empty($CFG->anonforum_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $timedsql = "AND (d.timestart < :dtimestart AND (d.timeend = 0 OR d.timeend > :dtimeend))";
        $params['dtimestart'] = $now;
        $params['dtimeend'] = $now;
    } else {
        $timedsql = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.anonforum
                                 FROM {anonforum_posts} p
                                 JOIN {anonforum_discussions} d ON d.id = p.discussion
                                 WHERE p.mailed = :mailed
                                 AND p.created >= :ptimestart
                                 AND (p.created < :ptimeend OR p.mailnow = :mailnow)
                                 $timedsql
                                 ORDER BY p.modified ASC", $params);
}

/**
 * Marks posts before a certain time as being mailed already
 *
 * @global object
 * @global object
 * @param int $endtime
 * @param int $now Defaults to time()
 * @return bool
 */
function anonforum_mark_old_posts_as_mailed($endtime, $now=null) {
    global $CFG, $DB;

    if (empty($now)) {
        $now = time();
    }

    $params = array();
    $params['mailedsuccess'] = ANONFORUM_MAILED_SUCCESS;
    $params['now'] = $now;
    $params['endtime'] = $endtime;
    $params['mailnow'] = 1;
    $params['mailedpending'] = ANONFORUM_MAILED_PENDING;

    if (empty($CFG->anonforum_enabletimedposts)) {
        return $DB->execute("UPDATE {anonforum_posts}
                             SET mailed = :mailedsuccess
                             WHERE (created < :endtime OR mailnow = :mailnow)
                             AND mailed = :mailedpending", $params);
    } else {
        return $DB->execute("UPDATE {anonforum_posts}
                             SET mailed = :mailedsuccess
                             WHERE discussion NOT IN (SELECT d.id
                                                      FROM {anonforum_discussions} d
                                                      WHERE d.timestart > :now)
                             AND (created < :endtime OR mailnow = :mailnow)
                             AND mailed = :mailedpending", $params);
    }
}

/**
 * Get all the posts for a user in a anonforum suitable for anonforum_print_post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function anonforum_get_user_posts($anonforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($anonforumid, $userid);

    if (!empty($CFG->anonforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('anonforum', $anonforumid);
        if (!has_capability('mod/anonforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_records_sql("SELECT p.*, d.anonforum, $allnames, u.email, u.picture, u.imagealt
                              FROM {anonforum} f
                                   JOIN {anonforum_discussions} d ON d.anonforum = f.id
                                   JOIN {anonforum_posts} p       ON p.discussion = d.id
                                   JOIN {user} u              ON u.id = p.userid
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Get all the discussions user participated in
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param int $anonforumid
 * @param int $userid
 * @return array Array or false
 */
function anonforum_get_user_involved_discussions($anonforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($anonforumid, $userid);
    if (!empty($CFG->anonforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('anonforum', $anonforumid);
        if (!has_capability('mod/anonforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT DISTINCT d.*
                              FROM {anonforum} f
                                   JOIN {anonforum_discussions} d ON d.anonforum = f.id
                                   JOIN {anonforum_posts} p       ON p.discussion = d.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql", $params);
}

/**
 * Get all the posts for a user in a anonforum suitable for anonforum_print_post
 *
 * @global object
 * @global object
 * @param int $anonforumid
 * @param int $userid
 * @return array of counts or false
 */
function anonforum_count_user_posts($anonforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($anonforumid, $userid);
    if (!empty($CFG->anonforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('anonforum', $anonforumid);
        if (!has_capability('mod/anonforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_record_sql("SELECT COUNT(p.id) AS postcount, MAX(p.modified) AS lastpost
                             FROM {anonforum} f
                                  JOIN {anonforum_discussions} d ON d.anonforum = f.id
                                  JOIN {anonforum_posts} p       ON p.discussion = d.id
                                  JOIN {user} u              ON u.id = p.userid
                            WHERE f.id = ?
                                  AND p.userid = ? AND p.anonymouspost = 0
                                  $timedsql", $params);
}

/**
 * Given a log entry, return the anonforum post details for it.
 *
 * @global object
 * @global object
 * @param object $log
 * @return array|null
 */
function anonforum_get_post_from_log($log) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    if ($log->action == "add post") {

        return $DB->get_record_sql("SELECT p.*, f.type AS anonforumtype, d.anonforum, d.groupid, $allnames, u.email, u.picture
                                 FROM {anonforum_discussions} d,
                                      {anonforum_posts} p,
                                      {anonforum} f,
                                      {user} u
                                WHERE p.id = ?
                                  AND d.id = p.discussion
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.anonforum", array($log->info));


    } else if ($log->action == "add discussion") {

        return $DB->get_record_sql("SELECT p.*, f.type AS anonforumtype, d.anonforum, d.groupid, $allnames, u.email, u.picture
                                 FROM {anonforum_discussions} d,
                                      {anonforum_posts} p,
                                      {anonforum} f,
                                      {user} u
                                WHERE d.id = ?
                                  AND d.firstpost = p.id
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.anonforum", array($log->info));
    }
    return NULL;
}

/**
 * Given a discussion id, return the first post from the discussion
 *
 * @global object
 * @global object
 * @param int $dicsussionid
 * @return array
 */
function anonforum_get_firstpost_from_discussion($discussionid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*
                             FROM {anonforum_discussions} d,
                                  {anonforum_posts} p
                            WHERE d.id = ?
                              AND d.firstpost = p.id ", array($discussionid));
}

/**
 * Returns an array of counts of replies to each discussion
 *
 * @global object
 * @global object
 * @param int $anonforumid
 * @param string $anonforumsort
 * @param int $limit
 * @param int $page
 * @param int $perpage
 * @return array
 */
function anonforum_count_discussion_replies($anonforumid, $anonforumsort="", $limit=-1, $page=-1, $perpage=0) {
    global $CFG, $DB;

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    if ($anonforumsort == "") {
        $orderby = "";
        $groupby = "";

    } else {
        $orderby = "ORDER BY $anonforumsort";
        $groupby = ", ".strtolower($anonforumsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    if (($limitfrom == 0 and $limitnum == 0) or $anonforumsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {anonforum_posts} p
                       JOIN {anonforum_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.anonforum = ?
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, array($anonforumid));

    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {anonforum_posts} p
                       JOIN {anonforum_discussions} d ON p.discussion = d.id
                 WHERE d.anonforum = ?
              GROUP BY p.discussion $groupby
              $orderby";
        return $DB->get_records_sql("SELECT * FROM ($sql) sq", array($anonforumid), $limitfrom, $limitnum);
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @staticvar array $cache
 * @param object $anonforum
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function anonforum_count_discussions($anonforum, $cm, $course) {
    global $CFG, $DB, $USER;

    static $cache = array();

    $now = round(time(), -2); // db cache friendliness

    $params = array($course->id);

    if (!isset($cache[$course->id])) {
        if (!empty($CFG->anonforum_enabletimedposts)) {
            $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
            $params[] = $now;
            $params[] = $now;
        } else {
            $timedsql = "";
        }

        $sql = "SELECT f.id, COUNT(d.id) as dcount
                  FROM {anonforum} f
                       JOIN {anonforum_discussions} d ON d.anonforum = f.id
                 WHERE f.course = ?
                       $timedsql
              GROUP BY f.id";

        if ($counts = $DB->get_records_sql($sql, $params)) {
            foreach ($counts as $count) {
                $counts[$count->id] = $count->dcount;
            }
            $cache[$course->id] = $counts;
        } else {
            $cache[$course->id] = array();
        }
    }

    if (empty($cache[$course->id][$anonforum->id])) {
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cache[$course->id][$anonforum->id];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $cache[$course->id][$anonforum->id];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // add all groups posts
    $mygroups[-1] = -1;

    list($mygroups_sql, $params) = $DB->get_in_or_equal($mygroups);
    $params[] = $anonforum->id;

    if (!empty($CFG->anonforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < $now AND (d.timeend = 0 OR d.timeend > $now)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT COUNT(d.id)
              FROM {anonforum_discussions} d
             WHERE d.groupid $mygroups_sql AND d.anonforum = ?
                   $timedsql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * How many posts by other users are unrated by a given user in the given discussion?
 *
 * TODO: Is this function still used anywhere?
 *
 * @param int $discussionid
 * @param int $userid
 * @return mixed
 */
function anonforum_count_unrated_posts($discussionid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT COUNT(*) as num
              FROM {anonforum_posts}
             WHERE parent > 0
               AND discussion = :discussionid
               AND userid <> :userid";
    $params = array('discussionid' => $discussionid, 'userid' => $userid);
    $posts = $DB->get_record_sql($sql, $params);
    if ($posts) {
        $sql = "SELECT count(*) as num
                  FROM {anonforum_posts} p,
                       {rating} r
                 WHERE p.discussion = :discussionid AND
                       p.id = r.itemid AND
                       r.userid = userid AND
                       r.component = 'mod_anonforum' AND
                       r.ratingarea = 'post'";
        $rated = $DB->get_record_sql($sql, $params);
        if ($rated) {
            if ($posts->num > $rated->num) {
                return $posts->num - $rated->num;
            } else {
                return 0;    // Just in case there was a counting error
            }
        } else {
            return $posts->num;
        }
    } else {
        return 0;
    }
}

/**
 * Get all discussions in a anonforum
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @param string $anonforumsort
 * @param bool $fullpost
 * @param int $unused
 * @param int $limit
 * @param bool $userlastmodified
 * @param int $page
 * @param int $perpage
 * @return array
 */
function anonforum_get_discussions($cm, $anonforumsort="d.timemodified DESC", $fullpost=true, $unused=-1, $limit=-1, $userlastmodified=false, $page=-1, $perpage=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $now = round(time(), -2);
    $params = array($cm->instance);

    $modcontext = context_module::instance($cm->id);

    if (!has_capability('mod/anonforum:viewdiscussion', $modcontext)) { /// User must have perms to view discussions
        return array();
    }

    if (!empty($CFG->anonforum_enabletimedposts)) { /// Users must fulfill timed posts

        if (!has_capability('mod/anonforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        if (empty($modcontext)) {
            $modcontext = context_module::instance($cm->id);
        }

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }


    if (empty($anonforumsort)) {
        $anonforumsort = "d.timemodified DESC";
    }
    if (empty($fullpost)) {
        $postdata = "p.id,p.subject,p.modified,p.discussion,p.userid,p.anonymouspost";
    } else {
        $postdata = "p.*";
    }

    if (empty($userlastmodified)) {  // We don't need to know this
        $umfields = "";
        $umtable  = "";
    } else {
        $umfields = ', ' . get_all_user_name_fields(true, 'um', null, 'um');
        $umtable  = " LEFT JOIN {user} um ON (d.usermodified = um.id)";
    }

    $allnames = get_all_user_name_fields(true, 'u');
    $sql = "SELECT $postdata, d.name, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend, $allnames,
                   u.email, u.picture, u.imagealt $umfields
              FROM {anonforum_discussions} d
                   JOIN {anonforum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
                   $umtable
             WHERE d.anonforum = ? AND p.parent = 0
                   $timelimit $groupselect
          ORDER BY $anonforumsort";
    return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
}

/**
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function anonforum_get_discussions_unread($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $cutoffdate = $now - ($CFG->anonforum_oldpostdays*24*60*60);

    $params = array();
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //separate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    if (!empty($CFG->anonforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < :now1 AND (d.timeend = 0 OR d.timeend > :now2)";
        $params['now1'] = $now;
        $params['now2'] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT d.id, COUNT(p.id) AS unread
              FROM {anonforum_discussions} d
                   JOIN {anonforum_posts} p     ON p.discussion = d.id
                   LEFT JOIN {anonforum_read} r ON (r.postid = p.id AND r.userid = $USER->id)
             WHERE d.anonforum = {$cm->instance}
                   AND p.modified >= :cutoffdate AND r.id is NULL
                   $groupselect
                   $timedsql
          GROUP BY d.id";
    $params['cutoffdate'] = $cutoffdate;

    if ($unreads = $DB->get_records_sql($sql, $params)) {
        foreach ($unreads as $unread) {
            $unreads[$unread->id] = $unread->unread;
        }
        return $unreads;
    } else {
        return array();
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @uses CONEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function anonforum_get_discussions_count($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $params = array($cm->instance);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    $cutoffdate = $now - ($CFG->anonforum_oldpostdays*24*60*60);

    $timelimit = "";

    if (!empty($CFG->anonforum_enabletimedposts)) {

        $modcontext = context_module::instance($cm->id);

        if (!has_capability('mod/anonforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    $sql = "SELECT COUNT(d.id)
              FROM {anonforum_discussions} d
                   JOIN {anonforum_posts} p ON p.discussion = d.id
             WHERE d.anonforum = ? AND p.parent = 0
                   $groupselect $timelimit";

    return $DB->get_field_sql($sql, $params);
}


/**
 * Get all discussions started by a particular user in a course (or group)
 * This function no longer used ...
 *
 * @todo Remove this function if no longer used
 * @global object
 * @global object
 * @param int $courseid
 * @param int $userid
 * @param int $groupid
 * @return array
 */
function anonforum_get_user_discussions($courseid, $userid, $groupid=0) {
    global $CFG, $DB;
    $params = array($courseid, $userid);
    if ($groupid) {
        $groupselect = " AND d.groupid = ? ";
        $params[] = $groupid;
    } else  {
        $groupselect = "";
    }

    $allnames = get_all_user_name_fields(true, 'u');
    return $DB->get_records_sql("SELECT p.*, d.groupid, $allnames, u.email, u.picture, u.imagealt,
                                   f.type as anonforumtype, f.name as anonforumname, f.id as anonforumid
                              FROM {anonforum_discussions} d,
                                   {anonforum_posts} p,
                                   {user} u,
                                   {anonforum} f
                             WHERE d.course = ?
                               AND p.discussion = d.id
                               AND p.parent = 0
                               AND p.userid = u.id
                               AND u.id = ?
                               AND d.anonforum = f.id $groupselect
                          ORDER BY p.created DESC", $params);
}

/**
 * Get the list of potential subscribers to a anonforum.
 *
 * @param object $anonforumcontext the anonforum context.
 * @param integer $groupid the id of a group, or 0 for all groups.
 * @param string $fields the list of fields to return for each user. As for get_users_by_capability.
 * @param string $sort sort order. As for get_users_by_capability.
 * @return array list of users.
 */
function anonforum_get_potential_subscribers($anonforumcontext, $groupid, $fields, $sort = '') {
    global $DB;

    // only active enrolled users or everybody on the frontpage
    list($esql, $params) = get_enrolled_sql($anonforumcontext, 'mod/anonforum:allowforcesubscribe', $groupid, true);
    if (!$sort) {
        list($sort, $sortparams) = users_order_by_sql('u');
        $params = array_merge($params, $sortparams);
    }

    $sql = "SELECT $fields
              FROM {user} u
              JOIN ($esql) je ON je.id = u.id
          ORDER BY $sort";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Returns list of user objects that are subscribed to this anonforum
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param anonforum $anonforum the anonforum
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the anonforum context, to save re-fetching it where possible.
 * @param string $fields requested user fields (with "u." table prefix)
 * @return array list of users.
 */
function anonforum_subscribed_users($course, $anonforum, $groupid=0, $context = null, $fields = null) {
    global $CFG, $DB;

    $allnames = get_all_user_name_fields(true, 'u');
    if (empty($fields)) {
        $fields ="u.id,
                  u.username,
                  $allnames,
                  u.maildisplay,
                  u.mailformat,
                  u.maildigest,
                  u.imagealt,
                  u.email,
                  u.emailstop,
                  u.city,
                  u.country,
                  u.lastaccess,
                  u.lastlogin,
                  u.picture,
                  u.timezone,
                  u.theme,
                  u.lang,
                  u.trackforums,
                  u.mnethostid";
    }

    if (empty($context)) {
        $cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id);
        $context = context_module::instance($cm->id);
    }

    if (anonforum_is_forcesubscribed($anonforum)) {
        $results = anonforum_get_potential_subscribers($context, $groupid, $fields, "u.email ASC");

    } else {
        // only active enrolled users or everybody on the frontpage
        list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
        $params['anonforumid'] = $anonforum->id;
        $results = $DB->get_records_sql("SELECT $fields
                                           FROM {user} u
                                           JOIN ($esql) je ON je.id = u.id
                                           JOIN {anonforum_subscriptions} s ON s.userid = u.id
                                          WHERE s.anonforum = :anonforumid
                                       ORDER BY u.email ASC", $params);
    }

    // Guest user should never be subscribed to a anonymous forum.
    unset($results[$CFG->siteguest]);

    return $results;
}



// OTHER FUNCTIONS ///////////////////////////////////////////////////////////


/**
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type
 */
function anonforum_get_course_anonforum($courseid, $type) {
// How to set up special 1-per-course anonymous forums
    global $CFG, $DB, $OUTPUT, $USER;

    if ($anonforums = $DB->get_records_select("anonforum", "course = ? AND type = ?", array($courseid, $type), "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($anonforums as $anonforum) {
            return $anonforum;   // ie the first one
        }
    }

    // Doesn't exist, so create one now.
    $anonforum = new stdClass();
    $anonforum->course = $courseid;
    $anonforum->type = "$type";
    if (!empty($USER->htmleditor)) {
        $anonforum->introformat = $USER->htmleditor;
    }
    switch ($anonforum->type) {
        case "news":
            $anonforum->name  = get_string("namenews", "anonforum");
            $anonforum->intro = get_string("intronews", "anonforum");
            $anonforum->forcesubscribe = ANONFORUM_FORCESUBSCRIBE;
            $anonforum->assessed = 0;
            if ($courseid == SITEID) {
                $anonforum->name  = get_string("sitenews");
                $anonforum->forcesubscribe = 0;
            }
            break;
        case "social":
            $anonforum->name  = get_string("namesocial", "anonforum");
            $anonforum->intro = get_string("introsocial", "anonforum");
            $anonforum->assessed = 0;
            $anonforum->forcesubscribe = 0;
            break;
        case "blog":
            $anonforum->name = get_string('bloganonforum', 'anonforum');
            $anonforum->intro = get_string('introblog', 'anonforum');
            $anonforum->assessed = 0;
            $anonforum->forcesubscribe = 0;
            break;
        default:
            echo $OUTPUT->notification("That anonforum type doesn't exist!");
            return false;
            break;
    }

    $anonforum->timemodified = time();
    $anonforum->id = $DB->insert_record("anonforum", $anonforum);

    if (! $module = $DB->get_record("modules", array("name" => "anonforum"))) {
        echo $OUTPUT->notification("Could not find anonforum module!!");
        return false;
    }
    $mod = new stdClass();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $anonforum->id;
    $mod->section = 0;
    include_once("$CFG->dirroot/course/lib.php");
    if (! $mod->coursemodule = add_course_module($mod) ) {
        echo $OUTPUT->notification("Could not add a new course module to the course '" . $courseid . "'");
        return false;
    }
    $sectionid = course_add_cm_to_section($courseid, $mod->coursemodule, 0);
    return $DB->get_record("anonforum", array("id" => "$anonforum->id"));
}


/**
 * Given the data about a posting, builds up the HTML to display it and
 * returns the HTML in a string.  This is designed for sending via HTML email.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $anonforum
 * @param object $discussion
 * @param object $post
 * @param object $userform
 * @param object $userto
 * @param bool $ownpost
 * @param bool $reply
 * @param bool $link
 * @param bool $rate
 * @param string $footer
 * @return string
 */
function anonforum_make_mail_post($course, $cm, $anonforum, $discussion, $post, $userfrom, $userto,
                              $ownpost=false, $reply=false, $link=false, $rate=false, $footer="") {

    global $CFG, $OUTPUT;

    $modcontext = context_module::instance($cm->id);

    if (!isset($userto->viewfullnames[$anonforum->id])) {
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$anonforum->id];
    }

    $by = new stdClass();
    $by->date = userdate($post->modified, '', $userto->timezone);
    if (empty($post->anonymouspost)) {
        $by->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$userfrom->id.'&amp;course='.$course->id.'">'.fullname($userfrom, $viewfullnames).'</a>';
        $by->userpicture = $OUTPUT->user_picture($userfrom, array('courseid'=>$course->id));
    } else {
        $by->name = get_string('anonymoususer', 'anonforum');
        $by->userpicture = anon_user_picture($userfrom);
    }

    // add absolute file links
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_anonforum', 'post', $post->id);

    // format the post body
    $options = new stdClass();
    $options->para = true;
    $formattedtext = format_text($post->message, $post->messageformat, $options, $course->id);

    $output = '<table border="0" cellpadding="3" cellspacing="0" class="anonforumpost">';

    $output .= '<tr class="header"><td width="35" valign="top" class="picture left">';
    $output .= $by->userpicture;
    $output .= '</td>';

    if ($post->parent) {
        $output .= '<td class="topic">';
    } else {
        $output .= '<td class="topic starter">';
    }
    $output .= '<div class="subject">'.format_string($post->subject).'</div>';

    $output .= '<div class="author">'.get_string('bynameondate', 'anonforum', $by).'</div>';

    $output .= '</td></tr>';

    $output .= '<tr><td class="left side" valign="top">';

    if (isset($userfrom->groups)) {
        $groups = $userfrom->groups[$anonforum->id];
    } else {
        $groups = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
    }

    if ($groups) {
        $output .= print_group_picture($groups, $course->id, false, true, true);
    } else {
        $output .= '&nbsp;';
    }

    $output .= '</td><td class="content">';

    $attachments = anonforum_print_attachments($post, $cm, 'html');
    if ($attachments !== '') {
        $output .= '<div class="attachments">';
        $output .= $attachments;
        $output .= '</div>';
    }

    $output .= $formattedtext;

// Commands
    $commands = array();

    if ($post->parent) {
        $commands[] = '<a target="_blank" href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.
                      $post->discussion.'&amp;parent='.$post->parent.'">'.get_string('parent', 'anonforum').'</a>';
    }

    if ($reply) {
        $commands[] = '<a target="_blank" href="'.$CFG->wwwroot.'/mod/anonforum/post.php?reply='.$post->id.'">'.
                      get_string('reply', 'anonforum').'</a>';
    }

    $output .= '<div class="commands">';
    $output .= implode(' | ', $commands);
    $output .= '</div>';

// Context link to post if required
    if ($link) {
        $output .= '<div class="link">';
        $output .= '<a target="_blank" href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'#p'.$post->id.'">'.
                     get_string('postincontext', 'anonforum').'</a>';
        $output .= '</div>';
    }

    if ($footer) {
        $output .= '<div class="footer">'.$footer.'</div>';
    }
    $output .= '</td></tr></table>'."\n\n";

    return $output;
}

/**
 * Print a anonforum post
 *
 * @global object
 * @global object
 * @uses ANONFORUM_MODE_THREADED
 * @uses PORTFOLIO_FORMAT_PLAINHTML
 * @uses PORTFOLIO_FORMAT_FILE
 * @uses PORTFOLIO_FORMAT_RICHHTML
 * @uses PORTFOLIO_ADD_TEXT_LINK
 * @uses CONTEXT_MODULE
 * @param object $post The post to print.
 * @param object $discussion
 * @param object $anonforum
 * @param object $cm
 * @param object $course
 * @param boolean $ownpost Whether this post belongs to the current user.
 * @param boolean $reply Whether to print a 'reply' link at the bottom of the message.
 * @param boolean $link Just print a shortened version of the post as a link to the full post.
 * @param string $footer Extra stuff to print after the message.
 * @param string $highlight Space-separated list of terms to highlight.
 * @param int $post_read true, false or -99. If we already know whether this user
 *          has read this post, pass that in, otherwise, pass in -99, and this
 *          function will work it out.
 * @param boolean $dummyifcantsee When anonforum_user_can_see_post says that
 *          the current user can't see this post, if this argument is true
 *          (the default) then print a dummy 'you can't see this post' post.
 *          If false, don't output anything at all.
 * @param bool|null $istracked
 * @return void
 */
function anonforum_print_post($post, $discussion, $anonforum, &$cm, $course, $ownpost=false, $reply=false, $link=false,
                          $footer="", $highlight="", $postisread=null, $dummyifcantsee=true, $istracked=null, $return=false) {
    global $USER, $CFG, $OUTPUT;

    require_once($CFG->libdir . '/filelib.php');

    // String cache
    static $str;

    $modcontext = context_module::instance($cm->id);

    $post->course = $course->id;
    $post->anonforum  = $anonforum->id;
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_anonforum', 'post', $post->id);
    if (!empty($CFG->enableplagiarism)) {
        require_once($CFG->libdir.'/plagiarismlib.php');
        $post->message .= plagiarism_get_links(array('userid' => $post->userid,
            'content' => $post->message,
            'cmid' => $cm->id,
            'course' => $post->course,
            'anonforum' => $post->anonforum));
    }

    // caching
    if (!isset($cm->cache)) {
        $cm->cache = new stdClass;
    }

    if (!isset($cm->cache->caps)) {
        $cm->cache->caps = array();
        $cm->cache->caps['mod/anonforum:viewdiscussion']   = has_capability('mod/anonforum:viewdiscussion', $modcontext);
        $cm->cache->caps['moodle/site:viewfullnames']  = has_capability('moodle/site:viewfullnames', $modcontext);
        $cm->cache->caps['mod/anonforum:editanypost']      = has_capability('mod/anonforum:editanypost', $modcontext);
        $cm->cache->caps['mod/anonforum:splitdiscussions'] = has_capability('mod/anonforum:splitdiscussions', $modcontext);
        $cm->cache->caps['mod/anonforum:deleteownpost']    = has_capability('mod/anonforum:deleteownpost', $modcontext);
        $cm->cache->caps['mod/anonforum:deleteanypost']    = has_capability('mod/anonforum:deleteanypost', $modcontext);
        $cm->cache->caps['mod/anonforum:viewanyrating']    = has_capability('mod/anonforum:viewanyrating', $modcontext);
        $cm->cache->caps['mod/anonforum:exportpost']       = has_capability('mod/anonforum:exportpost', $modcontext);
        $cm->cache->caps['mod/anonforum:exportownpost']    = has_capability('mod/anonforum:exportownpost', $modcontext);
    }

    if (!isset($cm->uservisible)) {
        $cm->uservisible = coursemodule_visible_for_user($cm);
    }

    if ($istracked && is_null($postisread)) {
        $postisread = anonforum_tp_is_post_read($USER->id, $post);
    }

    if (!anonforum_user_can_see_post($anonforum, $discussion, $post, NULL, $cm)) {
        $output = '';
        if (!$dummyifcantsee) {
            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }
        $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
        $output .= html_writer::start_tag('div', array('class'=>'anonforumpost clearfix',
                                                       'role' => 'region',
                                                       'aria-label' => get_string('hiddenforumpost', 'anonforum')));
        $output .= html_writer::start_tag('div', array('class'=>'row header'));
        $output .= html_writer::tag('div', '', array('class'=>'left picture')); // Picture
        if ($post->parent) {
            $output .= html_writer::start_tag('div', array('class'=>'topic'));
        } else {
            $output .= html_writer::start_tag('div', array('class'=>'topic starter'));
        }
        $output .= html_writer::tag('div', get_string('anonforumsubjecthidden','anonforum'), array('class' => 'subject',
                                                                                           'role' => 'header')); // Subject.
        $output .= html_writer::tag('div', get_string('anonforumauthorhidden', 'anonforum'), array('class' => 'author',
                                                                                           'role' => 'header')); // Author.
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::start_tag('div', array('class'=>'row'));
        $output .= html_writer::tag('div', '&nbsp;', array('class'=>'left side')); // Groups
        $output .= html_writer::tag('div', get_string('anonforumbodyhidden','anonforum'), array('class'=>'content')); // Content
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::end_tag('div'); // anonymous forumpost

        if ($return) {
            return $output;
        }
        echo $output;
        return;
    }

    if (empty($str)) {
        $str = new stdClass;
        $str->edit         = get_string('edit', 'anonforum');
        $str->delete       = get_string('delete', 'anonforum');
        $str->reply        = get_string('reply', 'anonforum');
        $str->parent       = get_string('parent', 'anonforum');
        $str->pruneheading = get_string('pruneheading', 'anonforum');
        $str->prune        = get_string('prune', 'anonforum');
        $str->displaymode  = get_user_preferences('anonforum_displaymode', $CFG->anonforum_displaymode);
        $str->markread     = get_string('markread', 'anonforum');
        $str->markunread   = get_string('markunread', 'anonforum');
    }

    $discussionlink = new moodle_url('/mod/anonforum/discuss.php', array('d'=>$post->discussion));

    // Build an object that represents the posting user
    $postuser = new stdClass;
    $postuser->id        = $post->userid;

    if (empty($post->anonymouspost)) {
        $postuserfields = explode(',', user_picture::fields());
        $postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);
        $postuser->id = $post->userid;
        $postuser->fullname    = fullname($postuser, $cm->cache->caps['moodle/site:viewfullnames']);
        $postuser->profilelink = new moodle_url('/user/view.php', array('id'=>$post->userid, 'course'=>$course->id));
        $postuser->userpicture = $OUTPUT->user_picture($postuser, array('courseid'=>$course->id));
    } else {
        $postuser->firstname = get_string('anonymoususer', 'anonforum');
        $postuser->lastname  = '';
        $postuser->imagealt  = '';
        $postuser->picture   = '';
        $postuser->email     = '';
        $postuser->firstnamephonetic = '';
        $postuser->lastnamephonetic = '';
        $postuser->middlename = '';
        $postuser->alternatename = '';
        // Some handy things for later on
        $postuser->fullname    = get_string('anonymoususer', 'anonforum');
        $postuser->profilelink = '#';
        $postuser->anonymous = true;
        $postuser->userpicture = anon_user_picture($postuser);
    }

    // Prepare the groups the posting user belongs to
    if (isset($cm->cache->usersgroups)) {
        $groups = array();
        if (isset($cm->cache->usersgroups[$post->userid])) {
            foreach ($cm->cache->usersgroups[$post->userid] as $gid) {
                $groups[$gid] = $cm->cache->groups[$gid];
            }
        }
    } else {
        $groups = groups_get_all_groups($course->id, $post->userid, $cm->groupingid);
    }

    // Prepare the attachements for the post, files then images
    list($attachments, $attachedimages) = anonforum_print_attachments($post, $cm, 'separateimages');

    // Determine if we need to shorten this post
    $shortenpost = ($link && (strlen(strip_tags($post->message)) > $CFG->anonforum_longpost));


    // Prepare an array of commands
    $commands = array();

    // SPECIAL CASE: The front page can display a news item post to non-logged in users.
    // Don't display the mark read / unread controls in this case.
    if ($istracked && $CFG->anonforum_usermarksread && isloggedin()) {
        $url = new moodle_url($discussionlink, array('postid'=>$post->id, 'mark'=>'unread'));
        $text = $str->markunread;
        if (!$postisread) {
            $url->param('mark', 'read');
            $text = $str->markread;
        }
        if ($str->displaymode == ANONFORUM_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->id);
        }
        $commands[] = array('url'=>$url, 'text'=>$text);
    }

    // Zoom in to the parent specifically
    if ($post->parent) {
        $url = new moodle_url($discussionlink);
        if ($str->displaymode == ANONFORUM_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->parent);
        }
        $commands[] = array('url'=>$url, 'text'=>$str->parent);
    }

    // Hack for allow to edit news posts those are not displayed yet until they are displayed
    $age = time() - $post->created;
    if (!$post->parent && $anonforum->type == 'news' && $discussion->timestart > time()) {
        $age = 0;
    }

    if ($anonforum->type == 'single' and $discussion->firstpost == $post->id) {
        if (has_capability('moodle/course:manageactivities', $modcontext)) {
            // The first post in single simple is the anonymous forum description.
            $commands[] = array('url'=>new moodle_url('/course/modedit.php', array('update'=>$cm->id, 'sesskey'=>sesskey(), 'return'=>1)), 'text'=>$str->edit);
        }
    } else if (($ownpost && $age < $CFG->maxeditingtime) || $cm->cache->caps['mod/anonforum:editanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/anonforum/post.php', array('edit'=>$post->id)), 'text'=>$str->edit);
    }

    if ($cm->cache->caps['mod/anonforum:splitdiscussions'] && $post->parent && $anonforum->type != 'single') {
        $commands[] = array('url'=>new moodle_url('/mod/anonforum/post.php', array('prune'=>$post->id)), 'text'=>$str->prune, 'title'=>$str->pruneheading);
    }

    if ($anonforum->type == 'single' and $discussion->firstpost == $post->id) {
        // Do not allow deleting of first post in single simple type.
    } else if (($ownpost && $age < $CFG->maxeditingtime && $cm->cache->caps['mod/anonforum:deleteownpost']) || $cm->cache->caps['mod/anonforum:deleteanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/anonforum/post.php', array('delete'=>$post->id)), 'text'=>$str->delete);
    }

    if ($reply) {
        $commands[] = array('url'=>new moodle_url('/mod/anonforum/post.php#mformanonforum', array('reply'=>$post->id)), 'text'=>$str->reply);
    }

    if ($CFG->enableportfolios && ($cm->cache->caps['mod/anonforum:exportpost'] || ($ownpost && $cm->cache->caps['mod/anonforum:exportownpost']))) {
        $p = array('postid' => $post->id);
        require_once($CFG->libdir.'/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('anonforum_portfolio_caller', array('postid' => $post->id), 'mod_anonforum');
        if (empty($attachments)) {
            $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
        } else {
            $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
        }

        $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
        if (!empty($porfoliohtml)) {
            $commands[] = $porfoliohtml;
        }
    }
    // Finished building commands


    // Begin output

    $output  = '';

    if ($istracked) {
        if ($postisread) {
            $anonforumpostclass = ' read';
        } else {
            $anonforumpostclass = ' unread';
            $output .= html_writer::tag('a', '', array('name'=>'unread'));
        }
    } else {
        // ignore trackign status if not tracked or tracked param missing
        $anonforumpostclass = '';
    }

    $topicclass = '';
    if (empty($post->parent)) {
        $topicclass = ' firstpost starter';
    }

    $postbyuser = new stdClass;
    $postbyuser->post = $post->subject;
    $postbyuser->user = $postuser->fullname;
    $discussionbyuser = get_string('postbyuser', 'anonforum', $postbyuser);
    $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
    $output .= html_writer::start_tag('div', array('class'=>'anonforumpost clearfix'.$anonforumpostclass.$topicclass,
                                                   'role' => 'region',
                                                   'aria-label' => $discussionbyuser));
    $output .= html_writer::start_tag('div', array('class'=>'row header clearfix'));
    $output .= html_writer::start_tag('div', array('class'=>'left picture'));
    $output .= $postuser->userpicture;
    $output .= html_writer::end_tag('div');


    $output .= html_writer::start_tag('div', array('class'=>'topic'.$topicclass));

    $postsubject = $post->subject;
    if (empty($post->subjectnoformat)) {
        $postsubject = format_string($postsubject);
    }
    $output .= html_writer::tag('div', $postsubject, array('class'=>'subject',
                                                           'role' => 'heading',
                                                           'aria-level' => '2'));

    $by = new stdClass();
    if (empty($post->anonymouspost)) {
        $by->name = html_writer::link($postuser->profilelink, $postuser->fullname);
    } else {
        $by->name = get_string('anonymoususer', 'anonforum');
    }
    $by->date = userdate($post->modified);
    $output .= html_writer::tag('div', get_string('bynameondate', 'anonforum', $by), array('class'=>'author',
                                                                                       'role' => 'heading',
                                                                                       'aria-level' => '2'));

    $output .= html_writer::end_tag('div'); //topic
    $output .= html_writer::end_tag('div'); //row

    $output .= html_writer::start_tag('div', array('class'=>'row maincontent clearfix'));
    $output .= html_writer::start_tag('div', array('class'=>'left'));

    $groupoutput = '';
    if ($groups) {
        $groupoutput = print_group_picture($groups, $course->id, false, true, true);
    }
    if (empty($groupoutput)) {
        $groupoutput = '&nbsp;';
    }
    $output .= html_writer::tag('div', $groupoutput, array('class'=>'grouppictures'));

    $output .= html_writer::end_tag('div'); //left side
    $output .= html_writer::start_tag('div', array('class'=>'no-overflow'));
    $output .= html_writer::start_tag('div', array('class'=>'content'));
    if (!empty($attachments)) {
        $output .= html_writer::tag('div', $attachments, array('class'=>'attachments'));
    }

    $options = new stdClass;
    $options->para    = false;
    $options->trusted = $post->messagetrust;
    $options->context = $modcontext;
    if ($shortenpost) {
        // Prepare shortened version by filtering the text then shortening it.
        $postclass    = 'shortenedpost';
        $postcontent  = format_text($post->message, $post->messageformat, $options);
        $postcontent  = shorten_text($postcontent, $CFG->anonforum_shortpost);
        $postcontent .= html_writer::link($discussionlink, get_string('readtherest', 'anonforum'));
        $postcontent .= html_writer::tag('div', '('.get_string('numwords', 'moodle', count_words($post->message)).')',
            array('class'=>'post-word-count'));
    } else {
        // Prepare whole post
        $postclass    = 'fullpost';
        $postcontent  = format_text($post->message, $post->messageformat, $options, $course->id);
        if (!empty($highlight)) {
            $postcontent = highlight($highlight, $postcontent);
        }
        if (!empty($anonforum->displaywordcount)) {
            $postcontent .= html_writer::tag('div', get_string('numwords', 'moodle', count_words($post->message)),
                array('class'=>'post-word-count'));
        }
        $postcontent .= html_writer::tag('div', $attachedimages, array('class'=>'attachedimages'));
    }

    // Output the post content
    $output .= html_writer::tag('div', $postcontent, array('class'=>'posting '.$postclass));
    $output .= html_writer::end_tag('div'); // Content
    $output .= html_writer::end_tag('div'); // Content mask
    $output .= html_writer::end_tag('div'); // Row

    $output .= html_writer::start_tag('div', array('class'=>'row side'));
    $output .= html_writer::tag('div','&nbsp;', array('class'=>'left'));
    $output .= html_writer::start_tag('div', array('class'=>'options clearfix'));

    // Output ratings
    if (!empty($post->rating) && empty($anonforum->anonymous)) {
        $output .= html_writer::tag('div', $OUTPUT->render($post->rating), array('class'=>'anonforum-post-rating'));
    }

    // Output the commands
    $commandhtml = array();
    foreach ($commands as $command) {
        if (is_array($command)) {
            $commandhtml[] = html_writer::link($command['url'], $command['text']);
        } else {
            $commandhtml[] = $command;
        }
    }
    $output .= html_writer::tag('div', implode(' | ', $commandhtml), array('class'=>'commands'));

    // Output link to post if required
    if ($link && anonforum_user_can_post($anonforum, $discussion, $USER, $cm, $course, $modcontext)) {
        if ($post->replies == 1) {
            $replystring = get_string('repliesone', 'anonforum', $post->replies);
        } else {
            $replystring = get_string('repliesmany', 'anonforum', $post->replies);
        }

        $output .= html_writer::start_tag('div', array('class'=>'link'));
        $output .= html_writer::link($discussionlink, get_string('discussthistopic', 'anonforum'));
        $output .= '&nbsp;('.$replystring.')';
        $output .= html_writer::end_tag('div'); // link
    }

    // Output footer if required
    if ($footer) {
        $output .= html_writer::tag('div', $footer, array('class'=>'footer'));
    }

    // Close remaining open divs
    $output .= html_writer::end_tag('div'); // content
    $output .= html_writer::end_tag('div'); // row
    $output .= html_writer::end_tag('div'); // anonymous forumpost

    // Mark the anonymous forum post as read if required
    if ($istracked && !$CFG->anonforum_usermarksread && !$postisread) {
        anonforum_tp_mark_post_read($USER->id, $post, $anonforum->id);
    }

    if ($return) {
        return $output;
    }
    echo $output;
    return;
}

/**
 * Return rating related permissions
 *
 * @param string $options the context id
 * @return array an associative array of the user's rating permissions
 */
function anonforum_rating_permissions($contextid, $component, $ratingarea) {
    $context = context::instance_by_id($contextid, MUST_EXIST);
    if ($component != 'mod_anonforum' || $ratingarea != 'post') {
        // We don't know about this component/ratingarea so just return null to get the
        // default restrictive permissions.
        return null;
    }
    return array(
        'view'    => has_capability('mod/anonforum:viewrating', $context),
        'viewany' => has_capability('mod/anonforum:viewanyrating', $context),
        'viewall' => has_capability('mod/anonforum:viewallratings', $context),
        'rate'    => has_capability('mod/anonforum:rate', $context)
    );
}

/**
 * Validates a submitted rating
 * @param array $params submitted data
 *            context => object the context in which the rated items exists [required]
 *            component => The component for this module - should always be mod_anonforum [required]
 *            ratingarea => object the context in which the rated items exists [required]
 *            itemid => int the ID of the object being rated [required]
 *            scaleid => int the scale from which the user can select a rating. Used for bounds checking. [required]
 *            rating => int the submitted rating [required]
 *            rateduserid => int the id of the user whose items have been rated. NOT the user who submitted the ratings. 0 to update all. [required]
 *            aggregation => int the aggregation method to apply when calculating grades ie RATING_AGGREGATE_AVERAGE [required]
 * @return boolean true if the rating is valid. Will throw rating_exception if not
 */
function anonforum_rating_validate($params) {
    global $DB, $USER;

    // Check the component is mod_anonymous forum
    if ($params['component'] != 'mod_anonforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in anonymous forum)
    if ($params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records ... we need to do this anyway to call anonymous forum_user_can_see_post
    $post = $DB->get_record('anonforum_posts', array('id' => $params['itemid'], 'userid' => $params['rateduserid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('anonforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $anonforum = $DB->get_record('anonforum', array('id' => $discussion->anonforum), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $anonforum->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id , false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Make sure the context provided is the context of the anonymous forum
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($anonforum->scale != $params['scaleid']) {
        //the scale being submitted doesnt match the one in the database
        throw new rating_exception('invalidscaleid');
    }

    // check the item we're rating was created in the assessable time window
    if (!empty($anonforum->assesstimestart) && !empty($anonforum->assesstimefinish)) {
        if ($post->created < $anonforum->assesstimestart || $post->created > $anonforum->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    //check that the submitted rating is valid for the scale

    // lower limit
    if ($params['rating'] < 0  && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // upper limit
    if ($anonforum->scale < 0) {
        //its a custom scale
        $scalerecord = $DB->get_record('scale', array('id' => -$anonforum->scale));
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $anonforum->scale) {
        //if its numeric and submitted rating is above maximum
        throw new rating_exception('invalidnum');
    }

    // Make sure groups allow this user to see the item they're rating
    if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
        if (!groups_group_exists($discussion->groupid)) { // Can't find group
            throw new rating_exception('cannotfindgroup');//something is wrong
        }

        if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
            // do not allow rating of posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
            throw new rating_exception('notmemberofgroup');
        }
    }

    // perform some final capability checks
    if (!anonforum_user_can_see_post($anonforum, $discussion, $post, $USER, $cm)) {
        throw new rating_exception('nopermissiontorate');
    }

    return true;
}


/**
 * This function prints the overview of a discussion in the anonforum listing.
 * It needs some discussion information and some post information, these
 * happen to be combined for efficiency in the $post parameter by the function
 * that calls this one: anonforum_print_latest_discussions()
 *
 * @global object
 * @global object
 * @param object $post The post object (passed by reference for speed).
 * @param object $anonforum The anonforum object.
 * @param int $group Current group.
 * @param string $datestring Format to use for the dates.
 * @param boolean $cantrack Is tracking enabled for this anonforum.
 * @param boolean $anonforumtracked Is the user tracking this anonforum.
 * @param boolean $canviewparticipants True if user has the viewparticipants permission for this course
 */
function anonforum_print_discussion_header(&$post, $anonforum, $group=-1, $datestring="",
                                        $cantrack=true, $anonforumtracked=true, $canviewparticipants=true, $modcontext=NULL) {

    global $USER, $CFG, $OUTPUT, $DB;

    static $rowcount;
    static $strmarkalldread;

    if (empty($modcontext)) {
        if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course)) {
            print_error('invalidcoursemodule');
        }
        $modcontext = context_module::instance($cm->id);
    }

    if (!isset($rowcount)) {
        $rowcount = 0;
        $strmarkalldread = get_string('markalldread', 'anonforum');
    } else {
        $rowcount = ($rowcount + 1) % 2;
    }

    $post->subject = format_string($post->subject,true);

    echo "\n\n";
    echo '<tr class="discussion r'.$rowcount.'">';

    // Topic
    echo '<td class="topic starter">';
    echo '<a href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'">'.$post->subject.'</a>';
    echo "</td>\n";

    // Picture
    $postuser = new stdClass();

    if (empty($post->anonymouspost)) {
        $postuserfields = explode(',', user_picture::fields());
        $postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);
        $postuser->id = $post->userid;
        echo '<td class="picture">';
        echo $OUTPUT->user_picture($postuser, array('courseid'=>$anonforum->course));
        echo "</td>\n";
        // User name
        $fullname = fullname($postuser, has_capability('moodle/site:viewfullnames', $modcontext));
        echo '<td class="author">';
        echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->userid.'&amp;course='.$anonforum->course.'">'.$fullname.'</a>';
        echo "</td>\n";
    } else {
        $postuser->id = $post->userid;
        $postuser->firstname = get_string('anonymoususer', 'anonforum');
        $postuser->lastname = '';
        $postuser->imagealt = '';
        $postuser->picture = '';
        $postuser->email = '';
        $postuser->firstnamephonetic = '';
        $postuser->lastnamephonetic = '';
        $postuser->middlename = '';
        $postuser->alternatename = '';
        echo '<td class="picture">';
        echo anon_user_picture($postuser);
        echo "</td>\n";
        echo '<td class="author">';
        echo get_string('anonymoususer', 'anonforum');
        echo "</td>\n";
    }

    // Group picture
    if ($group !== -1) {  // Groups are active - group is a group data object or NULL
        echo '<td class="picture group">';
        if (!empty($group->picture) and empty($group->hidepicture)) {
            print_group_picture($group, $anonforum->course, false, false, true);
        } else if (isset($group->id)) {
            if($canviewparticipants) {
                echo '<a href="'.$CFG->wwwroot.'/user/index.php?id='.$anonforum->course.'&amp;group='.$group->id.'">'.$group->name.'</a>';
            } else {
                echo $group->name;
            }
        }
        echo "</td>\n";
    }

    if (has_capability('mod/anonforum:viewdiscussion', $modcontext)) {   // Show the column with replies
        echo '<td class="replies">';
        echo '<a href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'">';
        echo $post->replies.'</a>';
        echo "</td>\n";

        if ($cantrack) {
            echo '<td class="replies">';
            if ($anonforumtracked) {
                if ($post->unread > 0) {
                    echo '<span class="unread">';
                    echo '<a href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.'#unread">';
                    echo $post->unread;
                    echo '</a>';
                    echo '<a title="'.$strmarkalldread.'" href="'.$CFG->wwwroot.'/mod/anonforum/markposts.php?f='.
                         $anonforum->id.'&amp;d='.$post->discussion.'&amp;mark=read&amp;returnpage=view.php">' .
                         '<img src="'.$OUTPUT->pix_url('t/markasread') . '" class="iconsmall" alt="'.$strmarkalldread.'" /></a>';
                    echo '</span>';
                } else {
                    echo '<span class="read">';
                    echo $post->unread;
                    echo '</span>';
                }
            } else {
                echo '<span class="read">';
                echo '-';
                echo '</span>';
            }
            echo "</td>\n";
        }
    }

    echo '<td class="lastpost">';
    $usedate = (empty($post->timemodified)) ? $post->modified : $post->timemodified;  // Just in case
    $parenturl = (empty($post->lastpostid)) ? '' : '&amp;parent='.$post->lastpostid;

    $usermodified = new stdClass();

    $usermodified->id        = $post->usermodified;
    // In the event of some db error it is better to not show information until we can confirm that we should.
    $usermodified->firstname = get_string('anonymoususer', 'anonforum');
    $usermodified->lastname  = '';
    $usermodified->link = get_string('anonymoususer', 'anonforum').'<br />';

    // Check if we have a lastpost (or if this is the last post) and if it is not anonymous


    if (!empty($post->lastpostid)) {
        $lastpost = $DB->get_record('anonforum_posts', array( 'id' => $post->lastpostid), 'id, anonymouspost', 'anonymouspost');
        if (empty($lastpost->anonymouspost)) {
            $usermodified->id = $post->usermodified;
            $usermodified = username_load_fields_from_object($usermodified, $post, 'um');
            $usermodified->link = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->usermodified.'&amp;course='.$anonforum->course.'">'.
                fullname($usermodified).'</a><br />';
            foreach (get_all_user_name_fields() as $addname) {
                $temp = 'um' . $addname;
                $usermodified->$addname = $post->$temp;
            }
        }
    } else if ((empty($post->lastpostid) && empty($post->anonymouspost))) {
        $usermodified->id = $post->usermodified;
        $usermodified = username_load_fields_from_object($usermodified, $post, 'um');
        $usermodified->link = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->usermodified.'&amp;course='.$anonforum->course.'">'.
            fullname($usermodified).'</a><br />';

        foreach (get_all_user_name_fields() as $addname) {
            $temp = 'um' . $addname;
            $usermodified->$addname = $post->$temp;
        }
    }

    echo $usermodified->link;

    echo '<a href="'.$CFG->wwwroot.'/mod/anonforum/discuss.php?d='.$post->discussion.$parenturl.'">'.
          userdate($usedate, $datestring).'</a>';
    echo "</td>\n";

    echo "</tr>\n\n";

}

/**
 * This function is now deprecated. Use shorten_text($message, $CFG->anonforum_shortpost) instead.
 *
 * Given a post object that we already know has a long message
 * this function truncates the message nicely to the first
 * sane place between $CFG->anonforum_longpost and $CFG->anonforum_shortpost
 *
 * @deprecated since Moodle 2.6
 * @see shorten_text()
 * @todo finalise deprecation in 2.8 in MDL-40851
 * @global object
 * @param string $message
 * @return string
 */
function anonforum_shorten_post($message) {
   global $CFG;
   debugging('anonforum_shorten_post() is deprecated since Moodle 2.6. Please use shorten_text($message, $CFG->anonforum_shortpost) instead.', DEBUG_DEVELOPER);
   return shorten_text($message, $CFG->anonforum_shortpost);
}

/**
 * Print the drop down that allows the user to select how they want to have
 * the discussion displayed.
 *
 * @param int $id anonforum id if $anonforumtype is 'single',
 *              discussion id for any other anonforum type
 * @param mixed $mode anonforum layout mode
 * @param string $anonforumtype optional
 */
function anonforum_print_mode_form($id, $mode, $anonforumtype='') {
    global $OUTPUT;
    if ($anonforumtype == 'single') {
        $select = new single_select(new moodle_url("/mod/anonforum/view.php", array('f'=>$id)), 'mode', anonforum_get_layout_modes(), $mode, null, "mode");
        $select->set_label(get_string('displaymode', 'anonforum'), array('class' => 'accesshide'));
        $select->class = "anonforummode";
    } else {
        $select = new single_select(new moodle_url("/mod/anonforum/discuss.php", array('d'=>$id)), 'mode', anonforum_get_layout_modes(), $mode, null, "mode");
        $select->set_label(get_string('displaymode', 'anonforum'), array('class' => 'accesshide'));
    }
    echo $OUTPUT->render($select);
}

/**
 * @global object
 * @param object $course
 * @param string $search
 * @return string
 */
function anonforum_search_form($course, $search='') {
    global $CFG, $OUTPUT;

    $output  = '<div class="anonforumsearch">';
    $output .= '<form action="'.$CFG->wwwroot.'/mod/anonforum/search.php" style="display:inline">';
    $output .= '<fieldset class="invisiblefieldset">';
    $output .= $OUTPUT->help_icon('search');
    $output .= '<label class="accesshide" for="search" >'.get_string('search', 'anonforum').'</label>';
    $output .= '<input id="search" name="search" type="text" size="18" value="'.s($search, true).'" alt="search" />';
    $output .= '<label class="accesshide" for="searchanonforums" >'.get_string('searchanonforums', 'anonforum').'</label>';
    $output .= '<input id="searchanonforums" value="'.get_string('searchanonforums', 'anonforum').'" type="submit" />';
    $output .= '<input name="id" type="hidden" value="'.$course->id.'" />';
    $output .= '</fieldset>';
    $output .= '</form>';
    $output .= '</div>';

    return $output;
}


/**
 * @global object
 * @global object
 */
function anonforum_set_return() {
    global $CFG, $SESSION;

    if (! isset($SESSION->fromdiscussion)) {
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referer = $_SERVER['HTTP_REFERER'];
        } else {
            $referer = "";
        }
        // If the referer is NOT a login screen then save it.
        if (! strncasecmp("$CFG->wwwroot/login", $referer, 300)) {
            $SESSION->fromdiscussion = $_SERVER["HTTP_REFERER"];
        }
    }
}


/**
 * @global object
 * @param string $default
 * @return string
 */
function anonforum_go_back_to($default) {
    global $SESSION;

    if (!empty($SESSION->fromdiscussion)) {
        $returnto = $SESSION->fromdiscussion;
        unset($SESSION->fromdiscussion);
        return $returnto;
    } else {
        return $default;
    }
}

/**
 * Given a discussion object that is being moved to $anonforumto,
 * this function checks all posts in that discussion
 * for attachments, and if any are found, these are
 * moved to the new anonforum directory.
 *
 * @global object
 * @param object $discussion
 * @param int $anonforumfrom source anonforum id
 * @param int $anonforumto target anonforum id
 * @return bool success
 */
function anonforum_move_attachments($discussion, $anonforumfrom, $anonforumto) {
    global $DB;

    $fs = get_file_storage();

    $newcm = get_coursemodule_from_instance('anonforum', $anonforumto);
    $oldcm = get_coursemodule_from_instance('anonforum', $anonforumfrom);

    $newcontext = context_module::instance($newcm->id);
    $oldcontext = context_module::instance($oldcm->id);

    // loop through all posts, better not use attachment flag ;-)
    if ($posts = $DB->get_records('anonforum_posts', array('discussion'=>$discussion->id), '', 'id, attachment')) {
        foreach ($posts as $post) {
            $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_anonforum', 'post', $post->id);
            $attachmentsmoved = $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_anonforum', 'attachment', $post->id);
            if ($attachmentsmoved > 0 && $post->attachment != '1') {
                // Weird - let's fix it
                $post->attachment = '1';
                $DB->update_record('anonforum_posts', $post);
            } else if ($attachmentsmoved == 0 && $post->attachment != '') {
                // Weird - let's fix it
                $post->attachment = '';
                $DB->update_record('anonforum_posts', $post);
            }
        }
    }

    return true;
}

/**
 * Returns attachments as formated text/html optionally with separate images
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param object $cm
 * @param string $type html/text/separateimages
 * @return mixed string or array of (html text withouth images and image HTML)
 */
function anonforum_print_attachments($post, $cm, $type) {
    global $CFG, $DB, $USER, $OUTPUT;

    if (empty($post->attachment)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!in_array($type, array('separateimages', 'html', 'text'))) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!$context = context_module::instance($cm->id)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }
    $strattachment = get_string('attachment', 'anonforum');

    $fs = get_file_storage();

    $imagereturn = '';
    $output = '';

    $canexport = !empty($CFG->enableportfolios) && (has_capability('mod/anonforum:exportpost', $context) || ($post->userid == $USER->id && has_capability('mod/anonforum:exportownpost', $context)));

    if ($canexport) {
        require_once($CFG->libdir.'/portfoliolib.php');
    }

    $files = $fs->get_area_files($context->id, 'mod_anonforum', 'attachment', $post->id, "timemodified", false);
    if ($files) {
        if ($canexport) {
            $button = new portfolio_add_button();
        }
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            $iconimage = $OUTPUT->pix_icon(file_file_icon($file), get_mimetype_description($file), 'moodle', array('class' => 'icon'));
            $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id.'/mod_anonforum/attachment/'.$post->id.'/'.$filename);

            if ($type == 'html') {
                $output .= "<a href=\"$path\">$iconimage</a> ";
                $output .= "<a href=\"$path\">".s($filename)."</a>";
                if ($canexport) {
                    $button->set_callback_options('anonforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_anonforum');
                    $button->set_format_by_file($file);
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= "<br />";

            } else if ($type == 'text') {
                $output .= "$strattachment ".s($filename).":\n$path\n";

            } else { //'returnimages'
                if (in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png'))) {
                    // Image attachments don't get printed as links
                    $imagereturn .= "<br /><img src=\"$path\" alt=\"\" />";
                    if ($canexport) {
                        $button->set_callback_options('anonforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_anonforum');
                        $button->set_format_by_file($file);
                        $imagereturn .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                } else {
                    $output .= "<a href=\"$path\">$iconimage</a> ";
                    $output .= format_text("<a href=\"$path\">".s($filename)."</a>", FORMAT_HTML, array('context'=>$context));
                    if ($canexport) {
                        $button->set_callback_options('anonforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), 'mod_anonforum');
                        $button->set_format_by_file($file);
                        $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                    $output .= '<br />';
                }
            }

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir.'/plagiarismlib.php');
                $output .= plagiarism_get_links(array('userid' => $post->userid,
                    'file' => $file,
                    'cmid' => $cm->id,
                    'course' => $post->course,
                    'anonforum' => $post->anonforum));
                $output .= '<br />';
            }
        }
    }

    if ($type !== 'separateimages') {
        return $output;

    } else {
        return array($output, $imagereturn);
    }
}

////////////////////////////////////////////////////////////////////////////////
// File API                                                                   //
////////////////////////////////////////////////////////////////////////////////

/**
 * Lists all browsable file areas
 *
 * @package  mod_anonforum
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @return array
 */
function anonforum_get_file_areas($course, $cm, $context) {
    return array(
        'attachment' => get_string('areaattachment', 'mod_anonforum'),
        'post' => get_string('areapost', 'mod_anonforum'),
    );
}

/**
 * File browsing support for anonforum module.
 *
 * @package  mod_anonforum
 * @category files
 * @param stdClass $browser file browser object
 * @param stdClass $areas file areas
 * @param stdClass $course course object
 * @param stdClass $cm course module
 * @param stdClass $context context module
 * @param string $filearea file area
 * @param int $itemid item ID
 * @param string $filepath file path
 * @param string $filename file name
 * @return file_info instance or null if not found
 */
function anonforum_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG, $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return null;
    }

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return null;
    }

    // Note that anonymous forum_user_can_see_post() additionally allows access for parent roles
    // and it explicitly checks qanda anonymous forum type, too. One day, when we stop requiring
    // course:managefiles, we will need to extend this.
    if (!has_capability('mod/anonforum:viewdiscussion', $context)) {
        return null;
    }

    if (is_null($itemid)) {
        require_once($CFG->dirroot.'/mod/anonforum/locallib.php');
        return new anonforum_file_info_container($browser, $course, $cm, $context, $areas, $filearea);
    }

    static $cached = array();
    // $cached will store last retrieved post, discussion and anonymous forum. To make sure that the cache
    // is cleared between unit tests we check if this is the same session
    if (!isset($cached['sesskey']) || $cached['sesskey'] != sesskey()) {
        $cached = array('sesskey' => sesskey());
    }

    if (isset($cached['post']) && $cached['post']->id == $itemid) {
        $post = $cached['post'];
    } else if ($post = $DB->get_record('anonforum_posts', array('id' => $itemid))) {
        $cached['post'] = $post;
    } else {
        return null;
    }

    if (isset($cached['discussion']) && $cached['discussion']->id == $post->discussion) {
        $discussion = $cached['discussion'];
    } else if ($discussion = $DB->get_record('anonforum_discussions', array('id' => $post->discussion))) {
        $cached['discussion'] = $discussion;
    } else {
        return null;
    }

    if (isset($cached['anonforum']) && $cached['anonforum']->id == $cm->instance) {
        $anonforum = $cached['anonforum'];
    } else if ($anonforum = $DB->get_record('anonforum', array('id' => $cm->instance))) {
        $cached['anonforum'] = $anonforum;
    } else {
        return null;
    }

    $fs = get_file_storage();
    $filepath = is_null($filepath) ? '/' : $filepath;
    $filename = is_null($filename) ? '.' : $filename;
    if (!($storedfile = $fs->get_file($context->id, 'mod_anonforum', $filearea, $itemid, $filepath, $filename))) {
        return null;
    }

    // Checks to see if the user can manage files or is the owner.
    // TODO MDL-33805 - Do not use userid here and move the capability check above.
    if (!has_capability('moodle/course:managefiles', $context) && $storedfile->get_userid() != $USER->id) {
        return null;
    }
    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0 && !has_capability('moodle/site:accessallgroups', $context)) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS && !groups_is_member($discussion->groupid)) {
            return null;
        }
    }

    // Make sure we're allowed to see it...
    if (!anonforum_user_can_see_post($anonforum, $discussion, $post, NULL, $cm)) {
        return null;
    }

    $urlbase = $CFG->wwwroot.'/pluginfile.php';
    return new file_info_stored($browser, $context, $storedfile, $urlbase, $itemid, true, true, false, false);
}

/**
 * Serves the anonforum attachments. Implements needed access control ;-)
 *
 * @package  mod_anonforum
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function anonforum_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $areas = anonforum_get_file_areas($course, $cm, $context);

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return false;
    }

    $postid = (int)array_shift($args);

    if (!$post = $DB->get_record('anonforum_posts', array('id'=>$postid))) {
        return false;
    }

    if (!$discussion = $DB->get_record('anonforum_discussions', array('id'=>$post->discussion))) {
        return false;
    }

    if (!$anonforum = $DB->get_record('anonforum', array('id'=>$cm->instance))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_anonforum/$filearea/$postid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm, $course);
        if ($groupmode == SEPARATEGROUPS) {
            if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
                return false;
            }
        }
    }

    // Make sure we're allowed to see it...
    if (!anonforum_user_can_see_post($anonforum, $discussion, $post, NULL, $cm)) {
        return false;
    }

    // finally send the file
    send_stored_file($file, 0, 0, true, $options); // download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @global object
 * @param object $post is a full post record, including course and anonforum
 * @param object $anonforum
 * @param object $cm
 * @param mixed $mform
 * @param string $unused
 * @return bool
 */
function anonforum_add_attachment($post, $anonforum, $cm, $mform=null, $unused=null) {
    global $DB;

    if (empty($mform)) {
        return false;
    }

    if (empty($post->attachments)) {
        return true;   // Nothing to do
    }

    $context = context_module::instance($cm->id);

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount']>0) ? '1' : '';
    file_save_draft_area_files($post->attachments, $context->id, 'mod_anonforum', 'attachment', $post->id,
            mod_anonforum_post_form::attachment_options($anonforum));

    $DB->set_field('anonforum_posts', 'attachment', $present, array('id'=>$post->id));

    return true;
}

/**
 * Add a new post in an existing discussion.
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return int
 */
function anonforum_add_new_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('anonforum_discussions', array('id' => $post->discussion));
    $anonforum      = $DB->get_record('anonforum', array('id' => $discussion->anonforum));
    $cm         = get_coursemodule_from_instance('anonforum', $anonforum->id);
    $context    = context_module::instance($cm->id);

    $post->created    = $post->modified = time();
    $post->mailed     = ANONFORUM_MAILED_PENDING;
    $post->userid     = $USER->id;
    $post->attachment = "";
    $post->id = $DB->insert_record("anonforum_posts", $post);
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_anonforum', 'post', $post->id,
            mod_anonforum_post_form::editor_options($context, null), $post->message);
    $DB->set_field('anonforum_posts', 'message', $post->message, array('id'=>$post->id));
    anonforum_add_attachment($post, $anonforum, $cm, $mform, $message);

    // Update discussion modified date
    $DB->set_field("anonforum_discussions", "timemodified", $post->modified, array("id" => $post->discussion));
    $DB->set_field("anonforum_discussions", "usermodified", $post->userid, array("id" => $post->discussion));

    if (anonforum_tp_can_track_anonforums($anonforum) && anonforum_tp_is_tracked($anonforum)) {
        anonforum_tp_mark_post_read($post->userid, $post, $post->anonforum);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    anonforum_trigger_content_uploaded_event($post, $cm, 'anonforum_add_new_post');

    return $post->id;
}

/**
 * Update a post
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function anonforum_update_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('anonforum_discussions', array('id' => $post->discussion));
    $anonforum      = $DB->get_record('anonforum', array('id' => $discussion->anonforum));
    $cm         = get_coursemodule_from_instance('anonforum', $anonforum->id);
    $context    = context_module::instance($cm->id);

    $post->modified = time();

    $DB->update_record('anonforum_posts', $post);

    $discussion->timemodified = $post->modified; // last modified tracking
    $discussion->usermodified = $post->userid;   // last modified tracking

    if (!$post->parent) {   // Post is a discussion starter - update discussion title and times too
        $discussion->name      = $post->subject;
        $discussion->timestart = $post->timestart;
        $discussion->timeend   = $post->timeend;
    }
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_anonforum', 'post', $post->id,
            mod_anonforum_post_form::editor_options($context, $post->id), $post->message);
    $DB->set_field('anonforum_posts', 'message', $post->message, array('id'=>$post->id));

    $DB->update_record('anonforum_discussions', $discussion);

    anonforum_add_attachment($post, $anonforum, $cm, $mform, $message);

    if (anonforum_tp_can_track_anonforums($anonforum) && anonforum_tp_is_tracked($anonforum)) {
        anonforum_tp_mark_post_read($post->userid, $post, $post->anonforum);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    anonforum_trigger_content_uploaded_event($post, $cm, 'anonforum_update_post');

    return true;
}

/**
 * Given an object containing all the necessary data,
 * create a new discussion and return the id
 *
 * @param object $post
 * @param mixed $mform
 * @param string $unused
 * @param int $userid
 * @return object
 */
function anonforum_add_discussion($discussion, $mform=null, $unused=null, $userid=null) {
    global $USER, $CFG, $DB;

    $timenow = time();

    if (is_null($userid)) {
        $userid = $USER->id;
    }

    // The first post is stored as a real post, and linked
    // to from the discuss entry.

    $anonforum = $DB->get_record('anonforum', array('id'=>$discussion->anonforum));
    $cm    = get_coursemodule_from_instance('anonforum', $anonforum->id);

    $post = new stdClass();
    $post->discussion    = 0;
    $post->parent        = 0;
    $post->userid        = $userid;
    $post->created       = $timenow;
    $post->modified      = $timenow;
    $post->mailed        = ANONFORUM_MAILED_PENDING;
    $post->subject       = $discussion->name;
    $post->message       = $discussion->message;
    $post->messageformat = $discussion->messageformat;
    $post->messagetrust  = $discussion->messagetrust;
    $post->attachments   = isset($discussion->attachments) ? $discussion->attachments : null;
    $post->anonforum         = $anonforum->id;     // speedup
    $post->course        = $anonforum->course; // speedup
    $post->mailnow       = $discussion->mailnow;
    $post->anonymouspost = $discussion->anonymouspost;

    $post->id = $DB->insert_record("anonforum_posts", $post);

    // TODO: Fix the calling code so that there always is a $cm when this function is called
    if (!empty($cm->id) && !empty($discussion->itemid)) {   // In "single simple discussions" this may not exist yet
        $context = context_module::instance($cm->id);
        $text = file_save_draft_area_files($discussion->itemid, $context->id, 'mod_anonforum', 'post', $post->id,
                mod_anonforum_post_form::editor_options($context, null), $post->message);
        $DB->set_field('anonforum_posts', 'message', $text, array('id'=>$post->id));
    }

    // Now do the main entry for the discussion, linking to this first post

    $discussion->firstpost    = $post->id;
    $discussion->timemodified = $timenow;
    $discussion->usermodified = $post->userid;
    $discussion->userid       = $userid;

    $post->discussion = $DB->insert_record("anonforum_discussions", $discussion);

    // Finally, set the pointer on the post.
    $DB->set_field("anonforum_posts", "discussion", $post->discussion, array("id"=>$post->id));

    if (!empty($cm->id)) {
        anonforum_add_attachment($post, $anonforum, $cm, $mform, $unused);
    }

    if (anonforum_tp_can_track_anonforums($anonforum) && anonforum_tp_is_tracked($anonforum)) {
        anonforum_tp_mark_post_read($post->userid, $post, $post->anonforum);
    }

    // Let Moodle know that assessable content is uploaded (eg for plagiarism detection)
    if (!empty($cm->id)) {
        anonforum_trigger_content_uploaded_event($post, $cm, 'anonforum_add_discussion');
    }

    return $post->discussion;
}


/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @global object
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire anonforum
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $anonforum Forum
 * @return bool
 */
function anonforum_delete_discussion($discussion, $fulldelete, $course, $cm, $anonforum) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $result = true;

    if ($posts = $DB->get_records("anonforum_posts", array("discussion" => $discussion->id))) {
        foreach ($posts as $post) {
            $post->course = $discussion->course;
            $post->anonforum  = $discussion->anonforum;
            if (!anonforum_delete_post($post, 'ignore', $course, $cm, $anonforum, $fulldelete)) {
                $result = false;
            }
        }
    }

    anonforum_tp_delete_read_records(-1, -1, $discussion->id);

    if (!$DB->delete_records("anonforum_discussions", array("id"=>$discussion->id))) {
        $result = false;
    }

    // Update completion state if we are tracking completion based on number of posts
    // But don't bother when deleting whole thing
    if (!$fulldelete) {
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
           ($anonforum->completiondiscussions || $anonforum->completionreplies || $anonforum->completionposts)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    return $result;
}


/**
 * Deletes a single anonforum post.
 *
 * @global object
 * @param object $post Forum post object
 * @param mixed $children Whether to delete children. If false, returns false
 *   if there are any children (without deleting the post). If true,
 *   recursively deletes all children. If set to special value 'ignore', deletes
 *   post regardless of children (this is for use only when deleting all posts
 *   in a disussion).
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $anonforum Forum
 * @param bool $skipcompletion True to skip updating completion state if it
 *   would otherwise be updated, i.e. when deleting entire anonforum anyway.
 * @return bool
 */
function anonforum_delete_post($post, $children, $course, $cm, $anonforum, $skipcompletion=false) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $context = context_module::instance($cm->id);

    if ($children !== 'ignore' && ($childposts = $DB->get_records('anonforum_posts', array('parent'=>$post->id)))) {
       if ($children) {
           foreach ($childposts as $childpost) {
               anonforum_delete_post($childpost, true, $course, $cm, $anonforum, $skipcompletion);
           }
       } else {
           return false;
       }
    }

    // Delete ratings.
    require_once($CFG->dirroot.'/rating/lib.php');
    $delopt = new stdClass;
    $delopt->contextid = $context->id;
    $delopt->component = 'mod_anonforum';
    $delopt->ratingarea = 'post';
    $delopt->itemid = $post->id;
    $rm = new rating_manager();
    $rm->delete_ratings($delopt);

    // Delete attachments.
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_anonforum', 'attachment', $post->id);
    $fs->delete_area_files($context->id, 'mod_anonforum', 'post', $post->id);

    // Delete cached RSS feeds.
    if (!empty($CFG->enablerssfeeds)) {
        require_once($CFG->dirroot.'/mod/anonforum/rsslib.php');
        anonforum_rss_delete_file($anonforum);
    }

    if ($DB->delete_records("anonforum_posts", array("id" => $post->id))) {

        anonforum_tp_delete_read_records(-1, $post->id);

    // Just in case we are deleting the last post
        anonforum_discussion_update_last_post($post->discussion);

        // Update completion state if we are tracking completion based on number of posts
        // But don't bother when deleting whole thing

        if (!$skipcompletion) {
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
               ($anonforum->completiondiscussions || $anonforum->completionreplies || $anonforum->completionposts)) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
            }
        }

        return true;
    }
    return false;
}

/**
 * Sends post content to plagiarism plugin
 * @param object $post Forum post object
 * @param object $cm Course-module
 * @param string $name
 * @return bool
*/
function anonforum_trigger_content_uploaded_event($post, $cm, $name) {
    $context = context_module::instance($cm->id);
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_anonforum', 'attachment', $post->id, "timemodified", false);
    $params = array(
        'context' => $context,
        'objectid' => $post->id,
        'other' => array(
            'content' => $post->message,
            'discussionid' => $post->discussion,
            'pathnamehashes' => array_keys($files),
            'triggeredfrom' => $name,
        )
    );
    $event = \mod_anonforum\event\assessable_uploaded::create($params);
    $event->trigger();
    return true;
}

/**
 * @global object
 * @param object $post
 * @param bool $children
 * @return int
 */
function anonforum_count_replies($post, $children=true) {
    global $DB;
    $count = 0;

    if ($children) {
        if ($childposts = $DB->get_records('anonforum_posts', array('parent' => $post->id))) {
           foreach ($childposts as $childpost) {
               $count ++;                   // For this child
               $count += anonforum_count_replies($childpost, true);
           }
        }
    } else {
        $count += $DB->count_records('anonforum_posts', array('parent' => $post->id));
    }

    return $count;
}


/**
 * @global object
 * @param int $anonforumid
 * @param mixed $value
 * @return bool
 */
function anonforum_forcesubscribe($anonforumid, $value=1) {
    global $DB;
    return $DB->set_field("anonforum", "forcesubscribe", $value, array("id" => $anonforumid));
}

/**
 * @global object
 * @param object $anonforum
 * @return bool
 */
function anonforum_is_forcesubscribed($anonforum) {
    global $DB;
    if (isset($anonforum->forcesubscribe)) {    // then we use that
        return ($anonforum->forcesubscribe == ANONFORUM_FORCESUBSCRIBE);
    } else {   // Check the database
       return ($DB->get_field('anonforum', 'forcesubscribe', array('id' => $anonforum)) == ANONFORUM_FORCESUBSCRIBE);
    }
}

function anonforum_get_forcesubscribed($anonforum) {
    global $DB;
    if (isset($anonforum->forcesubscribe)) {    // then we use that
        return $anonforum->forcesubscribe;
    } else {   // Check the database
        return $DB->get_field('anonforum', 'forcesubscribe', array('id' => $anonforum));
    }
}

/**
 * @global object
 * @param int $userid
 * @param object $anonforum
 * @return bool
 */
function anonforum_is_subscribed($userid, $anonforum) {
    global $DB;
    if (is_numeric($anonforum)) {
        $anonforum = $DB->get_record('anonforum', array('id' => $anonforum));
    }
    // If anonymous forum is force subscribed and has allowforcesubscribe, then user is subscribed.
    $cm = get_coursemodule_from_instance('anonforum', $anonforum->id);
    if (anonforum_is_forcesubscribed($anonforum) && $cm &&
            has_capability('mod/anonforum:allowforcesubscribe', context_module::instance($cm->id), $userid)) {
        return true;
    }
    return $DB->record_exists("anonforum_subscriptions", array("userid" => $userid, "anonforum" => $anonforum->id));
}

function anonforum_get_subscribed_anonforums($course) {
    global $USER, $CFG, $DB;
    $sql = "SELECT f.id
              FROM {anonforum} f
                   LEFT JOIN {anonforum_subscriptions} fs ON (fs.anonforum = f.id AND fs.userid = ?)
             WHERE f.course = ?
                   AND f.forcesubscribe <> ".ANONFORUM_DISALLOWSUBSCRIBE."
                   AND (f.forcesubscribe = ".ANONFORUM_FORCESUBSCRIBE." OR fs.id IS NOT NULL)";
    if ($subscribed = $DB->get_records_sql($sql, array($USER->id, $course->id))) {
        foreach ($subscribed as $s) {
            $subscribed[$s->id] = $s->id;
        }
        return $subscribed;
    } else {
        return array();
    }
}

/**
 * Returns an array of anonforums that the current user is subscribed to and is allowed to unsubscribe from
 *
 * @return array An array of unsubscribable anonforums
 */
function anonforum_get_optional_subscribed_anonforums() {
    global $USER, $DB;

    // Get courses that $USER is enrolled in and can see
    $courses = enrol_get_my_courses();
    if (empty($courses)) {
        return array();
    }

    $courseids = array();
    foreach($courses as $course) {
        $courseids[] = $course->id;
    }
    list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

    // get all anonymous forums from the user's courses that they are subscribed to and which are not set to forced
    $sql = "SELECT f.id, cm.id as cm, cm.visible
              FROM {anonforum} f
                   JOIN {course_modules} cm ON cm.instance = f.id
                   JOIN {modules} m ON m.name = :modulename AND m.id = cm.module
                   LEFT JOIN {anonforum_subscriptions} fs ON (fs.anonforum = f.id AND fs.userid = :userid)
             WHERE f.forcesubscribe <> :forcesubscribe AND fs.id IS NOT NULL
                   AND cm.course $coursesql";
    $params = array_merge($courseparams, array('modulename'=>'anonforum', 'userid'=>$USER->id, 'forcesubscribe'=>ANONFORUM_FORCESUBSCRIBE));
    if (!$anonforums = $DB->get_records_sql($sql, $params)) {
        return array();
    }

    $unsubscribableanonforums = array(); // Array to return

    foreach($anonforums as $anonforum) {

        if (empty($anonforum->visible)) {
            // the anonymous forum is hidden
            $context = context_module::instance($anonforum->cm);
            if (!has_capability('moodle/course:viewhiddenactivities', $context)) {
                // the user can't see the hidden anonymous forum
                continue;
            }
        }

        // subscribe.php only requires 'mod/anonymous forum:managesubscriptions' when
        // unsubscribing a user other than yourself so we don't require it here either

        // A check for whether the anonymous forum has subscription set to forced is built into the SQL above

        $unsubscribableanonforums[] = $anonforum;
    }

    return $unsubscribableanonforums;
}

/**
 * Adds user to the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $anonforumid
 */
function anonforum_subscribe($userid, $anonforumid) {
    global $DB;

    if ($DB->record_exists("anonforum_subscriptions", array("userid"=>$userid, "anonforum"=>$anonforumid))) {
        return true;
    }

    $sub = new stdClass();
    $sub->userid  = $userid;
    $sub->anonforum = $anonforumid;

    return $DB->insert_record("anonforum_subscriptions", $sub);
}

/**
 * Removes user from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $anonforumid
 */
function anonforum_unsubscribe($userid, $anonforumid) {
    global $DB;
    return ($DB->delete_records('anonforum_digests', array('userid' => $userid, 'anonforum' => $anonforumid))
            && $DB->delete_records('anonforum_subscriptions', array('userid' => $userid, 'anonforum' => $anonforumid)));
}

/**
 * Given a new post, subscribes or unsubscribes as appropriate.
 * Returns some text which describes what happened.
 *
 * @global objec
 * @param object $post
 * @param object $anonforum
 */
function anonforum_post_subscription($post, $anonforum) {

    global $USER;

    $action = '';
    $subscribed = anonforum_is_subscribed($USER->id, $anonforum);

    if ($anonforum->forcesubscribe == ANONFORUM_FORCESUBSCRIBE) { // database ignored
        return "";

    } elseif (($anonforum->forcesubscribe == ANONFORUM_DISALLOWSUBSCRIBE)
        && !has_capability('moodle/course:manageactivities', context_course::instance($anonforum->course), $USER->id)) {
        if ($subscribed) {
            $action = 'unsubscribe'; // sanity check, following MDL-14558
        } else {
            return "";
        }

    } else { // go with the user's choice
        if (isset($post->subscribe)) {
            // no change
            if ((!empty($post->subscribe) && $subscribed)
                || (empty($post->subscribe) && !$subscribed)) {
                return "";

            } elseif (!empty($post->subscribe) && !$subscribed) {
                $action = 'subscribe';

            } elseif (empty($post->subscribe) && $subscribed) {
                $action = 'unsubscribe';
            }
        }
    }

    $info = new stdClass();
    $info->anonforum = $anonforum->name;

    if (empty($post->anonymouspost)) {
        $info->name  = fullname($USER);
    } else {
        $info->name  = get_string('anonymoususer', 'anonforum');
    }


    switch ($action) {
        case 'subscribe':
            anonforum_subscribe($USER->id, $post->anonforum);
            return "<p>".get_string("nowsubscribed", "anonforum", $info)."</p>";
        case 'unsubscribe':
            anonforum_unsubscribe($USER->id, $post->anonforum);
            return "<p>".get_string("nownotsubscribed", "anonforum", $info)."</p>";
    }
}

/**
 * Generate and return the subscribe or unsubscribe link for a anonforum.
 *
 * @param object $anonforum the anonforum. Fields used are $anonforum->id and $anonforum->forcesubscribe.
 * @param object $context the context object for this anonforum.
 * @param array $messages text used for the link in its various states
 *      (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
 *      Any strings not passed in are taken from the $defaultmessages array
 *      at the top of the function.
 * @param bool $cantaccessagroup
 * @param bool $fakelink
 * @param bool $backtoindex
 * @param array $subscribed_anonforums
 * @return string
 */
function anonforum_get_subscribe_link($anonforum, $context, $messages = array(), $cantaccessagroup = false, $fakelink=true, $backtoindex=false, $subscribed_anonforums=null) {
    global $CFG, $USER, $PAGE, $OUTPUT;
    $defaultmessages = array(
        'subscribed' => get_string('unsubscribe', 'anonforum'),
        'unsubscribed' => get_string('subscribe', 'anonforum'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'anonforum'),
        'cantsubscribe' => get_string('disallowsubscribe','anonforum')
    );
    $messages = $messages + $defaultmessages;

    if (anonforum_is_forcesubscribed($anonforum)) {
        return $messages['forcesubscribed'];
    } else if ($anonforum->forcesubscribe == ANONFORUM_DISALLOWSUBSCRIBE && !has_capability('mod/anonforum:managesubscriptions', $context)) {
        return $messages['cantsubscribe'];
    } else if ($cantaccessagroup) {
        return $messages['cantaccessgroup'];
    } else {
        if (!is_enrolled($context, $USER, '', true)) {
            return '';
        }
        if (is_null($subscribed_anonforums)) {
            $subscribed = anonforum_is_subscribed($USER->id, $anonforum);
        } else {
            $subscribed = !empty($subscribed_anonforums[$anonforum->id]);
        }
        if ($subscribed) {
            $linktext = $messages['subscribed'];
            $linktitle = get_string('subscribestop', 'anonforum');
        } else {
            $linktext = $messages['unsubscribed'];
            $linktitle = get_string('subscribestart', 'anonforum');
        }

        $options = array();
        if ($backtoindex) {
            $backtoindexlink = '&amp;backtoindex=1';
            $options['backtoindex'] = 1;
        } else {
            $backtoindexlink = '';
        }
        $link = '';

        if ($fakelink) {
            $PAGE->requires->js('/mod/anonforum/anonforum.js');
            $PAGE->requires->js_function_call('anonforum_produce_subscribe_link', array($anonforum->id, $backtoindexlink, $linktext, $linktitle));
            $link = "<noscript>";
        }
        $options['id'] = $anonforum->id;
        $options['sesskey'] = sesskey();
        $url = new moodle_url('/mod/anonforum/subscribe.php', $options);
        $link .= $OUTPUT->single_button($url, $linktext, 'get', array('title'=>$linktitle));
        if ($fakelink) {
            $link .= '</noscript>';
        }

        return $link;
    }
}


/**
 * Generate and return the track or no track link for a anonforum.
 *
 * @global object
 * @global object
 * @global object
 * @param object $anonforum the anonforum. Fields used are $anonforum->id and $anonforum->forcesubscribe.
 * @param array $messages
 * @param bool $fakelink
 * @return string
 */
function anonforum_get_tracking_link($anonforum, $messages=array(), $fakelink=true) {
    global $CFG, $USER, $PAGE, $OUTPUT;

    static $strnotrackanonforum, $strtrackanonforum;

    if (isset($messages['trackanonforum'])) {
         $strtrackanonforum = $messages['trackanonforum'];
    }
    if (isset($messages['notrackanonforum'])) {
         $strnotrackanonforum = $messages['notrackanonforum'];
    }
    if (empty($strtrackanonforum)) {
        $strtrackanonforum = get_string('trackanonforum', 'anonforum');
    }
    if (empty($strnotrackanonforum)) {
        $strnotrackanonforum = get_string('notrackanonforum', 'anonforum');
    }

    if (anonforum_tp_is_tracked($anonforum)) {
        $linktitle = $strnotrackanonforum;
        $linktext = $strnotrackanonforum;
    } else {
        $linktitle = $strtrackanonforum;
        $linktext = $strtrackanonforum;
    }

    $link = '';
    if ($fakelink) {
        $PAGE->requires->js('/mod/anonforum/anonforum.js');
        $PAGE->requires->js_function_call('anonforum_produce_tracking_link', Array($anonforum->id, $linktext, $linktitle));
        // use <noscript> to print button in case javascript is not enabled
        $link .= '<noscript>';
    }
    $url = new moodle_url('/mod/anonforum/settracking.php', array('id'=>$anonforum->id));
    $link .= $OUTPUT->single_button($url, $linktext, 'get', array('title'=>$linktitle));

    if ($fakelink) {
        $link .= '</noscript>';
    }

    return $link;
}



/**
 * Returns true if user created new discussion already
 *
 * @global object
 * @global object
 * @param int $anonforumid
 * @param int $userid
 * @return bool
 */
function anonforum_user_has_posted_discussion($anonforumid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT 'x'
              FROM {anonforum_discussions} d, {anonforum_posts} p
             WHERE d.anonforum = ? AND p.discussion = d.id AND p.parent = 0 and p.userid = ?";

    return $DB->record_exists_sql($sql, array($anonforumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $anonforumid
 * @param int $userid
 * @return array
 */
function anonforum_discussions_user_has_posted_in($anonforumid, $userid) {
    global $CFG, $DB;

    $haspostedsql = "SELECT d.id AS id,
                            d.*
                       FROM {anonforum_posts} p,
                            {anonforum_discussions} d
                      WHERE p.discussion = d.id
                        AND d.anonforum = ?
                        AND p.userid = ?";

    return $DB->get_records_sql($haspostedsql, array($anonforumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $anonforumid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function anonforum_user_has_posted($anonforumid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // posted in any anonymous forum discussion?
        $sql = "SELECT 'x'
                  FROM {anonforum_posts} p
                  JOIN {anonforum_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.anonforum = :anonforumid";
        return $DB->record_exists_sql($sql, array('anonforumid'=>$anonforumid,'userid'=>$userid));
    } else {
        return $DB->record_exists('anonforum_posts', array('discussion'=>$did,'userid'=>$userid));
    }
}

/**
 * Returns creation time of the first user's post in given discussion
 * @global object $DB
 * @param int $did Discussion id
 * @param int $userid User id
 * @return int|bool post creation time stamp or return false
 */
function anonforum_get_user_posted_time($did, $userid) {
    global $DB;

    $posttime = $DB->get_field('anonforum_posts', 'MIN(created)', array('userid'=>$userid, 'discussion'=>$did));
    if (empty($posttime)) {
        return false;
    }
    return $posttime;
}

/**
 * @global object
 * @param object $anonforum
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function anonforum_user_can_post_discussion($anonforum, $currentgroup=null, $unused=-1, $cm=NULL, $context=NULL) {
// $anonymous forum is an object
    global $USER;

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($cm);
    }

    $groupmode = groups_get_activity_groupmode($cm);

    if ($anonforum->type == 'news') {
        $capname = 'mod/anonforum:addnews';
    } else if ($anonforum->type == 'qanda') {
        $capname = 'mod/anonforum:addquestion';
    } else {
        $capname = 'mod/anonforum:startdiscussion';
    }

    if (!has_capability($capname, $context)) {
        return false;
    }

    if ($anonforum->type == 'single') {
        return false;
    }

    if ($anonforum->type == 'eachuser') {
        if (anonforum_user_has_posted_discussion($anonforum->id, $USER->id)) {
            return false;
        }
    }

    if (!$groupmode or has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($currentgroup) {
        return groups_is_member($currentgroup);
    } else {
        // no group membership and no accessallgroups means no new discussions
        // reverted to 1.7 behaviour in 1.9+,  buggy in 1.8.0-1.9.0
        return false;
    }
}

/**
 * This function checks whether the user can reply to posts in a anonforum
 * discussion. Use anonforum_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $anonforum anonforum object
 * @param object $discussion
 * @param object $user
 * @param object $cm
 * @param object $course
 * @param object $context
 * @return bool
 */
function anonforum_user_can_post($anonforum, $discussion, $user=NULL, $cm=NULL, $course=NULL, $context=NULL) {
    global $USER, $DB;
    if (empty($user)) {
        $user = $USER;
    }

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if (!isset($discussion->groupid)) {
        debugging('incorrect discussion parameter', DEBUG_DEVELOPER);
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', array('id' => $anonforum->course))) {
            print_error('invalidcourseid');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    // normal users with temporary guest access can not post, suspended users can not post either
    if (!is_viewing($context, $user->id) and !is_enrolled($context, $user->id, '', true)) {
        return false;
    }

    if ($anonforum->type == 'news') {
        $capname = 'mod/anonforum:replynews';
    } else {
        $capname = 'mod/anonforum:replypost';
    }

    if (!has_capability($capname, $context, $user->id)) {
        return false;
    }

    if (!$groupmode = groups_get_activity_groupmode($cm, $course)) {
        return true;
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($groupmode == VISIBLEGROUPS) {
        if ($discussion->groupid == -1) {
            // allow students to reply to all participants discussions - this was not possible in Moodle <1.8
            return true;
        }
        return groups_is_member($discussion->groupid);

    } else {
        //separate groups
        if ($discussion->groupid == -1) {
            return false;
        }
        return groups_is_member($discussion->groupid);
    }
}

/**
 * Checks to see if a user can view a particular post.
 *
 * @deprecated since Moodle 2.4 use anonforum_user_can_see_post() instead
 *
 * @param object $post
 * @param object $course
 * @param object $cm
 * @param object $anonforum
 * @param object $discussion
 * @param object $user
 * @return boolean
 */
function anonforum_user_can_view_post($post, $course, $cm, $anonforum, $discussion, $user=null){
    debugging('anonforum_user_can_view_post() is deprecated. Please use anonforum_user_can_see_post() instead.', DEBUG_DEVELOPER);
    return anonforum_user_can_see_post($anonforum, $discussion, $post, $user, $cm);
}

/**
* Check to ensure a user can view a timed discussion.
*
* @param object $discussion
* @param object $user
* @param object $context
* @return boolean returns true if they can view post, false otherwise
*/
function anonforum_user_can_see_timed_discussion($discussion, $user, $context) {
    global $CFG;

    // Check that the user can view a discussion that is normally hidden due to access times.
    if (!empty($CFG->anonforum_enabletimedposts)) {
        $time = time();
        if (($discussion->timestart != 0 && $discussion->timestart > $time)
            || ($discussion->timeend != 0 && $discussion->timeend < $time)) {
            if (!has_capability('mod/anonforum:viewhiddentimedposts', $context, $user->id)) {
                return false;
            }
        }
    }

    return true;
}

/**
* Check to ensure a user can view a group discussion.
*
* @param object $discussion
* @param object $cm
* @param object $context
* @return boolean returns true if they can view post, false otherwise
*/
function anonforum_user_can_see_group_discussion($discussion, $cm, $context) {

    // If it's a grouped discussion, make sure the user is a member.
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS) {
            return groups_is_member($discussion->groupid) || has_capability('moodle/site:accessallgroups', $context);
        }
    }

    return true;
}

/**
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @param object $anonforum
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function anonforum_user_can_see_discussion($anonforum, $discussion, $context, $user=NULL) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // retrieve objects (yuk)
    if (is_numeric($anonforum)) {
        debugging('missing full anonforum', DEBUG_DEVELOPER);
        if (!$anonforum = $DB->get_record('anonforum',array('id'=>$anonforum))) {
            return false;
        }
    }
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('anonforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course)) {
        print_error('invalidcoursemodule');
    }

    if (!has_capability('mod/anonforum:viewdiscussion', $context)) {
        return false;
    }

    if (!anonforum_user_can_see_timed_discussion($discussion, $user, $context)) {
        return false;
    }

    if (!anonforum_user_can_see_group_discussion($discussion, $cm, $context)) {
        return false;
    }

    if ($anonforum->type == 'qanda' &&
            !anonforum_user_has_posted($anonforum->id, $discussion->id, $user->id) &&
            !has_capability('mod/anonforum:viewqandawithoutposting', $context)) {
        return false;
    }
    return true;
}

/**
 * @global object
 * @global object
 * @param object $anonforum
 * @param object $discussion
 * @param object $post
 * @param object $user
 * @param object $cm
 * @return bool
 */
function anonforum_user_can_see_post($anonforum, $discussion, $post, $user=NULL, $cm=NULL) {
    global $CFG, $USER, $DB;

    // Context used throughout function.
    $modcontext = context_module::instance($cm->id);

    // retrieve objects (yuk)
    if (is_numeric($anonforum)) {
        debugging('missing full anonforum', DEBUG_DEVELOPER);
        if (!$anonforum = $DB->get_record('anonforum',array('id'=>$anonforum))) {
            return false;
        }
    }

    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('anonforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('anonforum_posts',array('id'=>$post))) {
            return false;
        }
    }

    if (!isset($post->id) && isset($post->parent)) {
        $post->id = $post->parent;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    $canviewdiscussion = !empty($cm->cache->caps['mod/anonforum:viewdiscussion']) || has_capability('mod/anonforum:viewdiscussion', $modcontext, $user->id);
    if (!$canviewdiscussion && !has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), context_user::instance($post->userid))) {
        return false;
    }

    if (isset($cm->uservisible)) {
        if (!$cm->uservisible) {
            return false;
        }
    } else {
        if (!coursemodule_visible_for_user($cm, $user->id)) {
            return false;
        }
    }

    if (!anonforum_user_can_see_timed_discussion($discussion, $user, $modcontext)) {
        return false;
    }

    if (!anonforum_user_can_see_group_discussion($discussion, $cm, $modcontext)) {
        return false;
    }

    if ($anonforum->type == 'qanda') {
        $firstpost = anonforum_get_firstpost_from_discussion($discussion->id);
        $userfirstpost = anonforum_get_user_posted_time($discussion->id, $user->id);

        return (($userfirstpost !== false && (time() - $userfirstpost >= $CFG->maxeditingtime)) ||
                $firstpost->id == $post->id || $post->userid == $user->id || $firstpost->userid == $user->id ||
                has_capability('mod/anonforum:viewqandawithoutposting', $modcontext, $user->id));
    }
    return true;
}


/**
 * Prints the discussion view screen for a anonforum.
 *
 * @global object
 * @global object
 * @param object $course The current course object.
 * @param object $anonforum Forum to be printed.
 * @param int $maxdiscussions .
 * @param string $displayformat The display format to use (optional).
 * @param string $sort Sort arguments for database query (optional).
 * @param int $groupmode Group mode of the anonforum (optional).
 * @param void $unused (originally current group)
 * @param int $page Page mode, page to display (optional).
 * @param int $perpage The maximum number of discussions per page(optional)
 *
 */
function anonforum_print_latest_discussions($course, $anonforum, $maxdiscussions=-1, $displayformat='plain', $sort='',
                                        $currentgroup=-1, $groupmode=-1, $page=-1, $perpage=100, $cm=NULL) {
    global $CFG, $USER, $OUTPUT;

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course)) {
            print_error('invalidcoursemodule');
        }
    }
    $context = context_module::instance($cm->id);

    if (empty($sort)) {
        $sort = "d.timemodified DESC";
    }

    $olddiscussionlink = false;

 // Sort out some defaults
    if ($perpage <= 0) {
        $perpage = 0;
        $page    = -1;
    }

    if ($maxdiscussions == 0) {
        // all discussions - backwards compatibility
        $page    = -1;
        $perpage = 0;
        if ($displayformat == 'plain') {
            $displayformat = 'header';  // Abbreviate display by default
        }

    } else if ($maxdiscussions > 0) {
        $page    = -1;
        $perpage = $maxdiscussions;
    }

    $fullpost = false;
    if ($displayformat == 'plain') {
        $fullpost = true;
    }


// Decide if current user is allowed to see ALL the current discussions or not

// First check the group stuff
    if ($currentgroup == -1 or $groupmode == -1) {
        $groupmode    = groups_get_activity_groupmode($cm, $course);
        $currentgroup = groups_get_activity_group($cm);
    }

    $groups = array(); //cache

// If the user can post discussions, then this is a good place to put the
// button for it. We do not show the button if we are showing site news
// and the current user is a guest.

    $canstart = anonforum_user_can_post_discussion($anonforum, $currentgroup, $groupmode, $cm, $context);
    if (!$canstart and $anonforum->type !== 'news') {
        if (isguestuser() or !isloggedin()) {
            $canstart = true;
        }
        if (!is_enrolled($context) and !is_viewing($context)) {
            // allow guests and not-logged-in to see the button - they are prompted to log in after clicking the link
            // normal users with temporary guest access see this button too, they are asked to enrol instead
            // do not show the button to users with suspended enrolments here
            $canstart = enrol_selfenrol_available($course->id);
        }
    }

    if ($canstart) {
        echo '<div class="singlebutton anonforumaddnew">';
        echo "<form id=\"newdiscussionform\" method=\"get\" action=\"$CFG->wwwroot/mod/anonforum/post.php\">";
        echo '<div>';
        echo "<input type=\"hidden\" name=\"anonforum\" value=\"$anonforum->id\" />";
        switch ($anonforum->type) {
            case 'news':
            case 'blog':
                $buttonadd = get_string('addanewtopic', 'anonforum');
                break;
            case 'qanda':
                $buttonadd = get_string('addanewquestion', 'anonforum');
                break;
            default:
                $buttonadd = get_string('addanewdiscussion', 'anonforum');
                break;
        }
        echo '<input type="submit" value="'.$buttonadd.'" />';
        echo '</div>';
        echo '</form>';
        echo "</div>\n";

    } else if (isguestuser() or !isloggedin() or $anonforum->type == 'news') {
        // no button and no info

    } else if ($groupmode and has_capability('mod/anonforum:startdiscussion', $context)) {
        // inform users why they can not post new discussion
        if ($currentgroup) {
            echo $OUTPUT->notification(get_string('cannotadddiscussion', 'anonforum'));
        } else {
            echo $OUTPUT->notification(get_string('cannotadddiscussionall', 'anonforum'));
        }
    }

// Get all the recent discussions we're allowed to see

    $getuserlastmodified = ($displayformat == 'header');

    if (! $discussions = anonforum_get_discussions($cm, $sort, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage) ) {
        echo '<div class="anonforumnodiscuss">';
        if ($anonforum->type == 'news') {
            echo '('.get_string('nonews', 'anonforum').')';
        } else if ($anonforum->type == 'qanda') {
            echo '('.get_string('noquestions','anonforum').')';
        } else {
            echo '('.get_string('nodiscussions', 'anonforum').')';
        }
        echo "</div>\n";
        return;
    }

// If we want paging
    if ($page != -1) {
        ///Get the number of discussions found
        $numdiscussions = anonforum_get_discussions_count($cm);

        ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$anonforum->id");
        if ($numdiscussions > 1000) {
            // saves some memory on sites with very large anonymous forums
            $replies = anonforum_count_discussion_replies($anonforum->id, $sort, $maxdiscussions, $page, $perpage);
        } else {
            $replies = anonforum_count_discussion_replies($anonforum->id);
        }

    } else {
        $replies = anonforum_count_discussion_replies($anonforum->id);

        if ($maxdiscussions > 0 and $maxdiscussions <= count($discussions)) {
            $olddiscussionlink = true;
        }
    }

    $canviewparticipants = has_capability('moodle/course:viewparticipants',$context);

    $strdatestring = get_string('strftimerecentfull');

    // Check if the anonymous forum is tracked.
    if ($cantrack = anonforum_tp_can_track_anonforums($anonforum)) {
        $anonforumtracked = anonforum_tp_is_tracked($anonforum);
    } else {
        $anonforumtracked = false;
    }

    if ($anonforumtracked) {
        $unreads = anonforum_get_discussions_unread($cm);
    } else {
        $unreads = array();
    }

    if ($displayformat == 'header') {
        echo '<table cellspacing="0" class="anonforumheaderlist">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="header topic" scope="col">'.get_string('discussion', 'anonforum').'</th>';
        echo '<th class="header author" colspan="2" scope="col">'.get_string('startedby', 'anonforum').'</th>';
        if ($groupmode > 0) {
            echo '<th class="header group" scope="col">'.get_string('group').'</th>';
        }
        if (has_capability('mod/anonforum:viewdiscussion', $context)) {
            echo '<th class="header replies" scope="col">'.get_string('replies', 'anonforum').'</th>';
            // If the anonymous forum can be tracked, display the unread column.
            if ($cantrack) {
                echo '<th class="header replies" scope="col">'.get_string('unread', 'anonforum');
                if ($anonforumtracked) {
                    echo '<a title="'.get_string('markallread', 'anonforum').
                         '" href="'.$CFG->wwwroot.'/mod/anonforum/markposts.php?f='.
                         $anonforum->id.'&amp;mark=read&amp;returnpage=view.php">'.
                         '<img src="'.$OUTPUT->pix_url('t/markasread') . '" class="iconsmall" alt="'.get_string('markallread', 'anonforum').'" /></a>';
                }
                echo '</th>';
            }
        }
        echo '<th class="header lastpost" scope="col">'.get_string('lastpost', 'anonforum').'</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
    }

    foreach ($discussions as $discussion) {
        if (!empty($replies[$discussion->discussion])) {
            $discussion->replies = $replies[$discussion->discussion]->replies;
            $discussion->lastpostid = $replies[$discussion->discussion]->lastpostid;
        } else {
            $discussion->replies = 0;
        }

        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // All posts are read in this case.
        if (!$anonforumtracked) {
            $discussion->unread = '-';
        } else if (empty($USER)) {
            $discussion->unread = 0;
        } else {
            if (empty($unreads[$discussion->discussion])) {
                $discussion->unread = 0;
            } else {
                $discussion->unread = $unreads[$discussion->discussion];
            }
        }

        if (isloggedin()) {
            $ownpost = ($discussion->userid == $USER->id);
        } else {
            $ownpost=false;
        }
        // Use discussion name instead of subject of first post
        $discussion->subject = $discussion->name;

        switch ($displayformat) {
            case 'header':
                if ($groupmode > 0) {
                    if (isset($groups[$discussion->groupid])) {
                        $group = $groups[$discussion->groupid];
                    } else {
                        $group = $groups[$discussion->groupid] = groups_get_group($discussion->groupid);
                    }
                } else {
                    $group = -1;
                }
                anonforum_print_discussion_header($discussion, $anonforum, $group, $strdatestring, $cantrack, $anonforumtracked,
                    $canviewparticipants, $context);
            break;
            default:
                $link = false;

                if ($discussion->replies) {
                    $link = true;
                } else {
                    $modcontext = context_module::instance($cm->id);
                    $link = anonforum_user_can_see_discussion($anonforum, $discussion, $modcontext, $USER);
                }

                $discussion->anonforum = $anonforum->id;

                anonforum_print_post($discussion, $discussion, $anonforum, $cm, $course, $ownpost, 0, $link, false,
                        '', null, true, $anonforumtracked);
            break;
        }
    }

    if ($displayformat == "header") {
        echo '</tbody>';
        echo '</table>';
    }

    if ($olddiscussionlink) {
        if ($anonforum->type == 'news') {
            $strolder = get_string('oldertopics', 'anonforum');
        } else {
            $strolder = get_string('olderdiscussions', 'anonforum');
        }
        echo '<div class="anonforumolddiscuss">';
        echo '<a href="'.$CFG->wwwroot.'/mod/anonforum/view.php?f='.$anonforum->id.'&amp;showall=1">';
        echo $strolder.'</a> ...</div>';
    }

    if ($page != -1) { ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$anonforum->id");
    }
}


/**
 * Prints a anonforum discussion
 *
 * @uses CONTEXT_MODULE
 * @uses ANONFORUM_MODE_FLATNEWEST
 * @uses ANONFORUM_MODE_FLATOLDEST
 * @uses ANONFORUM_MODE_THREADED
 * @uses ANONFORUM_MODE_NESTED
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $anonforum
 * @param stdClass $discussion
 * @param stdClass $post
 * @param int $mode
 * @param mixed $canreply
 * @param bool $canrate
 */
function anonforum_print_discussion($course, $cm, $anonforum, $discussion, $post, $mode, $canreply=NULL, $canrate=false) {
    global $USER, $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ownpost = (isloggedin() && $USER->id == $post->userid);

    $modcontext = context_module::instance($cm->id);
    if ($canreply === NULL) {
        $reply = anonforum_user_can_post($anonforum, $discussion, $USER, $cm, $course, $modcontext);
    } else {
        $reply = $canreply;
    }

    // $cm holds general cache for anonymous forum functions
    $cm->cache = new stdClass;
    $cm->cache->groups      = groups_get_all_groups($course->id, 0, $cm->groupingid);
    $cm->cache->usersgroups = array();

    $posters = array();

    // preload all posts - TODO: improve...
    if ($mode == ANONFORUM_MODE_FLATNEWEST) {
        $sort = "p.created DESC";
    } else {
        $sort = "p.created ASC";
    }

    $anonforumtracked = anonforum_tp_is_tracked($anonforum);
    $posts = anonforum_get_all_discussion_posts($discussion->id, $sort, $anonforumtracked);
    $post = $posts[$post->id];

    foreach ($posts as $pid=>$p) {
        $posters[$p->userid] = $p->userid;
    }

    // preload all groups of ppl that posted in this discussion
    if ($postersgroups = groups_get_all_groups($course->id, $posters, $cm->groupingid, 'gm.id, gm.groupid, gm.userid')) {
        foreach($postersgroups as $pg) {
            if (!isset($cm->cache->usersgroups[$pg->userid])) {
                $cm->cache->usersgroups[$pg->userid] = array();
            }
            $cm->cache->usersgroups[$pg->userid][$pg->groupid] = $pg->groupid;
        }
        unset($postersgroups);
    }

    //load ratings
    if ($anonforum->assessed != RATING_AGGREGATE_NONE) {
        $ratingoptions = new stdClass;
        $ratingoptions->context = $modcontext;
        $ratingoptions->component = 'mod_anonforum';
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->items = $posts;
        $ratingoptions->aggregate = $anonforum->assessed;//the aggregation method
        $ratingoptions->scaleid = $anonforum->scale;
        $ratingoptions->userid = $USER->id;
        if ($anonforum->type == 'single' or !$discussion->id) {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/anonforum/view.php?id=$cm->id";
        } else {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/anonforum/discuss.php?d=$discussion->id";
        }
        $ratingoptions->assesstimestart = $anonforum->assesstimestart;
        $ratingoptions->assesstimefinish = $anonforum->assesstimefinish;

        $rm = new rating_manager();
        $posts = $rm->get_ratings($ratingoptions);
    }


    $post->anonforum = $anonforum->id;   // Add the anonforum id to the post object, later used by anonforum_print_post
    $post->anonforumtype = $anonforum->type;

    $post->subject = format_string($post->subject);

    $postread = !empty($post->postread);

    anonforum_print_post($post, $discussion, $anonforum, $cm, $course, $ownpost, $reply, false,
                         '', '', $postread, true, $anonforumtracked);

    switch ($mode) {
        case ANONFORUM_MODE_FLATOLDEST :
        case ANONFORUM_MODE_FLATNEWEST :
        default:
            anonforum_print_posts_flat($course, $cm, $anonforum, $discussion, $post, $mode, $reply, $anonforumtracked, $posts);
            break;

        case ANONFORUM_MODE_THREADED :
            anonforum_print_posts_threaded($course, $cm, $anonforum, $discussion, $post, 0, $reply, $anonforumtracked, $posts);
            break;

        case ANONFORUM_MODE_NESTED :
            anonforum_print_posts_nested($course, $cm, $anonforum, $discussion, $post, $reply, $anonforumtracked, $posts);
            break;
    }
}


/**
 * @global object
 * @global object
 * @uses ANONFORUM_MODE_FLATNEWEST
 * @param object $course
 * @param object $cm
 * @param object $anonforum
 * @param object $discussion
 * @param object $post
 * @param object $mode
 * @param bool $reply
 * @param bool $anonforumtracked
 * @param array $posts
 * @return void
 */
function anonforum_print_posts_flat($course, &$cm, $anonforum, $discussion, $post, $mode, $reply, $anonforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if ($mode == ANONFORUM_MODE_FLATNEWEST) {
        $sort = "ORDER BY created DESC";
    } else {
        $sort = "ORDER BY created ASC";
    }

    foreach ($posts as $post) {
        if (!$post->parent) {
            continue;
        }
        $post->subject = format_string($post->subject);
        $ownpost = ($USER->id == $post->userid);

        $postread = !empty($post->postread);

        anonforum_print_post($post, $discussion, $anonforum, $cm, $course, $ownpost, $reply, $link,
                             '', '', $postread, true, $anonforumtracked);
    }
}

/**
 * @todo Document this function
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return void
 */
function anonforum_print_posts_threaded($course, &$cm, $anonforum, $discussion, $parent, $depth, $reply, $anonforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        $modcontext       = context_module::instance($cm->id);
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $modcontext);

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if ($depth > 0) {
                $ownpost = ($USER->id == $post->userid);
                $post->subject = format_string($post->subject);

                $postread = !empty($post->postread);

                anonforum_print_post($post, $discussion, $anonforum, $cm, $course, $ownpost, $reply, $link,
                                     '', '', $postread, true, $anonforumtracked);
            } else {
                if (!anonforum_user_can_see_post($anonforum, $discussion, $post, NULL, $cm)) {
                    echo "</div>\n";
                    continue;
                }
                $by = new stdClass();
                if (empty($post->anonymouspost)) {
                    $by->name = fullname($post, $canviewfullnames);
                } else {
                    $by->name = get_string('anonymoususer', 'anonforum');
                }
                $by->date = userdate($post->modified);

                if ($anonforumtracked) {
                    if (!empty($post->postread)) {
                        $style = '<span class="anonforumthread read">';
                    } else {
                        $style = '<span class="anonforumthread unread">';
                    }
                } else {
                    $style = '<span class="anonforumthread">';
                }
                echo $style."<a name=\"$post->id\"></a>".
                     "<a href=\"discuss.php?d=$post->discussion&amp;parent=$post->id\">".format_string($post->subject,true)."</a> ";
                print_string("bynameondate", "anonforum", $by);
                echo "</span>";
            }

            anonforum_print_posts_threaded($course, $cm, $anonforum, $discussion, $post, $depth-1, $reply, $anonforumtracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * @todo Document this function
 * @global object
 * @global object
 * @return void
 */
function anonforum_print_posts_nested($course, &$cm, $anonforum, $discussion, $parent, $reply, $anonforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if (!isloggedin()) {
                $ownpost = false;
            } else {
                $ownpost = ($USER->id == $post->userid);
            }

            $post->subject = format_string($post->subject);
            $postread = !empty($post->postread);

            anonforum_print_post($post, $discussion, $anonforum, $cm, $course, $ownpost, $reply, $link,
                                 '', '', $postread, true, $anonforumtracked);
            anonforum_print_posts_nested($course, $cm, $anonforum, $discussion, $post, $reply, $anonforumtracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * Returns all anonforum posts since a given time in specified anonforum.
 *
 * @todo Document this functions args
 * @global object
 * @global object
 * @global object
 * @global object
 */
function anonforum_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $params = array($timestart, $cm->instance);

    if ($userid) {
        $userselect = "AND u.id = ?";
        $params[] = $userid;
    } else {
        $userselect = "";
    }

    if ($groupid) {
        $groupselect = "AND d.groupid = ?";
        $params[] = $groupid;
    } else {
        $groupselect = "";
    }

    $allnames = get_all_user_name_fields(true, 'u');
    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS anonforumtype, d.anonforum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              $allnames, u.email, u.picture, u.imagealt, u.email
                                         FROM {anonforum_posts} p
                                              JOIN {anonforum_discussions} d ON d.id = p.discussion
                                              JOIN {anonforum} f             ON f.id = d.anonforum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.id = ?
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)) { // order by initial posting date
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = context_module::instance($cm->id);
    $viewhiddentimed = has_capability('mod/anonforum:viewhiddentimedposts', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);

    $printposts = array();
    foreach ($posts as $post) {

        if (!empty($CFG->anonforum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!$viewhiddentimed) {
                continue;
            }
        }

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or $accessallgroups) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (!in_array($post->groupid, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }

    if (!$printposts) {
        return;
    }

    $aname = format_string($cm->name,true);

    foreach ($printposts as $post) {
        $tmpactivity = new stdClass();

        $tmpactivity->type         = 'anonforum';
        $tmpactivity->cmid         = $cm->id;
        $tmpactivity->name         = $aname;
        $tmpactivity->sectionnum   = $cm->sectionnum;
        $tmpactivity->timestamp    = $post->modified;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->id         = $post->id;
        $tmpactivity->content->discussion = $post->discussion;
        $tmpactivity->content->subject    = format_string($post->subject);
        $tmpactivity->content->parent     = $post->parent;

        $tmpactivity->user = new stdClass();
        if (empty($post->anonymouspost)) {
            $additionalfields = array('id' => 'userid', 'picture', 'imagealt', 'email');
            $additionalfields = explode(',', user_picture::fields());
            $tmpactivity->user = username_load_fields_from_object($tmpactivity->user, $post, null, $additionalfields);
            $tmpactivity->user->id = $post->userid;
        } else {
            $tmpactivity->user->id        = -1;
            $tmpactivity->user->firstname = 'Anonymous';
            $tmpactivity->user->lastname  = '';
            $tmpactivity->user->picture   = '';
            $tmpactivity->user->imagealt  = '';
            $tmpactivity->user->email     = '';
        }

    }

    return;
}

/**
 * @todo Document this function
 * @global object
 */
function anonforum_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
    global $CFG, $OUTPUT;

    if ($activity->content->parent) {
        $class = 'reply';
    } else {
        $class = 'discussion';
    }

    echo '<table border="0" cellpadding="3" cellspacing="0" class="anonforum-recent">';

    echo "<tr><td class=\"userpicture\" valign=\"top\">";
    echo $OUTPUT->user_picture($activity->user, array('courseid'=>$courseid));
    echo "</td><td class=\"$class\">";

    echo '<div class="title">';
    if ($detail) {
        $aname = s($activity->name);
        echo "<img src=\"" . $OUTPUT->pix_url('icon', $activity->type) . "\" ".
             "class=\"icon\" alt=\"{$aname}\" />";
    }
    echo "<a href=\"$CFG->wwwroot/mod/anonforum/discuss.php?d={$activity->content->discussion}"
         ."#p{$activity->content->id}\">{$activity->content->subject}</a>";
    echo '</div>';

    echo '<div class="user">';
    $fullname = fullname($activity->user, $viewfullnames);
    echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->id}&amp;course=$courseid\">"
         ."{$fullname}</a> - ".userdate($activity->timestamp);
    echo '</div>';
      echo "</td></tr></table>";

    return;
}

/**
 * recursively sets the discussion field to $discussionid on $postid and all its children
 * used when pruning a post
 *
 * @global object
 * @param int $postid
 * @param int $discussionid
 * @return bool
 */
function anonforum_change_discussionid($postid, $discussionid) {
    global $DB;
    $DB->set_field('anonforum_posts', 'discussion', $discussionid, array('id' => $postid));
    if ($posts = $DB->get_records('anonforum_posts', array('parent' => $postid))) {
        foreach ($posts as $post) {
            anonforum_change_discussionid($post->id, $discussionid);
        }
    }
    return true;
}

/**
 * Prints the editing button on subscribers page
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param int $anonforumid
 * @return string
 */
function anonforum_update_subscriptions_button($courseid, $anonforumid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('turneditingoff');
        $edit = "off";
    } else {
        $string = get_string('turneditingon');
        $edit = "on";
    }

    return "<form method=\"get\" action=\"$CFG->wwwroot/mod/anonforum/subscribers.php\">".
           "<input type=\"hidden\" name=\"id\" value=\"$anonforumid\" />".
           "<input type=\"hidden\" name=\"edit\" value=\"$edit\" />".
           "<input type=\"submit\" value=\"$string\" /></form>";
}

/**
 * This function gets run whenever user is enrolled into course
 *
 * @deprecated deprecating this function as we will be using \mod_anonforum\observer::role_assigned()
 * @param stdClass $cp
 * @return void
 */
function anonforum_user_enrolled($cp) {
    global $DB;

    // NOTE: this has to be as fast as possible - we do not want to slow down enrolments!
    //       Originally there used to be 'mod/anonymous forum:initialsubscriptions' which was
    //       introduced because we did not have enrolment information in earlier versions...

    $sql = "SELECT f.id
              FROM {anonforum} f
         LEFT JOIN {anonforum_subscriptions} fs ON (fs.anonforum = f.id AND fs.userid = :userid)
             WHERE f.course = :courseid AND f.forcesubscribe = :initial AND fs.id IS NULL";
    $params = array('courseid'=>$cp->courseid, 'userid'=>$cp->userid, 'initial'=>ANONFORUM_INITIALSUBSCRIBE);

    $anonforums = $DB->get_records_sql($sql, $params);
    foreach ($anonforums as $anonforum) {
        anonforum_subscribe($cp->userid, $anonforum->id);
    }
}

// Functions to do with read tracking.

/**
 * Mark posts as read.
 *
 * @global object
 * @global object
 * @param object $user object
 * @param array $postids array of post ids
 * @return boolean success
 */
function anonforum_tp_mark_posts_read($user, $postids) {
    global $CFG, $DB;

    if (!anonforum_tp_can_track_anonforums(false, $user)) {
        return true;
    }

    $status = true;

    $now = time();
    $cutoffdate = $now - ($CFG->anonforum_oldpostdays * 24 * 3600);

    if (empty($postids)) {
        return true;

    } else if (count($postids) > 200) {
        while ($part = array_splice($postids, 0, 200)) {
            $status = anonforum_tp_mark_posts_read($user, $part) && $status;
        }
        return $status;
    }

    list($usql, $params) = $DB->get_in_or_equal($postids);
    $params[] = $user->id;

    $sql = "SELECT id
              FROM {anonforum_read}
             WHERE postid $usql AND userid = ?";
    if ($existing = $DB->get_records_sql($sql, $params)) {
        $existing = array_keys($existing);
    } else {
        $existing = array();
    }

    $new = array_diff($postids, $existing);

    if ($new) {
        list($usql, $new_params) = $DB->get_in_or_equal($new);
        $params = array($user->id, $now, $now, $user->id);
        $params = array_merge($params, $new_params);
        $params[] = $cutoffdate;

        if ($CFG->anonforum_allowforcedreadtracking) {
            $trackingsql = "AND (f.trackingtype = ".ANONFORUM_TRACKING_FORCED."
                            OR (f.trackingtype = ".ANONFORUM_TRACKING_OPTIONAL." AND tf.id IS NULL))";
        } else {
            $trackingsql = "AND ((f.trackingtype = ".ANONFORUM_TRACKING_OPTIONAL."  OR f.trackingtype = ".ANONFORUM_TRACKING_FORCED.")
                                AND tf.id IS NULL)";
        }

        $sql = "INSERT INTO {anonforum_read} (userid, postid, discussionid, anonforumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.anonforum, ?, ?
                  FROM {anonforum_posts} p
                       JOIN {anonforum_discussions} d       ON d.id = p.discussion
                       JOIN {anonforum} f                   ON f.id = d.anonforum
                       LEFT JOIN {anonforum_track_prefs} tf ON (tf.userid = ? AND tf.anonforumid = f.id)
                 WHERE p.id $usql
                       AND p.modified >= ?
                       $trackingsql";
        $status = $DB->execute($sql, $params) && $status;
    }

    if ($existing) {
        list($usql, $new_params) = $DB->get_in_or_equal($existing);
        $params = array($now, $user->id);
        $params = array_merge($params, $new_params);

        $sql = "UPDATE {anonforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid $usql";
        $status = $DB->execute($sql, $params) && $status;
    }

    return $status;
}

/**
 * Mark post as read.
 * @global object
 * @global object
 * @param int $userid
 * @param int $postid
 */
function anonforum_tp_add_read_record($userid, $postid) {
    global $CFG, $DB;

    $now = time();
    $cutoffdate = $now - ($CFG->anonforum_oldpostdays * 24 * 3600);

    if (!$DB->record_exists('anonforum_read', array('userid' => $userid, 'postid' => $postid))) {
        $sql = "INSERT INTO {anonforum_read} (userid, postid, discussionid, anonforumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.anonforum, ?, ?
                  FROM {anonforum_posts} p
                       JOIN {anonforum_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
        return $DB->execute($sql, array($userid, $now, $now, $postid, $cutoffdate));

    } else {
        $sql = "UPDATE {anonforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
        return $DB->execute($sql, array($now, $userid, $userid));
    }
}

/**
 * Returns all records in the 'anonforum_read' table matching the passed keys, indexed
 * by userid.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $anonforumid
 * @return array
 */
function anonforum_tp_get_read_records($userid=-1, $postid=-1, $discussionid=-1, $anonforumid=-1) {
    global $DB;
    $select = '';
    $params = array();

    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($anonforumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'anonforumid = ?';
        $params[] = $anonforumid;
    }

    return $DB->get_records_select('anonforum_read', $select, $params);
}

/**
 * Returns all read records for the provided user and discussion, indexed by postid.
 *
 * @global object
 * @param inti $userid
 * @param int $discussionid
 */
function anonforum_tp_get_discussion_read_records($userid, $discussionid) {
    global $DB;
    $select = 'userid = ? AND discussionid = ?';
    $fields = 'postid, firstread, lastread';
    return $DB->get_records_select('anonforum_read', $select, array($userid, $discussionid), '', $fields);
}

/**
 * If its an old post, do nothing. If the record exists, the maintenance will clear it up later.
 *
 * @return bool
 */
function anonforum_tp_mark_post_read($userid, $post, $anonforumid) {
    if (!anonforum_tp_is_post_old($post)) {
        return anonforum_tp_add_read_record($userid, $post->id);
    } else {
        return true;
    }
}

/**
 * Marks a whole anonforum as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $anonforumid
 * @param int|bool $groupid
 * @return bool
 */
function anonforum_tp_mark_anonforum_read($user, $anonforumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->anonforum_oldpostdays*24*60*60);

    $groupsel = "";
    $params = array($user->id, $anonforumid, $cutoffdate);

    if ($groupid !== false) {
        $groupsel = " AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT p.id
              FROM {anonforum_posts} p
                   LEFT JOIN {anonforum_discussions} d ON d.id = p.discussion
                   LEFT JOIN {anonforum_read} r        ON (r.postid = p.id AND r.userid = ?)
             WHERE d.anonforum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $groupsel";

    if ($posts = $DB->get_records_sql($sql, $params)) {
        $postids = array_keys($posts);
        return anonforum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * Marks a whole discussion as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $discussionid
 * @return bool
 */
function anonforum_tp_mark_discussion_read($user, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->anonforum_oldpostdays*24*60*60);

    $sql = "SELECT p.id
              FROM {anonforum_posts} p
                   LEFT JOIN {anonforum_read} r ON (r.postid = p.id AND r.userid = ?)
             WHERE p.discussion = ?
                   AND p.modified >= ? AND r.id is NULL";

    if ($posts = $DB->get_records_sql($sql, array($user->id, $discussionid, $cutoffdate))) {
        $postids = array_keys($posts);
        return anonforum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * @global object
 * @param int $userid
 * @param object $post
 */
function anonforum_tp_is_post_read($userid, $post) {
    global $DB;
    return (anonforum_tp_is_post_old($post) ||
            $DB->record_exists('anonforum_read', array('userid' => $userid, 'postid' => $post->id)));
}

/**
 * @global object
 * @param object $post
 * @param int $time Defautls to time()
 */
function anonforum_tp_is_post_old($post, $time=null) {
    global $CFG;

    if (is_null($time)) {
        $time = time();
    }
    return ($post->modified < ($time - ($CFG->anonforum_oldpostdays * 24 * 3600)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return bool
 */
function anonforum_tp_count_discussion_read_records($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->anonforum_oldpostdays) ? (time() - ($CFG->anonforum_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(DISTINCT p.id) '.
           'FROM {anonforum_discussions} d '.
           'LEFT JOIN {anonforum_read} r ON d.id = r.discussionid AND r.userid = ? '.
           'LEFT JOIN {anonforum_posts} p ON p.discussion = d.id '.
                'AND (p.modified < ? OR p.id = r.postid) '.
           'WHERE d.id = ? ';

    return ($DB->count_records_sql($sql, array($userid, $cutoffdate, $discussionid)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return int
 */
function anonforum_tp_count_discussion_unread_posts($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->anonforum_oldpostdays) ? (time() - ($CFG->anonforum_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(p.id) '.
           'FROM {anonforum_posts} p '.
           'LEFT JOIN {anonforum_read} r ON r.postid = p.id AND r.userid = ? '.
           'WHERE p.discussion = ? '.
                'AND p.modified >= ? AND r.id is NULL';

    return $DB->count_records_sql($sql, array($userid, $discussionid, $cutoffdate));
}

/**
 * Returns the count of posts for the provided anonforum and [optionally] group.
 * @global object
 * @global object
 * @param int $anonforumid
 * @param int|bool $groupid
 * @return int
 */
function anonforum_tp_count_anonforum_posts($anonforumid, $groupid=false) {
    global $CFG, $DB;
    $params = array($anonforumid);
    $sql = 'SELECT COUNT(*) '.
           'FROM {anonforum_posts} fp,{anonforum_discussions} fd '.
           'WHERE fd.anonforum = ? AND fp.discussion = fd.id';
    if ($groupid !== false) {
        $sql .= ' AND (fd.groupid = ? OR fd.groupid = -1)';
        $params[] = $groupid;
    }
    $count = $DB->count_records_sql($sql, $params);


    return $count;
}

/**
 * Returns the count of records for the provided user and anonforum and [optionally] group.
 * @global object
 * @global object
 * @param int $userid
 * @param int $anonforumid
 * @param int|bool $groupid
 * @return int
 */
function anonforum_tp_count_anonforum_read_records($userid, $anonforumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->anonforum_oldpostdays*24*60*60);

    $groupsel = '';
    $params = array($userid, $anonforumid, $cutoffdate);
    if ($groupid !== false) {
        $groupsel = "AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT COUNT(p.id)
              FROM  {anonforum_posts} p
                    JOIN {anonforum_discussions} d ON d.id = p.discussion
                    LEFT JOIN {anonforum_read} r   ON (r.postid = p.id AND r.userid= ?)
              WHERE d.anonforum = ?
                    AND (p.modified < $cutoffdate OR (p.modified >= ? AND r.id IS NOT NULL))
                    $groupsel";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Returns the count of records for the provided user and course.
 * Please note that group access is ignored!
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function anonforum_tp_get_course_unread_posts($userid, $courseid) {
    global $CFG, $DB;

    $now = round(time(), -2); // DB cache friendliness.
    $cutoffdate = $now - ($CFG->anonforum_oldpostdays * 24 * 60 * 60);
    $params = array($userid, $userid, $courseid, $cutoffdate, $userid);

    if (!empty($CFG->anonforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    if ($CFG->anonforum_allowforcedreadtracking) {
        $trackingsql = "AND (f.trackingtype = ".ANONFORUM_TRACKING_FORCED."
                            OR (f.trackingtype = ".ANONFORUM_TRACKING_OPTIONAL." AND tf.id IS NULL
                                AND (SELECT trackforums FROM {user} WHERE id = ?) = 1))";
    } else {
        $trackingsql = "AND ((f.trackingtype = ".ANONFORUM_TRACKING_OPTIONAL." OR f.trackingtype = ".ANONFORUM_TRACKING_FORCED.")
                            AND tf.id IS NULL
                            AND (SELECT trackforums FROM {user} WHERE id = ?) = 1)";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread
              FROM {anonforum_posts} p
                   JOIN {anonforum_discussions} d       ON d.id = p.discussion
                   JOIN {anonforum} f                   ON f.id = d.anonforum
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {anonforum_read} r         ON (r.postid = p.id AND r.userid = ?)
                   LEFT JOIN {anonforum_track_prefs} tf ON (tf.userid = ? AND tf.anonforumid = f.id)
             WHERE f.course = ?
                   AND p.modified >= ? AND r.id is NULL
                   $trackingsql
                   $timedsql
          GROUP BY f.id";

    if ($return = $DB->get_records_sql($sql, $params)) {
        return $return;
    }

    return array();
}

/**
 * Returns the count of records for the provided user and anonforum and [optionally] group.
 *
 * @global object
 * @global object
 * @global object
 * @param object $cm
 * @param object $course
 * @return int
 */
function anonforum_tp_count_anonforum_unread_posts($cm, $course) {
    global $CFG, $USER, $DB;

    static $readcache = array();

    $anonforumid = $cm->instance;

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = array();
        if ($counts = anonforum_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$anonforumid])) {
        // no need to check group mode ;-)
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $readcache[$course->id][$anonforumid];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $readcache[$course->id][$anonforumid];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo = get_fast_modinfo($course);

    $mygroups = $modinfo->get_groups($cm->groupingid);

    // add all groups posts
    $mygroups[-1] = -1;

    list ($groups_sql, $groups_params) = $DB->get_in_or_equal($mygroups);

    $now = round(time(), -2); // db cache friendliness
    $cutoffdate = $now - ($CFG->anonforum_oldpostdays*24*60*60);
    $params = array($USER->id, $anonforumid, $cutoffdate);

    if (!empty($CFG->anonforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $params = array_merge($params, $groups_params);

    $sql = "SELECT COUNT(p.id)
              FROM {anonforum_posts} p
                   JOIN {anonforum_discussions} d ON p.discussion = d.id
                   LEFT JOIN {anonforum_read} r   ON (r.postid = p.id AND r.userid = ?)
             WHERE d.anonforum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $timedsql
                   AND d.groupid $groups_sql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Deletes read records for the specified index. At least one parameter must be specified.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $anonforumid
 * @return bool
 */
function anonforum_tp_delete_read_records($userid=-1, $postid=-1, $discussionid=-1, $anonforumid=-1) {
    global $DB;
    $params = array();

    $select = '';
    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($anonforumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'anonforumid = ?';
        $params[] = $anonforumid;
    }
    if ($select == '') {
        return false;
    }
    else {
        return $DB->delete_records_select('anonforum_read', $select, $params);
    }
}
/**
 * Get a list of anonforums not tracked by the user.
 *
 * @global object
 * @global object
 * @param int $userid The id of the user to use.
 * @param int $courseid The id of the course being checked.
 * @return mixed An array indexed by anonforum id, or false.
 */
function anonforum_tp_get_untracked_anonforums($userid, $courseid) {
    global $CFG, $DB;

    if ($CFG->anonforum_allowforcedreadtracking) {
        $trackingsql = "AND (f.trackingtype = ".ANONFORUM_TRACKING_OFF."
                            OR (f.trackingtype = ".ANONFORUM_TRACKING_OPTIONAL." AND (ft.id IS NOT NULL
                                OR (SELECT trackforums FROM {user} WHERE id = ?) = 0)))";
    } else {
        $trackingsql = "AND (f.trackingtype = ".ANONFORUM_TRACKING_OFF."
                            OR ((f.trackingtype = ".ANONFORUM_TRACKING_OPTIONAL." OR f.trackingtype = ".ANONFORUM_TRACKING_FORCED.")
                                AND (ft.id IS NOT NULL
                                    OR (SELECT trackforums FROM {user} WHERE id = ?) = 0)))";
    }

    $sql = "SELECT f.id
              FROM {anonforum} f
                   LEFT JOIN {anonforum_track_prefs} ft ON (ft.anonforumid = f.id AND ft.userid = ?)
             WHERE f.course = ?
                   $trackingsql";

    if ($anonforums = $DB->get_records_sql($sql, array($userid, $courseid, $userid))) {
        foreach ($anonforums as $anonforum) {
            $anonforums[$anonforum->id] = $anonforum;
        }
        return $anonforums;

    } else {
        return array();
    }
}

/**
 * Determine if a user can track anonforums and optionally a particular anonforum.
 * Checks the site settings, the user settings and the anonforum settings (if
 * requested).
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $anonforum The anonforum object to test, or the int id (optional).
 * @param mixed $userid The user object to check for (optional).
 * @return boolean
 */
function anonforum_tp_can_track_anonforums($anonforum=false, $user=false) {
    global $USER, $CFG, $DB;

    // if possible, avoid expensive
    // queries
    if (empty($CFG->anonforum_trackreadposts)) {
        return false;
    }

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if ($anonforum === false) {
        if ($CFG->anonforum_allowforcedreadtracking) {
            // Since we can force tracking, assume yes without a specific anonymous forum.
            return true;
        } else {
            return (bool)$user->trackforums;
        }
    }

    // Work toward always passing an object...
    if (is_numeric($anonforum)) {
        debugging('Better use proper anonforum object.', DEBUG_DEVELOPER);
        $anonforum = $DB->get_record('anonforum', array('id' => $anonforum), '', 'id,trackingtype');
    }

    $anonforumallows = ($anonforum->trackingtype == ANONFORUM_TRACKING_OPTIONAL);
    $anonforumforced = ($anonforum->trackingtype == ANONFORUM_TRACKING_FORCED);

    if ($CFG->anonforum_allowforcedreadtracking) {
        // If we allow forcing, then forced anonymous forums takes procidence over user setting.
        return ($anonforumforced || ($anonforumallows  && (!empty($user->trackforums) && (bool)$user->trackforums)));
    } else {
        // If we don't allow forcing, user setting trumps.
        return ($anonforumforced || $anonforumallows)  && !empty($user->trackforums);
    }
}

/**
 * Tells whether a specific anonforum is tracked by the user. A user can optionally
 * be specified. If not specified, the current user is assumed.
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $anonforum If int, the id of the anonforum being checked; if object, the anonforum object
 * @param int $userid The id of the user being checked (optional).
 * @return boolean
 */
function anonforum_tp_is_tracked($anonforum, $user=false) {
    global $USER, $CFG, $DB;

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    // Work toward always passing an object...
    if (is_numeric($anonforum)) {
        debugging('Better use proper anonforum object.', DEBUG_DEVELOPER);
        $anonforum = $DB->get_record('anonforum', array('id' => $anonforum));
    }

    if (!anonforum_tp_can_track_anonforums($anonforum, $user)) {
        return false;
    }

    $anonforumallows = ($anonforum->trackingtype == ANONFORUM_TRACKING_OPTIONAL);
    $anonforumforced = ($anonforum->trackingtype == ANONFORUM_TRACKING_FORCED);
    $userpref = $DB->get_record('anonforum_track_prefs', array('userid' => $user->id, 'anonforumid' => $anonforum->id));

    if ($CFG->anonforum_allowforcedreadtracking) {
        return $anonforumforced || ($anonforumallows && $userpref === false);
    } else {
        return  ($anonforumallows || $anonforumforced) && $userpref === false;
    }
}

/**
 * @global object
 * @global object
 * @param int $anonforumid
 * @param int $userid
 */
function anonforum_tp_start_tracking($anonforumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    return $DB->delete_records('anonforum_track_prefs', array('userid' => $userid, 'anonforumid' => $anonforumid));
}

/**
 * @global object
 * @global object
 * @param int $anonforumid
 * @param int $userid
 */
function anonforum_tp_stop_tracking($anonforumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    if (!$DB->record_exists('anonforum_track_prefs', array('userid' => $userid, 'anonforumid' => $anonforumid))) {
        $track_prefs = new stdClass();
        $track_prefs->userid = $userid;
        $track_prefs->anonforumid = $anonforumid;
        $DB->insert_record('anonforum_track_prefs', $track_prefs);
    }

    return anonforum_tp_delete_read_records($userid, -1, -1, $anonforumid);
}


/**
 * Clean old records from the anonforum_read table.
 * @global object
 * @global object
 * @return void
 */
function anonforum_tp_clean_read_records() {
    global $CFG, $DB;

    if (!isset($CFG->anonforum_oldpostdays)) {
        return;
    }
// Look for records older than the cutoffdate that are still in the anonymous forum_read table.
    $cutoffdate = time() - ($CFG->anonforum_oldpostdays*24*60*60);

    //first get the oldest tracking present - we need tis to speedup the next delete query
    $sql = "SELECT MIN(fp.modified) AS first
              FROM {anonforum_posts} fp
                   JOIN {anonforum_read} fr ON fr.postid=fp.id";
    if (!$first = $DB->get_field_sql($sql)) {
        // nothing to delete;
        return;
    }

    // now delete old tracking info
    $sql = "DELETE
              FROM {anonforum_read}
             WHERE postid IN (SELECT fp.id
                                FROM {anonforum_posts} fp
                               WHERE fp.modified >= ? AND fp.modified < ?)";
    $DB->execute($sql, array($first, $cutoffdate));
}

/**
 * Sets the last post for a given discussion
 *
 * @global object
 * @global object
 * @param into $discussionid
 * @return bool|int
 **/
function anonforum_discussion_update_last_post($discussionid) {
    global $CFG, $DB;

// Check the given discussion exists
    if (!$DB->record_exists('anonforum_discussions', array('id' => $discussionid))) {
        return false;
    }

// Use SQL to find the last post for this discussion
    $sql = "SELECT id, userid, modified
              FROM {anonforum_posts}
             WHERE discussion=?
             ORDER BY modified DESC";

// Lets go find the last post
    if (($lastposts = $DB->get_records_sql($sql, array($discussionid), 0, 1))) {
        $lastpost = reset($lastposts);
        $discussionobject = new stdClass();
        $discussionobject->id           = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;
        $DB->update_record('anonforum_discussions', $discussionobject);
        return $lastpost->id;
    }

// To get here either we couldn't find a post for the discussion (weird)
// or we couldn't update the discussion record (weird x2)
    return false;
}


/**
 * @return array
 */
function anonforum_get_view_actions() {
    return array('view discussion', 'search', 'anonforum', 'anonforums', 'subscribers', 'view anonforum');
}

/**
 * @return array
 */
function anonforum_get_post_actions() {
    return array('add discussion','add post','delete discussion','delete post','move discussion','prune post','update post');
}

/**
 * Returns a warning object if a user has reached the number of posts equal to
 * the warning/blocking setting, or false if there is no warning to show.
 *
 * @param int|stdClass $anonforum the anonforum id or the anonforum object
 * @param stdClass $cm the course module
 * @return stdClass|bool returns an object with the warning information, else
 *         returns false if no warning is required.
 */
function anonforum_check_throttling($anonforum, $cm = null) {
    global $CFG, $DB, $USER;

    if (is_numeric($anonforum)) {
        $anonforum = $DB->get_record('anonforum', array('id' => $anonforum), '*', MUST_EXIST);
    }

    if (!is_object($anonforum)) {
        return false; // This is broken.
    }

    if (!$cm) {
        $cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course, false, MUST_EXIST);
    }

    if (empty($anonforum->blockafter)) {
        return false;
    }

    if (empty($anonforum->blockperiod)) {
        return false;
    }

    $modcontext = context_module::instance($cm->id);
    if (has_capability('mod/anonforum:postwithoutthrottling', $modcontext)) {
        return false;
    }

    // Get the number of posts in the last period we care about.
    $timenow = time();
    $timeafter = $timenow - $anonforum->blockperiod;
    $numposts = $DB->count_records_sql('SELECT COUNT(p.id) FROM {anonforum_posts} p
                                        JOIN {anonforum_discussions} d
                                        ON p.discussion = d.id WHERE d.anonforum = ?
                                        AND p.userid = ? AND p.created > ?', array($anonforum->id, $USER->id, $timeafter));

    $a = new stdClass();
    $a->blockafter = $anonforum->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime'.$anonforum->blockperiod);

    if ($anonforum->blockafter <= $numposts) {
        $warning = new stdClass();
        $warning->canpost = false;
        $warning->errorcode = 'anonforumblockingtoomanyposts';
        $warning->module = 'error';
        $warning->additional = $a;
        $warning->link = $CFG->wwwroot . '/mod/anonforum/view.php?f=' . $anonforum->id;

        return $warning;
    }

    if ($anonforum->warnafter <= $numposts) {
        $warning = new stdClass();
        $warning->canpost = true;
        $warning->errorcode = 'anonforumblockingalmosttoomanyposts';
        $warning->module = 'anonforum';
        $warning->additional = $a;
        $warning->link = null;

        return $warning;
    }
}

/**
 * Throws an error if the user is no longer allowed to post due to having reached
 * or exceeded the number of posts specified in 'Post threshold for blocking'
 * setting.
 *
 * @since Moodle 2.5
 * @param stdClass $thresholdwarning the warning information returned
 *        from the function anonforum_check_throttling.
 */
function anonforum_check_blocking_threshold($thresholdwarning) {
    if (!empty($thresholdwarning) && !$thresholdwarning->canpost) {
        print_error($thresholdwarning->errorcode,
                    $thresholdwarning->module,
                    $thresholdwarning->link,
                    $thresholdwarning->additional);
    }
}


/**
 * Removes all grades from gradebook
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type optional
 */
function anonforum_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $wheresql = '';
    $params = array($courseid);
    if ($type) {
        $wheresql = "AND f.type=?";
        $params[] = $type;
    }

    $sql = "SELECT f.*, cm.idnumber as cmidnumber, f.course as courseid
              FROM {anonforum} f, {course_modules} cm, {modules} m
             WHERE m.name='anonforum' AND m.id=cm.module AND cm.instance=f.id AND f.course=? $wheresql";

    if ($anonforums = $DB->get_records_sql($sql, $params)) {
        foreach ($anonforums as $anonforum) {
            anonforum_grade_item_update($anonforum, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified anonforum
 * and clean up any related data.
 *
 * @global object
 * @global object
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function anonforum_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot.'/rating/lib.php');

    $componentstr = get_string('modulenameplural', 'anonforum');
    $status = array();

    $params = array($data->courseid);

    $removeposts = false;
    $typesql     = "";
    if (!empty($data->reset_anonforum_all)) {
        $removeposts = true;
        $typesstr    = get_string('resetanonforumsall', 'anonforum');
        $types       = array();
    } else if (!empty($data->reset_anonforum_types)){
        $removeposts = true;
        $typesql     = "";
        $types       = array();
        $anonforum_types_all = anonforum_get_anonforum_types_all();
        foreach ($data->reset_anonforum_types as $type) {
            if (!array_key_exists($type, $anonforum_types_all)) {
                continue;
            }
            $typesql .= " AND f.type=?";
            $types[] = $anonforum_types_all[$type];
            $params[] = $type;
        }
        $typesstr = get_string('resetanonforums', 'anonforum').': '.implode(', ', $types);
    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {anonforum_discussions} fd, {anonforum} f
                           WHERE f.course=? AND f.id=fd.anonforum";

    $allanonforumssql      = "SELECT f.id
                            FROM {anonforum} f
                           WHERE f.course=?";

    $allpostssql       = "SELECT fp.id
                            FROM {anonforum_posts} fp, {anonforum_discussions} fd, {anonforum} f
                           WHERE f.course=? AND f.id=fd.anonforum AND fd.id=fp.discussion";

    $anonforumssql = $anonforums = $rm = null;

    if( $removeposts || !empty($data->reset_anonforum_ratings) ) {
        $anonforumssql      = "$allanonforumssql $typesql";
        $anonforums = $anonforums = $DB->get_records_sql($anonforumssql, $params);
        $rm = new rating_manager();
        $ratingdeloptions = new stdClass;
        $ratingdeloptions->component = 'mod_anonforum';
        $ratingdeloptions->ratingarea = 'post';
    }

    if ($removeposts) {
        $discussionssql = "$alldiscussionssql $typesql";
        $postssql       = "$allpostssql $typesql";

        // now get rid of all attachments
        $fs = get_file_storage();
        if ($anonforums) {
            foreach ($anonforums as $anonforumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('anonforum', $anonforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);
                $fs->delete_area_files($context->id, 'mod_anonforum', 'attachment');
                $fs->delete_area_files($context->id, 'mod_anonforum', 'post');

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // first delete all read flags
        $DB->delete_records_select('anonforum_read', "anonforumid IN ($anonforumssql)", $params);

        // remove tracking prefs
        $DB->delete_records_select('anonforum_track_prefs', "anonforumid IN ($anonforumssql)", $params);

        // remove posts from queue
        $DB->delete_records_select('anonforum_queue', "discussionid IN ($discussionssql)", $params);

        // all posts - initial posts must be kept in single simple discussion anonymous forums
        $DB->delete_records_select('anonforum_posts', "discussion IN ($discussionssql) AND parent <> 0", $params); // first all children
        $DB->delete_records_select('anonforum_posts', "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0", $params); // now the initial posts for non single simple

        // finally all discussions except single simple anonymous forums
        $DB->delete_records_select('anonforum_discussions', "anonforum IN ($anonforumssql AND f.type <> 'single')", $params);

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            if (empty($types)) {
                anonforum_reset_gradebook($data->courseid);
            } else {
                foreach ($types as $type) {
                    anonforum_reset_gradebook($data->courseid, $type);
                }
            }
        }

        $status[] = array('component'=>$componentstr, 'item'=>$typesstr, 'error'=>false);
    }

    // remove all ratings in this course's anonymous forums
    if (!empty($data->reset_anonforum_ratings)) {
        if ($anonforums) {
            foreach ($anonforums as $anonforumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('anonforum', $anonforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            anonforum_reset_gradebook($data->courseid);
        }
    }

    // remove all digest settings unconditionally - even for users still enrolled in course.
    if (!empty($data->reset_anonforum_digests)) {
        $DB->delete_records_select('anonforum_digests', "anonforum IN ($allanonforumssql)", $params);
        $status[] = array('component' => $componentstr, 'item' => get_string('resetdigests', 'anonforum'), 'error' => false);
    }

    // remove all subscriptions unconditionally - even for users still enrolled in course
    if (!empty($data->reset_anonforum_subscriptions)) {
        $DB->delete_records_select('anonforum_subscriptions', "anonforum IN ($allanonforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resetsubscriptions','anonforum'), 'error'=>false);
    }

    // remove all tracking prefs unconditionally - even for users still enrolled in course
    if (!empty($data->reset_anonforum_track_prefs)) {
        $DB->delete_records_select('anonforum_track_prefs', "anonforumid IN ($allanonforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resettrackprefs','anonforum'), 'error'=>false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('anonforum', array('assesstimestart', 'assesstimefinish'), $data->timeshift, $data->courseid);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param $mform form passed by reference
 */
function anonforum_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'anonforumheader', get_string('modulenameplural', 'anonforum'));

    $mform->addElement('checkbox', 'reset_anonforum_all', get_string('resetanonforumsall','anonforum'));

    $mform->addElement('select', 'reset_anonforum_types', get_string('resetanonforums', 'anonforum'), anonforum_get_anonforum_types_all(), array('multiple' => 'multiple'));
    $mform->setAdvanced('reset_anonforum_types');
    $mform->disabledIf('reset_anonforum_types', 'reset_anonforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_anonforum_digests', get_string('resetdigests','anonforum'));
    $mform->setAdvanced('reset_anonforum_digests');

    $mform->addElement('checkbox', 'reset_anonforum_subscriptions', get_string('resetsubscriptions','anonforum'));
    $mform->setAdvanced('reset_anonforum_subscriptions');

    $mform->addElement('checkbox', 'reset_anonforum_track_prefs', get_string('resettrackprefs','anonforum'));
    $mform->setAdvanced('reset_anonforum_track_prefs');
    $mform->disabledIf('reset_anonforum_track_prefs', 'reset_anonforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_anonforum_ratings', get_string('deleteallratings'));
    $mform->disabledIf('reset_anonforum_ratings', 'reset_anonforum_all', 'checked');
}

/**
 * Course reset form defaults.
 * @return array
 */
function anonforum_reset_course_form_defaults($course) {
    return array('reset_anonforum_all'=>1, 'reset_anonforum_digests' => 0, 'reset_anonforum_subscriptions'=>0, 'reset_anonforum_track_prefs'=>0, 'reset_anonforum_ratings'=>1);
}

/**
 * Converts a anonforum to use the Roles System
 *
 * @global object
 * @global object
 * @param object $anonforum        a anonforum object with the same attributes as a record
 *                        from the anonforum database table
 * @param int $anonforummodid   the id of the anonforum module, from the modules table
 * @param array $teacherroles array of roles that have archetype teacher
 * @param array $studentroles array of roles that have archetype student
 * @param array $guestroles   array of roles that have archetype guest
 * @param int $cmid         the course_module id for this anonforum instance
 * @return boolean      anonforum was converted or not
 */
function anonforum_convert_to_roles($anonforum, $anonforummodid, $teacherroles=array(),
                                $studentroles=array(), $guestroles=array(), $cmid=NULL) {

    global $CFG, $DB, $OUTPUT;

    if (!isset($anonforum->open) && !isset($anonforum->assesspublic)) {
        // We assume that this anonymous forum has already been converted to use the
        // Roles System. Columns anonforum.open and anonymous forum.assesspublic get dropped
        // once the anonymous forum module has been upgraded to use Roles.
        return false;
    }

    if ($anonforum->type == 'teacher') {

        // Teacher anonforums should be converted to normal anonymous forums that
        // use the Roles System to implement the old behavior.
        // Note:
        //   Seems that teacher anonymous forums were never backed up in 1.6 since they
        //   didn't have an entry in the course_modules table.
        require_once($CFG->dirroot.'/course/lib.php');

        if ($DB->count_records('anonforum_discussions', array('anonforum' => $anonforum->id)) == 0) {
            // Delete empty teacher anonymous forums.
            $DB->delete_records('anonforum', array('id' => $anonforum->id));
        } else {
            // Create a course module for the anonymous forum and assign it to
            // section 0 in the course.
            $mod = new stdClass();
            $mod->course = $anonforum->course;
            $mod->module = $anonforummodid;
            $mod->instance = $anonforum->id;
            $mod->section = 0;
            $mod->visible = 0;     // Hide the anonymous forum
            $mod->visibleold = 0;  // Hide the anonymous forum
            $mod->groupmode = 0;

            if (!$cmid = add_course_module($mod)) {
                print_error('cannotcreateinstanceforteacher', 'anonforum');
            } else {
                $sectionid = course_add_cm_to_section($anonforum->course, $mod->coursemodule, 0);
            }

            // Change the anonymous forum type to general.
            $anonforum->type = 'general';
            $DB->update_record('anonforum', $anonforum);

            $context = context_module::instance($cmid);

            // Create overrides for default student and guest roles (prevent).
            foreach ($studentroles as $studentrole) {
                assign_capability('mod/anonforum:viewdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:viewhiddentimedposts', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:viewrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:rate', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:createattachment', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:deleteownpost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:deleteanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:splitdiscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:movediscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:editanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:viewqandawithoutposting', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:viewsubscribers', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:managesubscriptions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/anonforum:postwithoutthrottling', CAP_PREVENT, $studentrole->id, $context->id);
            }
            foreach ($guestroles as $guestrole) {
                assign_capability('mod/anonforum:viewdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:viewhiddentimedposts', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:startdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:replypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:viewrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:viewanyrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:rate', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:createattachment', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:deleteownpost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:deleteanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:splitdiscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:movediscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:editanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:viewqandawithoutposting', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:viewsubscribers', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:managesubscriptions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/anonforum:postwithoutthrottling', CAP_PREVENT, $guestrole->id, $context->id);
            }
        }
    } else {
        // Non-teacher anonymous forum.

        if (empty($cmid)) {
            // We were not given the course_module id. Try to find it.
            if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id)) {
                echo $OUTPUT->notification('Could not get the course module for the anonforum');
                return false;
            } else {
                $cmid = $cm->id;
            }
        }
        $context = context_module::instance($cmid);

        // $anonymous forum->open defines what students can do:
        //   0 = No discussions, no replies
        //   1 = No discussions, but replies are allowed
        //   2 = Discussions and replies are allowed
        switch ($anonforum->open) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/anonforum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/anonforum:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/anonforum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/anonforum:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/anonforum:startdiscussion', CAP_ALLOW, $studentrole->id, $context->id);
                    assign_capability('mod/anonforum:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
        }

        // $anonforum->assessed defines whether anonymous forum rating is turned
        // on (1 or 2) and who can rate posts:
        //   1 = Everyone can rate posts
        //   2 = Only teachers can rate posts
        switch ($anonforum->assessed) {
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/anonforum:rate', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/anonforum:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/anonforum:rate', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/anonforum:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        // $anonymous forum->assesspublic defines whether students can see
        // everybody's ratings:
        //   0 = Students can only see their own ratings
        //   1 = Students can see everyone's ratings
        switch ($anonforum->assesspublic) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/anonforum:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/anonforum:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/anonforum:viewanyrating', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/anonforum:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        if (empty($cm)) {
            $cm = $DB->get_record('course_modules', array('id' => $cmid));
        }

        // $cm->groupmode:
        // 0 - No groups
        // 1 - Separate groups
        // 2 - Visible groups
        switch ($cm->groupmode) {
            case 0:
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }
    }
    return true;
}

/**
 * Returns array of anonforum layout modes
 *
 * @return array
 */
function anonforum_get_layout_modes() {
    return array (ANONFORUM_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'anonforum'),
                  ANONFORUM_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'anonforum'),
                  ANONFORUM_MODE_THREADED   => get_string('modethreaded', 'anonforum'),
                  ANONFORUM_MODE_NESTED     => get_string('modenested', 'anonforum'));
}

/**
 * Returns array of anonforum types chooseable on the anonforum editing form
 *
 * @return array
 */
function anonforum_get_anonforum_types() {
    return array ('general'  => get_string('generalanonforum', 'anonforum'),
                  'eachuser' => get_string('eachuseranonforum', 'anonforum'),
                  'single'   => get_string('singleanonforum', 'anonforum'),
                  'qanda'    => get_string('qandaanonforum', 'anonforum'),
                  'blog'     => get_string('bloganonforum', 'anonforum'));
}

/**
 * Returns array of all anonforum layout modes
 *
 * @return array
 */
function anonforum_get_anonforum_types_all() {
    return array ('news'     => get_string('namenews','anonforum'),
                  'social'   => get_string('namesocial','anonforum'),
                  'general'  => get_string('generalanonforum', 'anonforum'),
                  'eachuser' => get_string('eachuseranonforum', 'anonforum'),
                  'single'   => get_string('singleanonforum', 'anonforum'),
                  'qanda'    => get_string('qandaanonforum', 'anonforum'),
                  'blog'     => get_string('bloganonforum', 'anonforum'));
}

/**
 * Returns array of anonforum open modes
 *
 * @return array
 */
function anonforum_get_open_modes() {
    return array ('2' => get_string('openmode2', 'anonforum'),
                  '1' => get_string('openmode1', 'anonforum'),
                  '0' => get_string('openmode0', 'anonforum') );
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function anonforum_get_extra_capabilities() {
    return array('moodle/site:accessallgroups', 'moodle/site:viewfullnames', 'moodle/site:trustcontent', 'moodle/rating:view', 'moodle/rating:viewany', 'moodle/rating:viewall', 'moodle/rating:rate');
}

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settings The settings navigation object
 * @param navigation_node $anonforumnode The node to add module settings to
 */
function anonforum_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $anonforumnode) {
    global $USER, $PAGE, $CFG, $DB, $OUTPUT;

    $anonforumobject = $DB->get_record("anonforum", array("id" => $PAGE->cm->instance));
    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = context_module::instance($PAGE->cm->instance);
    }

    // for some actions you need to be enrolled, beiing admin is not enough sometimes here
    $enrolled = is_enrolled($PAGE->cm->context, $USER, '', false);
    $activeenrolled = is_enrolled($PAGE->cm->context, $USER, '', true);

    $canmanage  = has_capability('mod/anonforum:managesubscriptions', $PAGE->cm->context);
    $subscriptionmode = anonforum_get_forcesubscribed($anonforumobject);
    $cansubscribe = ($activeenrolled && $subscriptionmode != ANONFORUM_FORCESUBSCRIBE && ($subscriptionmode != ANONFORUM_DISALLOWSUBSCRIBE || $canmanage));

    if ($canmanage) {
        $mode = $anonforumnode->add(get_string('subscriptionmode', 'anonforum'), null, navigation_node::TYPE_CONTAINER);

        $allowchoice = $mode->add(get_string('subscriptionoptional', 'anonforum'), new moodle_url('/mod/anonforum/subscribe.php', array('id'=>$anonforumobject->id, 'mode'=>ANONFORUM_CHOOSESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceforever = $mode->add(get_string("subscriptionforced", "anonforum"), new moodle_url('/mod/anonforum/subscribe.php', array('id'=>$anonforumobject->id, 'mode'=>ANONFORUM_FORCESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceinitially = $mode->add(get_string("subscriptionauto", "anonforum"), new moodle_url('/mod/anonforum/subscribe.php', array('id'=>$anonforumobject->id, 'mode'=>ANONFORUM_INITIALSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $disallowchoice = $mode->add(get_string('subscriptiondisabled', 'anonforum'), new moodle_url('/mod/anonforum/subscribe.php', array('id'=>$anonforumobject->id, 'mode'=>ANONFORUM_DISALLOWSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);

        switch ($subscriptionmode) {
            case ANONFORUM_CHOOSESUBSCRIBE : // 0
                $allowchoice->action = null;
                $allowchoice->add_class('activesetting');
                break;
            case ANONFORUM_FORCESUBSCRIBE : // 1
                $forceforever->action = null;
                $forceforever->add_class('activesetting');
                break;
            case ANONFORUM_INITIALSUBSCRIBE : // 2
                $forceinitially->action = null;
                $forceinitially->add_class('activesetting');
                break;
            case ANONFORUM_DISALLOWSUBSCRIBE : // 3
                $disallowchoice->action = null;
                $disallowchoice->add_class('activesetting');
                break;
        }

    } else if ($activeenrolled) {

        switch ($subscriptionmode) {
            case ANONFORUM_CHOOSESUBSCRIBE : // 0
                $notenode = $anonforumnode->add(get_string('subscriptionoptional', 'anonforum'));
                break;
            case ANONFORUM_FORCESUBSCRIBE : // 1
                $notenode = $anonforumnode->add(get_string('subscriptionforced', 'anonforum'));
                break;
            case ANONFORUM_INITIALSUBSCRIBE : // 2
                $notenode = $anonforumnode->add(get_string('subscriptionauto', 'anonforum'));
                break;
            case ANONFORUM_DISALLOWSUBSCRIBE : // 3
                $notenode = $anonforumnode->add(get_string('subscriptiondisabled', 'anonforum'));
                break;
        }
    }

    if ($cansubscribe) {
        if (anonforum_is_subscribed($USER->id, $anonforumobject)) {
            $linktext = get_string('unsubscribe', 'anonforum');
        } else {
            $linktext = get_string('subscribe', 'anonforum');
        }
        $url = new moodle_url('/mod/anonforum/subscribe.php', array('id'=>$anonforumobject->id, 'sesskey'=>sesskey()));
        $anonforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
    }

    if (has_capability('mod/anonforum:viewsubscribers', $PAGE->cm->context)){
        $url = new moodle_url('/mod/anonforum/subscribers.php', array('id'=>$anonforumobject->id));
        $anonforumnode->add(get_string('showsubscribers', 'anonforum'), $url, navigation_node::TYPE_SETTING);
    }

    if ($enrolled && anonforum_tp_can_track_anonforums($anonforumobject)) { // keep tracking info for users with suspended enrolments
        if ($anonforumobject->trackingtype == ANONFORUM_TRACKING_OPTIONAL
                || ((!$CFG->anonforum_allowforcedreadtracking) && $anonforumobject->trackingtype == ANONFORUM_TRACKING_FORCED)) {
            if (anonforum_tp_is_tracked($anonforumobject)) {
                $linktext = get_string('notrackanonforum', 'anonforum');
            } else {
                $linktext = get_string('trackanonforum', 'anonforum');
            }
            $url = new moodle_url('/mod/anonforum/settracking.php', array('id'=>$anonforumobject->id));
            $anonforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
        }
    }

    if (!isloggedin() && $PAGE->course->id == SITEID) {
        $userid = guest_user()->id;
    } else {
        $userid = $USER->id;
    }

    $hascourseaccess = ($PAGE->course->id == SITEID) || can_access_course($PAGE->course, $userid);
    $enablerssfeeds = !empty($CFG->enablerssfeeds) && !empty($CFG->anonforum_enablerssfeeds);

    if ($enablerssfeeds && $anonforumobject->rsstype && $anonforumobject->rssarticles && $hascourseaccess) {

        if (!function_exists('rss_get_url')) {
            require_once("$CFG->libdir/rsslib.php");
        }

        if ($anonforumobject->rsstype == 1) {
            $string = get_string('rsssubscriberssdiscussions','anonforum');
        } else {
            $string = get_string('rsssubscriberssposts','anonforum');
        }

        $url = new moodle_url(rss_get_url($PAGE->cm->context->id, $userid, "mod_anonforum", $anonforumobject->id));
        $anonforumnode->add($string, $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('i/rss', ''));
    }
}

/**
 * Abstract class used by anonforum subscriber selection controls
 * @package mod-anonforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class anonforum_subscriber_selector_base extends user_selector_base {

    /**
     * The id of the anonforum this selector is being used for
     * @var int
     */
    protected $anonforumid = null;
    /**
     * The context of the anonforum this selector is being used for
     * @var object
     */
    protected $context = null;
    /**
     * The id of the current group
     * @var int
     */
    protected $currentgroup = null;

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        $options['accesscontext'] = $options['context'];
        parent::__construct($name, $options);
        if (isset($options['context'])) {
            $this->context = $options['context'];
        }
        if (isset($options['currentgroup'])) {
            $this->currentgroup = $options['currentgroup'];
        }
        if (isset($options['anonforumid'])) {
            $this->anonforumid = $options['anonforumid'];
        }
    }

    /**
     * Returns an array of options to seralise and store for searches
     *
     * @return array
     */
    protected function get_options() {
        global $CFG;
        $options = parent::get_options();
        $options['file'] =  substr(__FILE__, strlen($CFG->dirroot.'/'));
        $options['context'] = $this->context;
        $options['currentgroup'] = $this->currentgroup;
        $options['anonforumid'] = $this->anonforumid;
        return $options;
    }

}

/**
 * A user selector control for potential subscribers to the selected anonforum
 * @package mod-anonforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anonforum_potential_subscriber_selector extends anonforum_subscriber_selector_base {
    /**
     * If set to true EVERYONE in this course is force subscribed to this anonforum
     * @var bool
     */
    protected $forcesubscribed = false;
    /**
     * Can be used to store existing subscribers so that they can be removed from
     * the potential subscribers list
     */
    protected $existingsubscribers = array();

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        parent::__construct($name, $options);
        if (isset($options['forcesubscribed'])) {
            $this->forcesubscribed=true;
        }
    }

    /**
     * Returns an arary of options for this control
     * @return array
     */
    protected function get_options() {
        $options = parent::get_options();
        if ($this->forcesubscribed===true) {
            $options['forcesubscribed']=1;
        }
        return $options;
    }

    /**
     * Finds all potential users
     *
     * Potential subscribers are all enroled users who are not already subscribed.
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;

        $whereconditions = array();
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        if ($wherecondition) {
            $whereconditions[] = $wherecondition;
        }

        if (!$this->forcesubscribed) {
            $existingids = array();
            foreach ($this->existingsubscribers as $group) {
                foreach ($group as $user) {
                    $existingids[$user->id] = 1;
                }
            }
            if ($existingids) {
                list($usertest, $userparams) = $DB->get_in_or_equal(
                        array_keys($existingids), SQL_PARAMS_NAMED, 'existing', false);
                $whereconditions[] = 'u.id ' . $usertest;
                $params = array_merge($params, $userparams);
            }
        }

        if ($whereconditions) {
            $wherecondition = 'WHERE ' . implode(' AND ', $whereconditions);
        }

        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $params = array_merge($params, $eparams);

        $fields      = 'SELECT ' . $this->required_fields_sql('u');
        $countfields = 'SELECT COUNT(u.id)';

        $sql = " FROM {user} u
                 JOIN ($esql) je ON je.id = u.id
                      $wherecondition";

        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $order = ' ORDER BY ' . $sort;

        // Check to see if there are too many to show sensibly.
        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $params);
            if ($potentialmemberscount > $this->maxusersperpage) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }

        // If not, show them.
        $availableusers = $DB->get_records_sql($fields . $sql . $order, array_merge($params, $sortparams));

        if (empty($availableusers)) {
            return array();
        }

        if ($this->forcesubscribed) {
            return array(get_string("existingsubscribers", 'anonforum') => $availableusers);
        } else {
            return array(get_string("potentialsubscribers", 'anonforum') => $availableusers);
        }
    }

    /**
     * Sets the existing subscribers
     * @param array $users
     */
    public function set_existing_subscribers(array $users) {
        $this->existingsubscribers = $users;
    }

    /**
     * Sets this anonforum as force subscribed or not
     */
    public function set_force_subscribed($setting=true) {
        $this->forcesubscribed = true;
    }
}

/**
 * User selector control for removing subscribed users
 * @package mod-anonforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anonforum_existing_subscriber_selector extends anonforum_subscriber_selector_base {

    /**
     * Finds all subscribed users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        $params['anonforumid'] = $this->anonforumid;

        // only active enrolled or everybody on the frontpage
        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $fields = $this->required_fields_sql('u');
        list($sort, $sortparams) = users_order_by_sql('u', $search, $this->accesscontext);
        $params = array_merge($params, $eparams, $sortparams);

        $subscribers = $DB->get_records_sql("SELECT $fields
                                               FROM {user} u
                                               JOIN ($esql) je ON je.id = u.id
                                               JOIN {anonforum_subscriptions} s ON s.userid = u.id
                                              WHERE $wherecondition AND s.anonforum = :anonforumid
                                           ORDER BY $sort", $params);

        return array(get_string("existingsubscribers", 'anonforum') => $subscribers);
    }

}

/**
 * Adds information about unread messages, that is only required for the course view page (and
 * similar), to the course-module object.
 * @param cm_info $cm Course-module object
 */
function anonforum_cm_info_view(cm_info $cm) {
    global $CFG;

    if (anonforum_tp_can_track_anonforums()) {
        if ($unread = anonforum_tp_count_anonforum_unread_posts($cm, $cm->get_course())) {
            $out = '<span class="unread"> <a href="' . $cm->get_url() . '">';
            if ($unread == 1) {
                $out .= get_string('unreadpostsone', 'anonforum');
            } else {
                $out .= get_string('unreadpostsnumber', 'anonforum', $unread);
            }
            $out .= '</a></span>';
            $cm->set_after_link($out);
        }
    }
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function anonforum_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $anonforum_pagetype = array(
        'mod-anonforum-*'=>get_string('page-mod-anonforum-x', 'anonforum'),
        'mod-anonforum-view'=>get_string('page-mod-anonforum-view', 'anonforum'),
        'mod-anonforum-discuss'=>get_string('page-mod-anonforum-discuss', 'anonforum')
    );
    return $anonforum_pagetype;
}

/**
 * Gets all of the courses where the provided user has posted in a anonforum.
 *
 * @global moodle_database $DB The database connection
 * @param stdClass $user The user who's posts we are looking for
 * @param bool $discussionsonly If true only look for discussions started by the user
 * @param bool $includecontexts If set to trye contexts for the courses will be preloaded
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of courses
 */
function anonforum_get_courses_user_posted_in($user, $discussionsonly = false, $includecontexts = true, $limitfrom = null, $limitnum = null) {
    global $DB;

    // If we are only after discussions we need only look at the anonymous forum_discussions
    // table and join to the userid there. If we are looking for posts then we need
    // to join to the anonymous forum_posts table.
    if (!$discussionsonly) {
        $subquery = "(SELECT DISTINCT fd.course
                         FROM {anonforum_discussions} fd
                         JOIN {anonforum_posts} fp ON fp.discussion = fd.id
                        WHERE fp.userid = :userid )";
    } else {
        $subquery= "(SELECT DISTINCT fd.course
                         FROM {anonforum_discussions} fd
                        WHERE fd.userid = :userid )";
    }

    $params = array('userid' => $user->id);

    // Join to the context table so that we can preload contexts if required.
    if ($includecontexts) {
        $ctxselect = ', ' . context_helper::get_preload_record_columns_sql('ctx');
        $ctxjoin = "LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)";
        $params['contextlevel'] = CONTEXT_COURSE;
    } else {
        $ctxselect = '';
        $ctxjoin = '';
    }

    // Now we need to get all of the courses to search.
    // All courses where the user has posted within a anonymous forum will be returned.
    $sql = "SELECT c.* $ctxselect
            FROM {course} c
            $ctxjoin
            WHERE c.id IN ($subquery)";
    $courses = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    if ($includecontexts) {
        array_map('context_helper::preload_from_record', $courses);
    }
    return $courses;
}

/**
 * Gets all of the anonforums a user has posted in for one or more courses.
 *
 * @global moodle_database $DB
 * @param stdClass $user
 * @param array $courseids An array of courseids to search or if not provided
 *                       all courses the user has posted within
 * @param bool $discussionsonly If true then only anonforums where the user has started
 *                       a discussion will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return array An array of anonforums the user has posted within in the provided courses
 */
function anonforum_get_anonforums_user_posted_in($user, array $courseids = null, $discussionsonly = false, $limitfrom = null, $limitnum = null) {
    global $DB;

    if (!is_null($courseids)) {
        list($coursewhere, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
        $coursewhere = ' AND f.course '.$coursewhere;
    } else {
        $coursewhere = '';
        $params = array();
    }
    $params['userid'] = $user->id;
    $params['anonforum'] = 'anonforum';

    if ($discussionsonly) {
        $join = 'JOIN {anonforum_discussions} ff ON ff.anonforum = f.id';
    } else {
        $join = 'JOIN {anonforum_discussions} fd ON fd.anonforum = f.id
                 JOIN {anonforum_posts} ff ON ff.discussion = fd.id';
    }

    $sql = "SELECT f.*, cm.id AS cmid
              FROM {anonforum} f
              JOIN {course_modules} cm ON cm.instance = f.id
              JOIN {modules} m ON m.id = cm.module
              JOIN (
                  SELECT f.id
                    FROM {anonforum} f
                    {$join}
                   WHERE ff.userid = :userid
                GROUP BY f.id
                   ) j ON j.id = f.id
             WHERE m.name = :anonforum
                 {$coursewhere}";

    $courseanonforums = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    return $courseanonforums;
}

/**
 * Returns posts made by the selected user in the requested courses.
 *
 * This method can be used to return all of the posts made by the requested user
 * within the given courses.
 * For each course the access of the current user and requested user is checked
 * and then for each post access to the post and anonforum is checked as well.
 *
 * This function is safe to use with usercapabilities.
 *
 * @global moodle_database $DB
 * @param stdClass $user The user whose posts we want to get
 * @param array $courses The courses to search
 * @param bool $musthaveaccess If set to true errors will be thrown if the user
 *                             cannot access one or more of the courses to search
 * @param bool $discussionsonly If set to true only discussion starting posts
 *                              will be returned.
 * @param int $limitfrom The offset of records to return
 * @param int $limitnum The number of records to return
 * @return stdClass An object the following properties
 *               ->totalcount: the total number of posts made by the requested user
 *                             that the current user can see.
 *               ->courses: An array of courses the current user can see that the
 *                          requested user has posted in.
 *               ->anonforums: An array of anonforums relating to the posts returned in the
 *                         property below.
 *               ->posts: An array containing the posts to show for this request.
 */
function anonforum_get_posts_by_user($user, array $courses, $musthaveaccess = false, $discussionsonly = false, $limitfrom = 0, $limitnum = 50) {
    global $DB, $USER, $CFG;

    $return = new stdClass;
    $return->totalcount = 0;    // The total number of posts that the current user is able to view
    $return->courses = array(); // The courses the current user can access
    $return->anonforums = array();  // The anonymous forums that the current user can access that contain posts
    $return->posts = array();   // The posts to display

    // First up a small sanity check. If there are no courses to check we can
    // return immediately, there is obviously nothing to search.
    if (empty($courses)) {
        return $return;
    }

    // A couple of quick setups
    $isloggedin = isloggedin();
    $isguestuser = $isloggedin && isguestuser();
    $iscurrentuser = $isloggedin && $USER->id == $user->id;

    // Checkout whether or not the current user has capabilities over the requested
    // user and if so they have the capabilities required to view the requested
    // users content.
    $usercontext = context_user::instance($user->id, MUST_EXIST);
    $hascapsonuser = !$iscurrentuser && $DB->record_exists('role_assignments', array('userid' => $USER->id, 'contextid' => $usercontext->id));
    $hascapsonuser = $hascapsonuser && has_all_capabilities(array('moodle/user:viewdetails', 'moodle/user:readuserposts'), $usercontext);

    // Before we actually search each course we need to check the user's access to the
    // course. If the user doesn't have the appropraite access then we either throw an
    // error if a particular course was requested or we just skip over the course.
    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id, MUST_EXIST);
        if ($iscurrentuser || $hascapsonuser) {
            // If it is the current user, or the current user has capabilities to the
            // requested user then all we need to do is check the requested users
            // current access to the course.
            // Note: There is no need to check group access or anything of the like
            // as either the current user is the requested user, or has granted
            // capabilities on the requested user. Either way they can see what the
            // requested user posted, although its VERY unlikely in the `parent` situation
            // that the current user will be able to view the posts in context.
            if (!is_viewing($coursecontext, $user) && !is_enrolled($coursecontext, $user)) {
                // Need to have full access to a course to see the rest of own info
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'anonforum');
                }
                continue;
            }
        } else {
            // Check whether the current user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course)) {
                if ($musthaveaccess) {
                    print_error('errorenrolmentrequired', 'anonforum');
                }
                continue;
            }

            // Check whether the requested user is enrolled or has access to view the course
            // if they don't we immediately have a problem.
            if (!can_access_course($course, $user)) {
                if ($musthaveaccess) {
                    print_error('notenrolled', 'anonforum');
                }
                continue;
            }

            // If groups are in use and enforced throughout the course then make sure
            // we can meet in at least one course level group.
            // Note that we check if either the current user or the requested user have
            // the capability to access all groups. This is because with that capability
            // a user in group A could post in the group B anonymous forum. Grrrr.
            if (groups_get_course_groupmode($course) == SEPARATEGROUPS && $course->groupmodeforce
              && !has_capability('moodle/site:accessallgroups', $coursecontext) && !has_capability('moodle/site:accessallgroups', $coursecontext, $user->id)) {
                // If its the guest user to bad... the guest user cannot access groups
                if (!$isloggedin or $isguestuser) {
                    // do not use require_login() here because we might have already used require_login($course)
                    if ($musthaveaccess) {
                        redirect(get_login_url());
                    }
                    continue;
                }
                // Get the groups of the current user
                $mygroups = array_keys(groups_get_all_groups($course->id, $USER->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Get the groups the requested user is a member of
                $usergroups = array_keys(groups_get_all_groups($course->id, $user->id, $course->defaultgroupingid, 'g.id, g.name'));
                // Check whether they are members of the same group. If they are great.
                $intersect = array_intersect($mygroups, $usergroups);
                if (empty($intersect)) {
                    // But they're not... if it was a specific course throw an error otherwise
                    // just skip this course so that it is not searched.
                    if ($musthaveaccess) {
                        print_error("groupnotamember", '', $CFG->wwwroot."/course/view.php?id=$course->id");
                    }
                    continue;
                }
            }
        }
        // Woo hoo we got this far which means the current user can search this
        // this course for the requested user. Although this is only the course accessibility
        // handling that is complete, the anonymous forum accessibility tests are yet to come.
        $return->courses[$course->id] = $course;
    }
    // No longer beed $courses array - lose it not it may be big
    unset($courses);

    // Make sure that we have some courses to search
    if (empty($return->courses)) {
        // If we don't have any courses to search then the reality is that the current
        // user doesn't have access to any courses is which the requested user has posted.
        // Although we do know at this point that the requested user has posts.
        if ($musthaveaccess) {
            print_error('permissiondenied');
        } else {
            return $return;
        }
    }

    // Next step: Collect all of the anonymous forums that we will want to search.
    // It is important to note that this step isn't actually about searching, it is
    // about determining which anonymous forums we can search by testing accessibility.
    $anonforums = anonforum_get_anonforums_user_posted_in($user, array_keys($return->courses), $discussionsonly);

    // Will be used to build the where conditions for the search
    $anonforumsearchwhere = array();
    // Will be used to store the where condition params for the search
    $anonforumsearchparams = array();
    // Will record anonymous forums where the user can freely access everything
    $anonforumsearchfullaccess = array();
    // DB caching friendly
    $now = round(time(), -2);
    // For each course to search we want to find the anonymous forums the user has posted in
    // and providing the current user can access the anonymous forum create a search condition
    // for the anonymous forum to get the requested users posts.
    foreach ($return->courses as $course) {
        // Now we need to get the anonymous forums
        $modinfo = get_fast_modinfo($course);
        if (empty($modinfo->instances['anonforum'])) {
            // hmmm, no anonymous forums? well at least its easy... skip!
            continue;
        }
        // Iterate
        foreach ($modinfo->get_instances_of('anonforum') as $anonforumid => $cm) {
            if (!$cm->uservisible or !isset($anonforums[$anonforumid])) {
                continue;
            }
            // Get the anonymous forum in question
            $anonforum = $anonforums[$anonforumid];

            // This is needed for functionality later on in the anonymous forum code. It is converted to an object
            // because the cm_info is readonly from 2.6. This is a dirty hack because some other parts of the
            // code were expecting an writeable object. See {@link anonymous forum_print_post()}.
            $anonforum->cm = new stdClass();
            foreach ($cm as $key => $value) {
                $anonforum->cm->$key = $value;
            }

            // Check that either the current user can view the anonymous forum, or that the
            // current user has capabilities over the requested user and the requested
            // user can view the discussion
            if (!has_capability('mod/anonforum:viewdiscussion', $cm->context) && !($hascapsonuser && has_capability('mod/anonforum:viewdiscussion', $cm->context, $user->id))) {
                continue;
            }

            // This will contain anonymous forum specific where clauses
            $anonforumsearchselect = array();
            if (!$iscurrentuser && !$hascapsonuser) {
                // Make sure we check group access
                if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $cm->context)) {
                    $groups = $modinfo->get_groups($cm->groupingid);
                    $groups[] = -1;
                    list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED, 'grps'.$anonforumid.'_');
                    $anonforumsearchparams = array_merge($anonforumsearchparams, $groupid_params);
                    $anonforumsearchselect[] = "d.groupid $groupid_sql";
                }

                // hidden timed discussions
                if (!empty($CFG->anonforum_enabletimedposts) && !has_capability('mod/anonforum:viewhiddentimedposts', $cm->context)) {
                    $anonforumsearchselect[] = "(d.userid = :userid{$anonforumid} OR (d.timestart < :timestart{$anonforumid} AND (d.timeend = 0 OR d.timeend > :timeend{$anonforumid})))";
                    $anonforumsearchparams['userid'.$anonforumid] = $user->id;
                    $anonforumsearchparams['timestart'.$anonforumid] = $now;
                    $anonforumsearchparams['timeend'.$anonforumid] = $now;
                }

                // qanda access
                if ($anonforum->type == 'qanda' && !has_capability('mod/anonforum:viewqandawithoutposting', $cm->context)) {
                    // We need to check whether the user has posted in the qanda anonymous forum.
                    $discussionspostedin = anonforum_discussions_user_has_posted_in($anonforum->id, $user->id);
                    if (!empty($discussionspostedin)) {
                        $anonforumonlydiscussions = array();  // Holds discussion ids for the discussions the user is allowed to see in this anonymous forum.
                        foreach ($discussionspostedin as $d) {
                            $anonforumonlydiscussions[] = $d->id;
                        }
                        list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($anonforumonlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$anonforumid.'_');
                        $anonforumsearchparams = array_merge($anonforumsearchparams, $discussionid_params);
                        $anonforumsearchselect[] = "(d.id $discussionid_sql OR p.parent = 0)";
                    } else {
                        $anonforumsearchselect[] = "p.parent = 0";
                    }

                }

                if (count($anonforumsearchselect) > 0) {
                    $anonforumsearchwhere[] = "(d.anonforum = :anonforum{$anonforumid} AND ".implode(" AND ", $anonforumsearchselect).")";
                    $anonforumsearchparams['anonforum'.$anonforumid] = $anonforumid;
                } else {
                    $anonforumsearchfullaccess[] = $anonforumid;
                }
            } else {
                // The current user/parent can see all of their own posts
                $anonforumsearchfullaccess[] = $anonforumid;
            }
        }
    }

    // If we dont have any search conditions, and we don't have any anonymous forums where
    // the user has full access then we just return the default.
    if (empty($anonforumsearchwhere) && empty($anonforumsearchfullaccess)) {
        return $return;
    }

    // Prepare a where condition for the full access anonymous forums.
    if (count($anonforumsearchfullaccess) > 0) {
        list($fullidsql, $fullidparams) = $DB->get_in_or_equal($anonforumsearchfullaccess, SQL_PARAMS_NAMED, 'fula');
        $anonforumsearchparams = array_merge($anonforumsearchparams, $fullidparams);
        $anonforumsearchwhere[] = "(d.anonforum $fullidsql)";
    }

    // Prepare SQL to both count and search.
    // We alias user.id to useridx because we anonymous forum_posts already has a userid field and not aliasing this would break
    // oracle and mssql.
    $userfields = user_picture::fields('u', null, 'useridx');
    $countsql = 'SELECT COUNT(*) ';
    $selectsql = 'SELECT p.*, d.anonforum, d.name AS discussionname, '.$userfields.' ';
    $wheresql = implode(" OR ", $anonforumsearchwhere);

    if ($discussionsonly) {
        if ($wheresql == '') {
            $wheresql = 'p.parent = 0';
        } else {
            $wheresql = 'p.parent = 0 AND ('.$wheresql.')';
        }
    }
    // Only view anonymous posts if I created them.
    if (!$iscurrentuser) {
        if ($wheresql == '') {
            $wheresql = 'p.anonymouspost = 0';
        } else {
            $wheresql = 'p.anonymouspost = 0 AND ('.$wheresql.')';
        }
    }

    $sql = "FROM {anonforum_posts} p
            JOIN {anonforum_discussions} d ON d.id = p.discussion
            JOIN {user} u ON u.id = p.userid
           WHERE ($wheresql)
             AND p.userid = :userid ";
    $orderby = "ORDER BY p.modified DESC";
    $anonforumsearchparams['userid'] = $user->id;

    // Set the total number posts made by the requested user that the current user can see
    $return->totalcount = $DB->count_records_sql($countsql.$sql, $anonforumsearchparams);
    // Set the collection of posts that has been requested
    $return->posts = $DB->get_records_sql($selectsql.$sql.$orderby, $anonforumsearchparams, $limitfrom, $limitnum);

    // We need to build an array of anonymous forums for which posts will be displayed.
    // We do this here to save the caller needing to retrieve them themselves before
    // printing these anonforums posts. Given we have the anonymous forums already there is
    // practically no overhead here.
    foreach ($return->posts as $post) {
        if (!array_key_exists($post->anonforum, $return->anonforums)) {
            $return->anonforums[$post->anonforum] = $anonforums[$post->anonforum];
        }
    }

    return $return;
}

/**
 * Set the per-anonforum maildigest option for the specified user.
 *
 * @param stdClass $anonforum The anonforum to set the option for.
 * @param int $maildigest The maildigest option.
 * @param stdClass $user The user object. This defaults to the global $USER object.
 * @throws invalid_digest_setting thrown if an invalid maildigest option is provided.
 */
function anonforum_set_user_maildigest($anonforum, $maildigest, $user = null) {
    global $DB, $USER;

    if (is_number($anonforum)) {
        $anonforum = $DB->get_record('anonforum', array('id' => $anonforum));
    }

    if ($user === null) {
        $user = $USER;
    }

    $course  = $DB->get_record('course', array('id' => $anonforum->course), '*', MUST_EXIST);
    $cm      = get_coursemodule_from_instance('anonforum', $anonforum->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // User must be allowed to see this anonymous forum.
    require_capability('mod/anonforum:viewdiscussion', $context, $user->id);

    // Validate the maildigest setting.
    $digestoptions = anonforum_get_user_digest_options($user);

    if (!isset($digestoptions[$maildigest])) {
        throw new moodle_exception('invaliddigestsetting', 'mod_anonforum');
    }

    // Attempt to retrieve any existing anonymous forum digest record.
    $subscription = $DB->get_record('anonforum_digests', array(
        'userid' => $user->id,
        'anonforum' => $anonforum->id,
    ));

    // Create or Update the existing maildigest setting.
    if ($subscription) {
        if ($maildigest == -1) {
            $DB->delete_records('anonforum_digests', array('anonforum' => $anonforum->id, 'userid' => $user->id));
        } else if ($maildigest !== $subscription->maildigest) {
            // Only update the maildigest setting if it's changed.

            $subscription->maildigest = $maildigest;
            $DB->update_record('anonforum_digests', $subscription);
        }
    } else {
        if ($maildigest != -1) {
            // Only insert the maildigest setting if it's non-default.

            $subscription = new stdClass();
            $subscription->anonforum = $anonforum->id;
            $subscription->userid = $user->id;
            $subscription->maildigest = $maildigest;
            $subscription->id = $DB->insert_record('anonforum_digests', $subscription);
        }
    }
}

/**
 * Determine the maildigest setting for the specified user against the
 * specified anonforum.
 *
 * @param Array $digests An array of anonforums and user digest settings.
 * @param stdClass $user The user object containing the id and maildigest default.
 * @param int $anonforumid The ID of the anonforum to check.
 * @return int The calculated maildigest setting for this user and anonforum.
 */
function anonforum_get_user_maildigest_bulk($digests, $user, $anonforumid) {
    if (isset($digests[$anonforumid]) && isset($digests[$anonforumid][$user->id])) {
        $maildigest = $digests[$anonforumid][$user->id];
        if ($maildigest === -1) {
            $maildigest = $user->maildigest;
        }
    } else {
        $maildigest = $user->maildigest;
    }
    return $maildigest;
}

/**
 * Retrieve the list of available user digest options.
 *
 * @param stdClass $user The user object. This defaults to the global $USER object.
 * @return array The mapping of values to digest options.
 */
function anonforum_get_user_digest_options($user = null) {
    global $USER;

    // Revert to the global user object.
    if ($user === null) {
        $user = $USER;
    }

    $digestoptions = array();
    $digestoptions['0']  = get_string('emaildigestoffshort', 'mod_anonforum');
    $digestoptions['1']  = get_string('emaildigestcompleteshort', 'mod_anonforum');
    $digestoptions['2']  = get_string('emaildigestsubjectsshort', 'mod_anonforum');

    // We need to add the default digest option at the end - it relies on
    // the contents of the existing values.
    $digestoptions['-1'] = get_string('emaildigestdefault', 'mod_anonforum',
            $digestoptions[$user->maildigest]);

    // Resort the options to be in a sensible order.
    ksort($digestoptions);

    return $digestoptions;
}

function anon_user_picture(stdClass $userin) {
    global $PAGE;
    $user = clone $userin;
    $user->imagealt = '';
    $user->picture = '';
    $userpicture = new user_picture($user);

    if (empty($userpicture->size)) {
        $size = 35;
    } else if ($userpicture->size === true or $userpicture->size == 1) {
        $size = 100;
    } else {
        $size = $userpicture->size;
    }

    $class = 'user_picture';

    if ($user->picture == 0) {
        $class .= '';
    }

    $src = $userpicture->get_url($PAGE, null);

    $attributes = array('src'=>$src, 'class'=>$class, 'width'=>$size, 'height'=>$size);

    // get the image html output fisrt
    return html_writer::empty_tag('img', $attributes);

}

