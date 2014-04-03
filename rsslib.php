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
 * This file adds support to rss feeds generation
 *
 * @package mod_anonforum
 * @category rss
 * @copyright 2001 Eloy Lafuente (stronk7) http://contiento.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* Include the core RSS lib */
require_once($CFG->libdir.'/rsslib.php');

/**
 * Returns the path to the cached rss feed contents. Creates/updates the cache if necessary.
 * @param stdClass $context the context
 * @param array    $args    the arguments received in the url
 * @return string the full path to the cached RSS feed directory. Null if there is a problem.
 */
function anonforum_rss_get_feed($context, $args) {
    global $CFG, $DB, $USER;

    $status = true;

    //are RSS feeds enabled?
    if (empty($CFG->anonforum_enablerssfeeds)) {
        debugging('DISABLED (module configuration)');
        return null;
    }

    $anonforumid  = clean_param($args[3], PARAM_INT);
    $cm = get_coursemodule_from_instance('anonforum', $anonforumid, 0, false, MUST_EXIST);
    $modcontext = context_module::instance($cm->id);

    //context id from db should match the submitted one
    if ($context->id != $modcontext->id || !has_capability('mod/anonforum:viewdiscussion', $modcontext)) {
        return null;
    }

    $anonforum = $DB->get_record('anonforum', array('id' => $anonforumid), '*', MUST_EXIST);
    if (!rss_enabled_for_mod('anonforum', $anonforum)) {
        return null;
    }

    //the sql that will retreive the data for the feed and be hashed to get the cache filename
    list($sql, $params) = anonforum_rss_get_sql($anonforum, $cm);

    // Hash the sql to get the cache file name.
    $filename = rss_get_file_name($anonforum, $sql, $params);
    $cachedfilepath = rss_get_file_full_name('mod_anonforum', $filename);

    //Is the cache out of date?
    $cachedfilelastmodified = 0;
    if (file_exists($cachedfilepath)) {
        $cachedfilelastmodified = filemtime($cachedfilepath);
    }
    // Used to determine if we need to generate a new RSS feed.
    $dontrecheckcutoff = time() - 60; // Sixty seconds ago.

    // If it hasn't been generated we need to create it.
    // Otherwise, if it has been > 60 seconds since we last updated, check for new items.
    if (($cachedfilelastmodified == 0) || (($dontrecheckcutoff > $cachedfilelastmodified) &&
        anonforum_rss_newstuff($anonforum, $cm, $cachedfilelastmodified))) {
        // Need to regenerate the cached version.
        $result = anonforum_rss_feed_contents($anonforum, $sql, $params, $modcontext);
        $status = rss_save_file('mod_anonforum', $filename, $result);
    }

    //return the path to the cached version
    return $cachedfilepath;
}

/**
 * Given a anonforum object, deletes all cached RSS files associated with it.
 *
 * @param stdClass $anonforum
 */
function anonforum_rss_delete_file($anonforum) {
    rss_delete_file('mod_anonforum', $anonforum);
}

///////////////////////////////////////////////////////
//Utility functions

/**
 * If there is new stuff in the anonforum since $time this returns true
 * Otherwise it returns false.
 *
 * @param stdClass $anonforum the anonforum object
 * @param stdClass $cm    Course Module object
 * @param int      $time  check for items since this epoch timestamp
 * @return bool True for new items
 */
function anonforum_rss_newstuff($anonforum, $cm, $time) {
    global $DB;

    list($sql, $params) = anonforum_rss_get_sql($anonforum, $cm, $time);

    return $DB->record_exists_sql($sql, $params);
}

/**
 * Determines which type of SQL query is required, one for posts or one for discussions, and returns the appropriate query
 *
 * @param stdClass $anonforum the anonforum object
 * @param stdClass $cm    Course Module object
 * @param int      $time  check for items since this epoch timestamp
 * @return string the SQL query to be used to get the Discussion/Post details from the anonforum table of the database
 */
function anonforum_rss_get_sql($anonforum, $cm, $time=0) {
    if ($anonforum->rsstype == 1) { // Discussion RSS
        return anonforum_rss_feed_discussions_sql($anonforum, $cm, $time);
    } else { // Post RSS
        return anonforum_rss_feed_posts_sql($anonforum, $cm, $time);
    }
}

/**
 * Generates the SQL query used to get the Discussion details from the anonforum table of the database
 *
 * @param stdClass $anonforum     the anonforum object
 * @param stdClass $cm        Course Module object
 * @param int      $newsince  check for items since this epoch timestamp
 * @return string the SQL query to be used to get the Discussion details from the anonforum table of the database
 */
function anonforum_rss_feed_discussions_sql($anonforum, $cm, $newsince=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $modcontext = null;

    $now = round(time(), -2);
    $params = array();

    $modcontext = context_module::instance($cm->id);

    if (!empty($CFG->anonforum_enabletimedposts)) { /// Users must fulfill timed posts
        if (!has_capability('mod/anonforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= :now1 AND (d.timeend = 0 OR d.timeend > :now2))";
            $params['now1'] = $now;
            $params['now2'] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = :userid";
                $params['userid'] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    // Do we only want new posts?
    if ($newsince) {
        $params['newsince'] = $newsince;
        $newsince = " AND p.modified > :newsince";
    } else {
        $newsince = '';
    }

    // Get group enforcing SQL.
    $groupmode = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);
    list($groupselect, $groupparams) = anonforum_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext);

    // Add the groupparams to the params array.
    $params = array_merge($params, $groupparams);

    $anonforumsort = "d.timemodified DESC";
    $postdata = "p.id AS postid, p.subject, p.created as postcreated, p.modified, p.discussion, p.userid, p.message as postmessage, p.messageformat AS postformat, p.messagetrust AS posttrust, p.anonymouspost";
    $userpicturefields = user_picture::fields('u', null, 'userid');


    $sql = "SELECT $postdata, d.id as discussionid, d.name as discussionname, d.timemodified, d.usermodified, d.groupid,
                   d.timestart, d.timeend, $userpicturefields
              FROM {anonforum_discussions} d
                   JOIN {anonforum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
             WHERE d.anonforum = {$anonforum->id} AND p.parent = 0
                   $timelimit $groupselect $newsince
          ORDER BY $anonforumsort";
    return array($sql, $params);
}

/**
 * Generates the SQL query used to get the Post details from the anonforum table of the database
 *
 * @param stdClass $anonforum     the anonforum object
 * @param stdClass $cm        Course Module object
 * @param int      $newsince  check for items since this epoch timestamp
 * @return string the SQL query to be used to get the Post details from the anonforum table of the database
 */
function anonforum_rss_feed_posts_sql($anonforum, $cm, $newsince=0) {
    $modcontext = context_module::instance($cm->id);

    // Get group enforcement SQL.
    $groupmode = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);
    $params = array();

    list($groupselect, $groupparams) = anonforum_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext);

    // Add the groupparams to the params array.
    $params = array_merge($params, $groupparams);

    // Do we only want new posts?
    if ($newsince) {
        $params['newsince'] = $newsince;
        $newsince = " AND p.modified > :newsince";
    } else {
        $newsince = '';
    }

    $usernamefields = get_all_user_name_fields(true, 'u');
    $sql = "SELECT p.id AS postid,
                 d.id AS discussionid,
                 d.name AS discussionname,
                 d.groupid,
                 d.timestart,
                 d.timeend,
                 u.id AS userid,
                 $usernamefields,
                 p.subject AS postsubject,
                 p.message AS postmessage,
                 p.created AS postcreated,
                 p.messageformat AS postformat,
                 p.messagetrust AS posttrust,
                 p.parent as postparent,
                 p.anonymouspost as anonymouspost
            FROM {anonforum_discussions} d,
               {anonforum_posts} p,
               {user} u
            WHERE d.anonforum = {$anonforum->id} AND
                p.discussion = d.id AND
                u.id = p.userid $newsince
                $groupselect
            ORDER BY p.created desc";

    return array($sql, $params);
}

/**
 * Retrieve the correct SQL snippet for group-only anonforums
 *
 * @param stdClass $cm           Course Module object
 * @param int      $groupmode    the mode in which the anonforum's groups are operating
 * @param bool     $currentgroup true if the user is from the a group enabled on the anonforum
 * @param stdClass $modcontext   The context instance of the anonforum module
 * @return string SQL Query for group details of the anonforum
 */
function anonforum_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext=null) {
    $groupselect = '';
    $params = array();

    if ($groupmode) {
        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :groupid OR d.groupid = -1)";
                $params['groupid'] = $currentgroup;
            }
        } else {
            // Separate groups without access all.
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :groupid OR d.groupid = -1)";
                $params['groupid'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    }

    return array($groupselect, $params);
}

/**
 * This function return the XML rss contents about the anonforum
 * It returns false if something is wrong
 *
 * @param stdClass $anonforum the anonforum object
 * @param string $sql the SQL used to retrieve the contents from the database
 * @param array $params the SQL parameters used
 * @param object $context the context this anonforum relates to
 * @return bool|string false if the contents is empty, otherwise the contents of the feed is returned
 *
 * @Todo MDL-31129 implement post attachment handling
 */

function anonforum_rss_feed_contents($anonforum, $sql, $params, $context) {
    global $CFG, $DB, $USER;

    $status = true;

    $recs = $DB->get_recordset_sql($sql, $params, 0, $anonforum->rssarticles);

    //set a flag. Are we displaying discussions or posts?
    $isdiscussion = true;
    if (!empty($anonforum->rsstype) && $anonforum->rsstype!=1) {
        $isdiscussion = false;
    }

    if (!$cm = get_coursemodule_from_instance('anonforum', $anonforum->id, $anonforum->course)) {
        print_error('invalidcoursemodule');
    }

    $formatoptions = new stdClass();
    $items = array();
    foreach ($recs as $rec) {
            $item = new stdClass();

            $discussion = new stdClass();
            $discussion->id = $rec->discussionid;
            $discussion->groupid = $rec->groupid;
            $discussion->timestart = $rec->timestart;
            $discussion->timeend = $rec->timeend;

            $post = null;
            if (!$isdiscussion) {
                $post = new stdClass();
                $post->id = $rec->postid;
                $post->parent = $rec->postparent;
                $post->userid = $rec->userid;
            }

            if ($isdiscussion && !anonforum_user_can_see_discussion($anonforum, $discussion, $context)) {
                // This is a discussion which the user has no permission to view
                $item->title = get_string('anonforumsubjecthidden', 'anonforum');
                $message = get_string('anonforumbodyhidden', 'anonforum');
                $item->author = get_string('anonforumauthorhidden', 'anonforum');
            } else if (!$isdiscussion && !anonforum_user_can_see_post($anonforum, $discussion, $post, $USER, $cm)) {
                // This is a post which the user has no permission to view
                $item->title = get_string('anonforumsubjecthidden', 'anonforum');
                $message = get_string('anonforumbodyhidden', 'anonforum');
                $item->author = get_string('anonforumauthorhidden', 'anonforum');
            } else {
                // The user must have permission to view
                if ($isdiscussion && !empty($rec->discussionname)) {
                    $item->title = format_string($rec->discussionname);
                } else if (!empty($rec->postsubject)) {
                    $item->title = format_string($rec->postsubject);
                } else {
                    //we should have an item title by now but if we dont somehow then substitute something somewhat meaningful
                    $item->title = format_string($anonforum->name.' '.userdate($rec->postcreated,get_string('strftimedatetimeshort', 'langconfig')));
                }

                if (empty($rec->anonymouspost)) {
                    $user = new stdClass();
                    $user->firstname = $rec->userfirstname;
                    $user->lastname = $rec->userlastname;
                    $item->author = fullname($user);
                } else {
                    $item->author = get_string('anonymoususer', 'anonforum');
                }
                $message = file_rewrite_pluginfile_urls($rec->postmessage, 'pluginfile.php', $context->id,
                        'mod_anonforum', 'post', $rec->postid);
                $formatoptions->trusted = $rec->posttrust;
            }

            if ($isdiscussion) {
                $item->link = $CFG->wwwroot."/mod/anonforum/discuss.php?d=".$rec->discussionid;
            } else {
                $item->link = $CFG->wwwroot."/mod/anonforum/discuss.php?d=".$rec->discussionid."&parent=".$rec->postid;
            }

            $formatoptions->trusted = $rec->posttrust;
            $item->description = format_text($message, $rec->postformat, $formatoptions, $anonforum->course);

            //TODO: MDL-31129 implement post attachment handling
            /*if (!$isdiscussion) {
                $post_file_area_name = str_replace('//', '/', "$anonforum->course/$CFG->moddata/anonforum/$anonforum->id/$rec->postid");
                $post_files = get_directory_list("$CFG->dataroot/$post_file_area_name");

                if (!empty($post_files)) {
                    $item->attachments = array();
                }
            }*/
            $item->pubdate = $rec->postcreated;

            $items[] = $item;
        }
    $recs->close();

    // Create the RSS header.
    $header = rss_standard_header(strip_tags(format_string($anonforum->name, true)),
                                  $CFG->wwwroot."/mod/anonforum/view.php?f=".$anonforum->id,
                                  format_string($anonforum->intro,true)); // TODO: fix format
    // Now all the RSS items, if there are any.
    $articles = '';
    if (!empty($items)) {
        $articles = rss_add_items($items);
    }
    // Create the RSS footer.
    $footer = rss_standard_footer();

    return $header . $articles . $footer;
}
