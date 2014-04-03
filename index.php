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

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/anonforum/lib.php');
require_once($CFG->libdir . '/rsslib.php');

$id = optional_param('id', 0, PARAM_INT);                   // Course id
$subscribe = optional_param('subscribe', null, PARAM_INT);  // Subscribe/Unsubscribe all anonymous forums

$url = new moodle_url('/mod/anonforum/index.php', array('id'=>$id));
if ($subscribe !== null) {
    require_sesskey();
    $url->param('subscribe', $subscribe);
}
$PAGE->set_url($url);

if ($id) {
    if (! $course = $DB->get_record('course', array('id' => $id))) {
        print_error('invalidcourseid');
    }
} else {
    $course = get_site();
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');
$coursecontext = context_course::instance($course->id);


unset($SESSION->fromdiscussion);

add_to_log($course->id, 'anonforum', 'view anonforums', "index.php?id=$course->id");

$stranonforums       = get_string('anonforums', 'anonforum');
$stranonforum        = get_string('anonforum', 'anonforum');
$strdescription  = get_string('description');
$strdiscussions  = get_string('discussions', 'anonforum');
$strsubscribed   = get_string('subscribed', 'anonforum');
$strunreadposts  = get_string('unreadposts', 'anonforum');
$strtracking     = get_string('tracking', 'anonforum');
$strmarkallread  = get_string('markallread', 'anonforum');
$strtrackanonforum   = get_string('trackanonforum', 'anonforum');
$strnotrackanonforum = get_string('notrackanonforum', 'anonforum');
$strsubscribe    = get_string('subscribe', 'anonforum');
$strunsubscribe  = get_string('unsubscribe', 'anonforum');
$stryes          = get_string('yes');
$strno           = get_string('no');
$strrss          = get_string('rss');
$stremaildigest  = get_string('emaildigest');

$searchform = anonforum_search_form($course);

// Retrieve the list of anonymous forum digest options for later.
$digestoptions = anonforum_get_user_digest_options();
$digestoptions_selector = new single_select(new moodle_url('/mod/anonforum/maildigest.php',
    array(
        'backtoindex' => 1,
    )),
    'maildigest',
    $digestoptions,
    null,
    '');
$digestoptions_selector->method = 'post';

// Start of the table for General Forums

$generaltable = new html_table();
$generaltable->head  = array ($stranonforum, $strdescription, $strdiscussions);
$generaltable->align = array ('left', 'left', 'center');

if ($usetracking = anonforum_tp_can_track_anonforums()) {
    $untracked = anonforum_tp_get_untracked_anonforums($USER->id, $course->id);

    $generaltable->head[] = $strunreadposts;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $strtracking;
    $generaltable->align[] = 'center';
}

$subscribed_anonforums = anonforum_get_subscribed_anonforums($course);

$can_subscribe = is_enrolled($coursecontext);
if ($can_subscribe) {
    $generaltable->head[] = $strsubscribed;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $stremaildigest . ' ' . $OUTPUT->help_icon('emaildigesttype', 'mod_anonforum');
    $generaltable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->anonforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->anonforum_enablerssfeeds)) {
    $generaltable->head[] = $strrss;
    $generaltable->align[] = 'center';
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();

// Parse and organise all the anonforums.  Most anonymous forums are course modules but
// some special ones are not.  These get placed in the general anonymous forums
// category with the anonymous forums in section 0.

$anonforums = $DB->get_records_sql("
    SELECT f.*,
           d.maildigest
      FROM {anonforum} f
 LEFT JOIN {anonforum_digests} d ON d.anonforum = f.id AND d.userid = ?
     WHERE f.course = ?
    ", array($USER->id, $course->id));

$generalanonforums  = array();
$learninganonforums = array();
$modinfo = get_fast_modinfo($course);

foreach ($modinfo->get_instances_of('anonforum') as $anonforumid=>$cm) {
    if (!$cm->uservisible or !isset($anonforums[$anonforumid])) {
        continue;
    }

    $anonforum = $anonforums[$anonforumid];

    if (!$context = context_module::instance($cm->id, IGNORE_MISSING)) {
        continue;   // Shouldn't happen
    }

    if (!has_capability('mod/anonforum:viewdiscussion', $context)) {
        continue;
    }

    // fill two type array - order in modinfo is the same as in course
    if ($anonforum->type == 'news' or $anonforum->type == 'social') {
        $generalanonforums[$anonforum->id] = $anonforum;

    } else if ($course->id == SITEID or empty($cm->sectionnum)) {
        $generalanonforums[$anonforum->id] = $anonforum;

    } else {
        $learninganonforums[$anonforum->id] = $anonforum;
    }
}

// Do course wide subscribe/unsubscribe if requested
if (!is_null($subscribe)) {
    if (isguestuser() or !$can_subscribe) {
        // there should not be any links leading to this place, just redirect
        redirect(new moodle_url('/mod/anonforum/index.php', array('id' => $id)), get_string('subscribeenrolledonly', 'anonforum'));
    }
    // Can proceed now, the user is not guest and is enrolled
    foreach ($modinfo->get_instances_of('anonforum') as $anonforumid=>$cm) {
        $anonforum = $anonforums[$anonforumid];
        $modcontext = context_module::instance($cm->id);
        $cansub = false;

        if (has_capability('mod/anonforum:viewdiscussion', $modcontext)) {
            $cansub = true;
        }
        if ($cansub && $cm->visible == 0 &&
            !has_capability('mod/anonforum:managesubscriptions', $modcontext))
        {
            $cansub = false;
        }
        if (!anonforum_is_forcesubscribed($anonforum)) {
            $subscribed = anonforum_is_subscribed($USER->id, $anonforum);
            if ((has_capability('moodle/course:manageactivities', $coursecontext, $USER->id) || $anonforum->forcesubscribe != ANONFORUM_DISALLOWSUBSCRIBE) && $subscribe && !$subscribed && $cansub) {
                anonforum_subscribe($USER->id, $anonforumid);
            } else if (!$subscribe && $subscribed) {
                anonforum_unsubscribe($USER->id, $anonforumid);
            }
        }
    }
    $returnto = anonforum_go_back_to("index.php?id=$course->id");
    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));
    if ($subscribe) {
        add_to_log($course->id, 'anonforum', 'subscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallsubscribed', 'anonforum', $shortname), 1);
    } else {
        add_to_log($course->id, 'anonforum', 'unsubscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallunsubscribed', 'anonforum', $shortname), 1);
    }
}

/// First, let's process the general anonymous forums and build up a display

if ($generalanonforums) {
    foreach ($generalanonforums as $anonforum) {
        $cm      = $modinfo->instances['anonforum'][$anonforum->id];
        $context = context_module::instance($cm->id);

        $count = anonforum_count_discussions($anonforum, $cm, $course);

        if ($usetracking) {
            if ($anonforum->trackingtype == ANONFORUM_TRACKING_OFF) {
                $unreadlink  = '-';
                $trackedlink = '-';

            } else {
                if (isset($untracked[$anonforum->id])) {
                        $unreadlink  = '-';
                } else if ($unread = anonforum_tp_count_anonforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$anonforum->id.'">'.$unread.'</a>';
                    $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                   $anonforum->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                } else {
                    $unreadlink = '<span class="read">0</span>';
                }

                if (($anonforum->trackingtype == ANONFORUM_TRACKING_FORCED) && ($CFG->anonforum_allowforcedreadtracking)) {
                    $trackedlink = $stryes;
                } else if ($anonforum->trackingtype === ANONFORUM_TRACKING_OFF || ($USER->trackforums == 0)) {
                    $trackedlink = '-';
                } else {
                    $aurl = new moodle_url('/mod/anonforum/settracking.php', array('id'=>$anonforum->id));
                    if (!isset($untracked[$anonforum->id])) {
                        $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackanonforum));
                    } else {
                        $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackanonforum));
                    }
                }
            }
        }

        $anonforum->intro = shorten_text(format_module_intro('anonforum', $anonforum, $cm->id), $CFG->anonforum_shortpost);
        $anonforumname = format_string($anonforum->name, true);

        if ($cm->visible) {
            $style = '';
        } else {
            $style = 'class="dimmed"';
        }
        $anonforumlink = "<a href=\"view.php?f=$anonforum->id\" $style>".format_string($anonforum->name,true)."</a>";
        $discussionlink = "<a href=\"view.php?f=$anonforum->id\" $style>".$count."</a>";

        $row = array ($anonforumlink, $anonforum->intro, $discussionlink);
        if ($usetracking) {
            $row[] = $unreadlink;
            $row[] = $trackedlink;    // Tracking.
        }

        if ($can_subscribe) {
            if ($anonforum->forcesubscribe != ANONFORUM_DISALLOWSUBSCRIBE) {
                $row[] = anonforum_get_subscribe_link($anonforum, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_anonforums);
            } else {
                $row[] = '-';
            }

            $digestoptions_selector->url->param('id', $anonforum->id);
            if ($anonforum->maildigest === null) {
                $digestoptions_selector->selected = -1;
            } else {
                $digestoptions_selector->selected = $anonforum->maildigest;
            }
            $row[] = $OUTPUT->render($digestoptions_selector);
        }

        //If this anonymous forum has RSS activated, calculate it
        if ($show_rss) {
            if ($anonforum->rsstype and $anonforum->rssarticles) {
                //Calculate the tooltip text
                if ($anonforum->rsstype == 1) {
                    $tooltiptext = get_string('rsssubscriberssdiscussions', 'anonforum');
                } else {
                    $tooltiptext = get_string('rsssubscriberssposts', 'anonforum');
                }

                if (!isloggedin() && $course->id == SITEID) {
                    $userid = guest_user()->id;
                } else {
                    $userid = $USER->id;
                }
                //Get html code for RSS link
                $row[] = rss_get_link($context->id, $userid, 'mod_anonforum', $anonforum->id, $tooltiptext);
            } else {
                $row[] = '&nbsp;';
            }
        }

        $generaltable->data[] = $row;
    }
}


// Start of the table for Learning Forums
$learningtable = new html_table();
$learningtable->head  = array ($stranonforum, $strdescription, $strdiscussions);
$learningtable->align = array ('left', 'left', 'center');

if ($usetracking) {
    $learningtable->head[] = $strunreadposts;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $strtracking;
    $learningtable->align[] = 'center';
}

if ($can_subscribe) {
    $learningtable->head[] = $strsubscribed;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $stremaildigest . ' ' . $OUTPUT->help_icon('emaildigesttype', 'mod_anonforum');
    $learningtable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->anonforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->anonforum_enablerssfeeds)) {
    $learningtable->head[] = $strrss;
    $learningtable->align[] = 'center';
}

/// Now let's process the learning anonymous forums

if ($course->id != SITEID) {    // Only real courses have learning anonforums
    // 'format_.'$course->format only applicable when not SITEID (format_site is not a format)
    $strsectionname  = get_string('sectionname', 'format_'.$course->format);
    // Add extra field for section number, at the front
    array_unshift($learningtable->head, $strsectionname);
    array_unshift($learningtable->align, 'center');


    if ($learninganonforums) {
        $currentsection = '';
            foreach ($learninganonforums as $anonforum) {
            $cm      = $modinfo->instances['anonforum'][$anonforum->id];
            $context = context_module::instance($cm->id);

            $count = anonforum_count_discussions($anonforum, $cm, $course);

            if ($usetracking) {
                if ($anonforum->trackingtype == ANONFORUM_TRACKING_OFF) {
                    $unreadlink  = '-';
                    $trackedlink = '-';

                } else {
                    if (isset($untracked[$anonforum->id])) {
                        $unreadlink  = '-';
                    } else if ($unread = anonforum_tp_count_anonforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$anonforum->id.'">'.$unread.'</a>';
                        $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                       $anonforum->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/markasread') . '" alt="'.$strmarkallread.'" class="iconsmall" /></a></span>';
                    } else {
                        $unreadlink = '<span class="read">0</span>';
                    }

                    if (($anonforum->trackingtype == ANONFORUM_TRACKING_FORCED) && ($CFG->anonforum_allowforcedreadtracking)) {
                        $trackedlink = $stryes;
                    } else if ($anonforum->trackingtype === ANONFORUM_TRACKING_OFF || ($USER->trackforums == 0)) {
                        $trackedlink = '-';
                    } else {
                        $aurl = new moodle_url('/mod/anonforum/settracking.php', array('id'=>$anonforum->id));
                        if (!isset($untracked[$anonforum->id])) {
                            $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackanonforum));
                        } else {
                            $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackanonforum));
                        }
                    }
                }
            }

            $anonforum->intro = shorten_text(format_module_intro('anonforum', $anonforum, $cm->id), $CFG->anonforum_shortpost);

            if ($cm->sectionnum != $currentsection) {
                $printsection = get_section_name($course, $cm->sectionnum);
                if ($currentsection) {
                    $learningtable->data[] = 'hr';
                }
                $currentsection = $cm->sectionnum;
            } else {
                $printsection = '';
            }

            $anonforumname = format_string($anonforum->name,true);

            if ($cm->visible) {
                $style = '';
            } else {
                $style = 'class="dimmed"';
            }
            $anonforumlink = "<a href=\"view.php?f=$anonforum->id\" $style>".format_string($anonforum->name,true)."</a>";
            $discussionlink = "<a href=\"view.php?f=$anonforum->id\" $style>".$count."</a>";

            $row = array ($printsection, $anonforumlink, $anonforum->intro, $discussionlink);
            if ($usetracking) {
                $row[] = $unreadlink;
                $row[] = $trackedlink;    // Tracking.
            }

            if ($can_subscribe) {
                if ($anonforum->forcesubscribe != ANONFORUM_DISALLOWSUBSCRIBE) {
                    $row[] = anonforum_get_subscribe_link($anonforum, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_anonforums);
                } else {
                    $row[] = '-';
                }

                $digestoptions_selector->url->param('id', $anonforum->id);
                if ($anonforum->maildigest === null) {
                    $digestoptions_selector->selected = -1;
                } else {
                    $digestoptions_selector->selected = $anonforum->maildigest;
                }
                $row[] = $OUTPUT->render($digestoptions_selector);
            }

            //If this anonymous forum has RSS activated, calculate it
            if ($show_rss) {
                if ($anonforum->rsstype and $anonforum->rssarticles) {
                    //Calculate the tolltip text
                    if ($anonforum->rsstype == 1) {
                        $tooltiptext = get_string('rsssubscriberssdiscussions', 'anonforum');
                    } else {
                        $tooltiptext = get_string('rsssubscriberssposts', 'anonforum');
                    }
                    //Get html code for RSS link
                    $row[] = rss_get_link($context->id, $USER->id, 'mod_anonforum', $anonforum->id, $tooltiptext);
                } else {
                    $row[] = '&nbsp;';
                }
            }

            $learningtable->data[] = $row;
        }
    }
}


/// Output the page
$PAGE->navbar->add($stranonforums);
$PAGE->set_title("$course->shortname: $stranonforums");
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();

// Show the subscribe all options only to non-guest, enrolled users
if (!isguestuser() && isloggedin() && $can_subscribe) {
    echo $OUTPUT->box_start('subscription');
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/anonforum/index.php', array('id'=>$course->id, 'subscribe'=>1, 'sesskey'=>sesskey())),
            get_string('allsubscribe', 'anonforum')),
        array('class'=>'helplink'));
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/anonforum/index.php', array('id'=>$course->id, 'subscribe'=>0, 'sesskey'=>sesskey())),
            get_string('allunsubscribe', 'anonforum')),
        array('class'=>'helplink'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

if ($generalanonforums) {
    echo $OUTPUT->heading(get_string('generalanonforums', 'anonforum'), 2);
    echo html_writer::table($generaltable);
}

if ($learninganonforums) {
    echo $OUTPUT->heading(get_string('learninganonforums', 'anonforum'), 2);
    echo html_writer::table($learningtable);
}

echo $OUTPUT->footer();

