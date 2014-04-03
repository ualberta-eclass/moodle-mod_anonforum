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
 * Event observers used in anonymous forum.
 *
 * @package    mod_anonforum
 * @copyright  2013 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for mod_anonforum.
 */
class mod_anonforum_observer {

    /**
     * Triggered via user_enrolment_deleted event.
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        global $DB;

        // NOTE: this has to be as fast as possible.
        // Get user enrolment info from event.
        $cp = (object)$event->other['userenrolment'];
        if ($cp->lastenrol) {
            $params = array('userid' => $cp->userid, 'courseid' => $cp->courseid);
            $anonforumselect = "IN (SELECT f.id FROM {anonforum} f WHERE f.course = :courseid)";

            $DB->delete_records_select('anonforum_digests', 'userid = :userid AND anonforum '.$anonforumselect, $params);
            $DB->delete_records_select('anonforum_subscriptions', 'userid = :userid AND anonforum '.$anonforumselect, $params);
            $DB->delete_records_select('anonforum_track_prefs', 'userid = :userid AND anonforumid '.$anonforumselect, $params);
            $DB->delete_records_select('anonforum_read', 'userid = :userid AND anonforumid '.$anonforumselect, $params);
        }
    }

    /**
     * Observer for role_assigned event.
     *
     * @param \core\event\role_assigned $event
     * @return void
     */
    public static function role_assigned(\core\event\role_assigned $event) {
        global $CFG, $DB;

        $context = context::instance_by_id($event->contextid, MUST_EXIST);

        // If contextlevel is course then only subscribe user. Role assignment
        // at course level means user is enroled in course and can subscribe to anonymous forum.
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        // Anonymous Forum lib required for the constant used below.
        require_once($CFG->dirroot . '/mod/anonforum/lib.php');

        $userid = $event->relateduserid;
        $sql = "SELECT f.id, cm.id AS cmid
                  FROM {anonforum} f
                  JOIN {course_modules} cm ON (cm.instance = f.id)
                  JOIN {modules} m ON (m.id = cm.module)
             LEFT JOIN {anonforum_subscriptions} fs ON (fs.anonforum = f.id AND fs.userid = :userid)
                 WHERE f.course = :courseid
                   AND f.forcesubscribe = :initial
                   AND m.name = 'anonforum'
                   AND fs.id IS NULL";
        $params = array('courseid' => $context->instanceid, 'userid' => $userid, 'initial' => ANONFORUM_INITIALSUBSCRIBE);

        $anonforums = $DB->get_records_sql($sql, $params);
        foreach ($anonforums as $anonforum) {
            // If user doesn't have allowforcesubscribe capability then don't subscribe.
            if (has_capability('mod/anonforum:allowforcesubscribe', context_module::instance($anonforum->cmid), $userid)) {
                anonforum_subscribe($userid, $anonforum->id);
            }
        }
    }
}
