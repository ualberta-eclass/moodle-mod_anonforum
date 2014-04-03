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
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/anonforum/backup/moodle2/restore_anonforum_stepslib.php'); // Because it exists (must)

/**
 * anonymous forum restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_anonforum_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Choice only has one structure step
        $this->add_step(new restore_anonforum_activity_structure_step('anonforum_structure', 'anonforum.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    static public function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('anonforum', array('intro'), 'anonforum');
        $contents[] = new restore_decode_content('anonforum_posts', array('message'), 'anonforum_post');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    static public function define_decode_rules() {
        $rules = array();

        // List of anonymous forums in course
        $rules[] = new restore_decode_rule('ANONFORUMINDEX', '/mod/anonforum/index.php?id=$1', 'course');
        // Anonymous forum by cm->id and anonforum->id
        $rules[] = new restore_decode_rule('ANONFORUMVIEWBYID', '/mod/anonforum/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('ANONFORUMVIEWBYF', '/mod/anonforum/view.php?f=$1', 'anonforum');
        // Link to anonymous forum discussion
        $rules[] = new restore_decode_rule('ANONFORUMDISCUSSIONVIEW', '/mod/anonforum/discuss.php?d=$1', 'anonforum_discussion');
        // Link to discussion with parent and with anchor posts
        $rules[] = new restore_decode_rule('ANONFORUMDISCUSSIONVIEWPARENT', '/mod/anonforum/discuss.php?d=$1&parent=$2',
                                           array('anonforum_discussion', 'anonforum_post'));
        $rules[] = new restore_decode_rule('ANONFORUMDISCUSSIONVIEWINSIDE', '/mod/anonforum/discuss.php?d=$1#$2',
                                           array('anonforum_discussion', 'anonforum_post'));

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * forum logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    static public function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('anonforum', 'add', 'view.php?id={course_module}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'update', 'view.php?id={course_module}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'view', 'view.php?id={course_module}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'view anonymous forum', 'view.php?id={course_module}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'mark read', 'view.php?f={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'start tracking', 'view.php?f={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'stop tracking', 'view.php?f={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'subscribe', 'view.php?f={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'unsubscribe', 'view.php?f={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'subscriber', 'subscribers.php?id={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'subscribers', 'subscribers.php?id={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'view subscribers', 'subscribers.php?id={anonforum}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'add discussion', 'discuss.php?d={anonforum_discussion}', '{anonforum_discussion}');
        $rules[] = new restore_log_rule('anonforum', 'view discussion', 'discuss.php?d={anonforum_discussion}', '{anonforum_discussion}');
        $rules[] = new restore_log_rule('anonforum', 'move discussion', 'discuss.php?d={anonforum_discussion}', '{anonforum_discussion}');
        $rules[] = new restore_log_rule('anonforum', 'delete discussi', 'view.php?id={course_module}', '{anonforum}',
                                        null, 'delete discussion');
        $rules[] = new restore_log_rule('anonforum', 'delete discussion', 'view.php?id={course_module}', '{anonforum}');
        $rules[] = new restore_log_rule('anonforum', 'add post', 'discuss.php?d={anonforum_discussion}&parent={anonforum_post}', '{anonforum_post}');
        $rules[] = new restore_log_rule('anonforum', 'update post', 'discuss.php?d={anonforum_discussion}#p{anonforum_post}&parent={anonforum_post}', '{anonforum_post}');
        $rules[] = new restore_log_rule('anonforum', 'update post', 'discuss.php?d={anonforum_discussion}&parent={anonforum_post}', '{anonforum_post}');
        $rules[] = new restore_log_rule('anonforum', 'prune post', 'discuss.php?d={anonforum_discussion}', '{anonforum_post}');
        $rules[] = new restore_log_rule('anonforum', 'delete post', 'discuss.php?d={anonforum_discussion}', '[post]');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    static public function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('anonforum', 'view anonymous forums', 'index.php?id={course}', null);
        $rules[] = new restore_log_rule('anonforum', 'subscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('anonforum', 'unsubscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('anonforum', 'user report', 'user.php?course={course}&id={user}&mode=[mode]', '{user}');
        $rules[] = new restore_log_rule('anonforum', 'search', 'search.php?id={course}&search=[searchenc]', '[search]');

        return $rules;
    }
}
