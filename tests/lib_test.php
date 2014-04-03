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
 * The module forums tests
 *
 * @package    mod_anonforum
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class mod_anonforum_lib_testcase extends advanced_testcase {

    public function test_anonforum_trigger_content_uploaded_event() {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);

        $this->setUser($user->id);
        $fakepost = (object) array('id' => 123, 'message' => 'Yay!', 'discussion' => 100);
        $cm = get_coursemodule_from_instance('anonforum', $anonforum->id);

        $fs = get_file_storage();
        $dummy = (object) array(
            'contextid' => $context->id,
            'component' => 'mod_anonforum',
            'filearea' => 'attachment',
            'itemid' => $fakepost->id,
            'filepath' => '/',
            'filename' => 'myassignmnent.pdf'
        );
        $fi = $fs->create_file_from_string($dummy, 'Content of ' . $dummy->filename);

        $data = new stdClass();
        $sink = $this->redirectEvents();
        forum_trigger_content_uploaded_event($fakepost, $cm, 'some triggered from value');
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf('\mod_anonforum\event\assessable_uploaded', $event);
        $this->assertEquals($context->id, $event->contextid);
        $this->assertEquals($fakepost->id, $event->objectid);
        $this->assertEquals($fakepost->message, $event->other['content']);
        $this->assertEquals($fakepost->discussion, $event->other['discussionid']);
        $this->assertCount(1, $event->other['pathnamehashes']);
        $this->assertEquals($fi->get_pathnamehash(), $event->other['pathnamehashes'][0]);
        $expected = new stdClass();
        $expected->modulename = 'anonforum';
        $expected->name = 'some triggered from value';
        $expected->cmid = $anonforum->cmid;
        $expected->itemid = $fakepost->id;
        $expected->courseid = $course->id;
        $expected->userid = $user->id;
        $expected->content = $fakepost->message;
        $expected->pathnamehashes = array($fi->get_pathnamehash());
        $this->assertEventLegacyData($expected, $event);
    }

    public function test_anonforum_get_courses_user_posted_in() {
        $this->resetAfterTest();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $course3 = $this->getDataGenerator()->create_course();

        // Create 3 forums, one in each course.
        $record = new stdClass();
        $record->course = $course1->id;
        $anonforum1 = $this->getDataGenerator()->create_module('anonforum', $record);

        $record = new stdClass();
        $record->course = $course2->id;
        $anonforum2 = $this->getDataGenerator()->create_module('anonforum', $record);

        $record = new stdClass();
        $record->course = $course3->id;
        $anonforum3 = $this->getDataGenerator()->create_module('anonforum', $record);

        // Add a second forum in course 1.
        $record = new stdClass();
        $record->course = $course1->id;
        $anonforum4 = $this->getDataGenerator()->create_module('anonforum', $record);

        // Add discussions to course 1 started by user1.
        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->anonforum = $anonforum1->id;
        $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $record = new stdClass();
        $record->course = $course1->id;
        $record->userid = $user1->id;
        $record->anonforum = $anonforum4->id;
        $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add discussions to course2 started by user1.
        $record = new stdClass();
        $record->course = $course2->id;
        $record->userid = $user1->id;
        $record->anonforum = $anonforum2->id;
        $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add discussions to course 3 started by user2.
        $record = new stdClass();
        $record->course = $course3->id;
        $record->userid = $user2->id;
        $record->anonforum = $anonforum3->id;
        $discussion3 = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add post to course 3 by user1.
        $record = new stdClass();
        $record->course = $course3->id;
        $record->userid = $user1->id;
        $record->anonforum = $anonforum3->id;
        $record->discussion = $discussion3->id;
        $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        // User 3 hasn't posted anything, so shouldn't get any results.
        $user3courses = forum_get_courses_user_posted_in($user3);
        $this->assertEmpty($user3courses);

        // User 2 has only posted in course3.
        $user2courses = forum_get_courses_user_posted_in($user2);
        $this->assertCount(1, $user2courses);
        $user2course = array_shift($user2courses);
        $this->assertEquals($course3->id, $user2course->id);
        $this->assertEquals($course3->shortname, $user2course->shortname);

        // User 1 has posted in all 3 courses.
        $user1courses = forum_get_courses_user_posted_in($user1);
        $this->assertCount(3, $user1courses);
        foreach ($user1courses as $course) {
            $this->assertContains($course->id, array($course1->id, $course2->id, $course3->id));
            $this->assertContains($course->shortname, array($course1->shortname, $course2->shortname,
                $course3->shortname));

        }

        // User 1 has only started a discussion in course 1 and 2 though.
        $user1courses = forum_get_courses_user_posted_in($user1, true);
        $this->assertCount(2, $user1courses);
        foreach ($user1courses as $course) {
            $this->assertContains($course->id, array($course1->id, $course2->id));
            $this->assertContains($course->shortname, array($course1->shortname, $course2->shortname));
        }
    }

    /**
     * Test user_enrolment_deleted observer.
     */
    public function test_user_enrolment_deleted_observer() {
        global $DB;

        $this->resetAfterTest();

        $metaplugin = enrol_get_plugin('meta');
        $user1 = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $student = $DB->get_record('role', array('shortname' => 'student'));

        $e1 = $metaplugin->add_instance($course2, array('customint1' => $course1->id));
        $enrol1 = $DB->get_record('enrol', array('id' => $e1));

        // Enrol user.
        $metaplugin->enrol_user($enrol1, $user1->id, $student->id);
        $this->assertEquals(1, $DB->count_records('user_enrolments'));

        // Unenrol user and capture event.
        $sink = $this->redirectEvents();
        $metaplugin->unenrol_user($enrol1, $user1->id);
        $events = $sink->get_events();
        $sink->close();
        $event = array_pop($events);

        $this->assertEquals(0, $DB->count_records('user_enrolments'));
        $this->assertInstanceOf('\core\event\user_enrolment_deleted', $event);
        $this->assertEquals('user_unenrolled', $event->get_legacy_eventname());
    }

    /**
     * Test the logic in the forum_tp_can_track_anonforums() function.
     */
    public function test_anonforum_tp_can_track_anonforums() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OFF); // Off.
        $anonforumoff = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_FORCED); // On.
        $anonforumforce = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OPTIONAL); // Optional.
        $anonforumoptional = $this->getDataGenerator()->create_module('anonforum', $options);

        // Allow force.
        $CFG->anonforum_allowforcedreadtracking = 1;

        // User on, forum off, should be off.
        $result = forum_tp_can_track_anonforums($anonforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum on, should be on.
        $result = forum_tp_can_track_anonforums($anonforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_can_track_anonforums($anonforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_can_track_anonforums($anonforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be on.
        $result = forum_tp_can_track_anonforums($anonforumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_can_track_anonforums($anonforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Don't allow force.
        $CFG->anonforum_allowforcedreadtracking = 0;

        // User on, forum off, should be off.
        $result = forum_tp_can_track_anonforums($anonforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum on, should be on.
        $result = forum_tp_can_track_anonforums($anonforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_can_track_anonforums($anonforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_can_track_anonforums($anonforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be off.
        $result = forum_tp_can_track_anonforums($anonforumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_can_track_anonforums($anonforumoptional, $useroff);
        $this->assertEquals(false, $result);

    }

    /**
     * Test the logic in the test_anonforum_tp_is_tracked() function.
     */
    public function test_anonforum_tp_is_tracked() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OFF); // Off.
        $anonforumoff = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_FORCED); // On.
        $anonforumforce = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OPTIONAL); // Optional.
        $anonforumoptional = $this->getDataGenerator()->create_module('anonforum', $options);

        // Allow force.
        $CFG->anonforum_allowforcedreadtracking = 1;

        // User on, forum off, should be off.
        $result = forum_tp_is_tracked($anonforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum force, should be on.
        $result = forum_tp_is_tracked($anonforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_is_tracked($anonforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_is_tracked($anonforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be on.
        $result = forum_tp_is_tracked($anonforumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_is_tracked($anonforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Don't allow force.
        $CFG->anonforum_allowforcedreadtracking = 0;

        // User on, forum off, should be off.
        $result = forum_tp_is_tracked($anonforumoff, $useron);
        $this->assertEquals(false, $result);

        // User on, forum force, should be on.
        $result = forum_tp_is_tracked($anonforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, forum optional, should be on.
        $result = forum_tp_is_tracked($anonforumoptional, $useron);
        $this->assertEquals(true, $result);

        // User off, forum off, should be off.
        $result = forum_tp_is_tracked($anonforumoff, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum force, should be off.
        $result = forum_tp_is_tracked($anonforumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, forum optional, should be off.
        $result = forum_tp_is_tracked($anonforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Stop tracking so we can test again.
        forum_tp_stop_tracking($anonforumforce->id, $useron->id);
        forum_tp_stop_tracking($anonforumoptional->id, $useron->id);
        forum_tp_stop_tracking($anonforumforce->id, $useroff->id);
        forum_tp_stop_tracking($anonforumoptional->id, $useroff->id);

        // Allow force.
        $CFG->anonforum_allowforcedreadtracking = 1;

        // User on, preference off, forum force, should be on.
        $result = forum_tp_is_tracked($anonforumforce, $useron);
        $this->assertEquals(true, $result);

        // User on, preference off, forum optional, should be on.
        $result = forum_tp_is_tracked($anonforumoptional, $useron);
        $this->assertEquals(false, $result);

        // User off, preference off, forum force, should be on.
        $result = forum_tp_is_tracked($anonforumforce, $useroff);
        $this->assertEquals(true, $result);

        // User off, preference off, forum optional, should be off.
        $result = forum_tp_is_tracked($anonforumoptional, $useroff);
        $this->assertEquals(false, $result);

        // Don't allow force.
        $CFG->anonforum_allowforcedreadtracking = 0;

        // User on, preference off, forum force, should be on.
        $result = forum_tp_is_tracked($anonforumforce, $useron);
        $this->assertEquals(false, $result);

        // User on, preference off, forum optional, should be on.
        $result = forum_tp_is_tracked($anonforumoptional, $useron);
        $this->assertEquals(false, $result);

        // User off, preference off, forum force, should be off.
        $result = forum_tp_is_tracked($anonforumforce, $useroff);
        $this->assertEquals(false, $result);

        // User off, preference off, forum optional, should be off.
        $result = forum_tp_is_tracked($anonforumoptional, $useroff);
        $this->assertEquals(false, $result);
    }

    /**
     * Test the logic in the forum_tp_get_course_unread_posts() function.
     */
    public function test_anonforum_tp_get_course_unread_posts() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OFF); // Off.
        $anonforumoff = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_FORCED); // On.
        $anonforumforce = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OPTIONAL); // Optional.
        $anonforumoptional = $this->getDataGenerator()->create_module('anonforum', $options);

        // Add discussions to the tracking off forum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->anonforum = $anonforumoff->id;
        $discussionoff = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add discussions to the tracking forced forum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->anonforum = $anonforumforce->id;
        $discussionforce = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add post to the tracking forced discussion.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useroff->id;
        $record->anonforum = $anonforumforce->id;
        $record->discussion = $discussionforce->id;
        $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        // Add discussions to the tracking optional forum.
        $record = new stdClass();
        $record->course = $course->id;
        $record->userid = $useron->id;
        $record->anonforum = $anonforumoptional->id;
        $discussionoptional = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Allow force.
        $CFG->anonforum_allowforcedreadtracking = 1;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));
        $this->assertEquals(2, $result[$anonforumforce->id]->unread);
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));
        $this->assertEquals(1, $result[$anonforumoptional->id]->unread);

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));
        $this->assertEquals(2, $result[$anonforumforce->id]->unread);
        $this->assertEquals(false, isset($result[$anonforumoptional->id]));

        // Don't allow force.
        $CFG->anonforum_allowforcedreadtracking = 0;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));
        $this->assertEquals(2, $result[$anonforumforce->id]->unread);
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));
        $this->assertEquals(1, $result[$anonforumoptional->id]->unread);

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(false, isset($result[$anonforumforce->id]));
        $this->assertEquals(false, isset($result[$anonforumoptional->id]));

        // Stop tracking so we can test again.
        forum_tp_stop_tracking($anonforumforce->id, $useron->id);
        forum_tp_stop_tracking($anonforumoptional->id, $useron->id);
        forum_tp_stop_tracking($anonforumforce->id, $useroff->id);
        forum_tp_stop_tracking($anonforumoptional->id, $useroff->id);

        // Allow force.
        $CFG->anonforum_allowforcedreadtracking = 1;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));
        $this->assertEquals(2, $result[$anonforumforce->id]->unread);
        $this->assertEquals(false, isset($result[$anonforumoptional->id]));

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));
        $this->assertEquals(2, $result[$anonforumforce->id]->unread);
        $this->assertEquals(false, isset($result[$anonforumoptional->id]));

        // Don't allow force.
        $CFG->anonforum_allowforcedreadtracking = 0;

        $result = forum_tp_get_course_unread_posts($useron->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(false, isset($result[$anonforumforce->id]));
        $this->assertEquals(false, isset($result[$anonforumoptional->id]));

        $result = forum_tp_get_course_unread_posts($useroff->id, $course->id);
        $this->assertEquals(0, count($result));
        $this->assertEquals(false, isset($result[$anonforumoff->id]));
        $this->assertEquals(false, isset($result[$anonforumforce->id]));
        $this->assertEquals(false, isset($result[$anonforumoptional->id]));
    }

    /**
     * Test the logic in the test_anonforum_tp_get_untracked_anonforums() function.
     */
    public function test_anonforum_tp_get_untracked_anonforums() {
        global $CFG;

        $this->resetAfterTest();

        $useron = $this->getDataGenerator()->create_user(array('trackforums' => 1));
        $useroff = $this->getDataGenerator()->create_user(array('trackforums' => 0));
        $course = $this->getDataGenerator()->create_course();
        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OFF); // Off.
        $anonforumoff = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_FORCED); // On.
        $anonforumforce = $this->getDataGenerator()->create_module('anonforum', $options);

        $options = array('course' => $course->id, 'trackingtype' => FORUM_TRACKING_OPTIONAL); // Optional.
        $anonforumoptional = $this->getDataGenerator()->create_module('anonforum', $options);

        // Allow force.
        $CFG->anonforum_allowforcedreadtracking = 1;

        // On user with force on.
        $result = forum_tp_get_untracked_anonforums($useron->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));

        // Off user with force on.
        $result = forum_tp_get_untracked_anonforums($useroff->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));

        // Don't allow force.
        $CFG->anonforum_allowforcedreadtracking = 0;

        // On user with force off.
        $result = forum_tp_get_untracked_anonforums($useron->id, $course->id);
        $this->assertEquals(1, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));

        // Off user with force off.
        $result = forum_tp_get_untracked_anonforums($useroff->id, $course->id);
        $this->assertEquals(3, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));

        // Stop tracking so we can test again.
        forum_tp_stop_tracking($anonforumforce->id, $useron->id);
        forum_tp_stop_tracking($anonforumoptional->id, $useron->id);
        forum_tp_stop_tracking($anonforumforce->id, $useroff->id);
        forum_tp_stop_tracking($anonforumoptional->id, $useroff->id);

        // Allow force.
        $CFG->anonforum_allowforcedreadtracking = 1;

        // On user with force on.
        $result = forum_tp_get_untracked_anonforums($useron->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));

        // Off user with force on.
        $result = forum_tp_get_untracked_anonforums($useroff->id, $course->id);
        $this->assertEquals(2, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));

        // Don't allow force.
        $CFG->anonforum_allowforcedreadtracking = 0;

        // On user with force off.
        $result = forum_tp_get_untracked_anonforums($useron->id, $course->id);
        $this->assertEquals(3, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));

        // Off user with force off.
        $result = forum_tp_get_untracked_anonforums($useroff->id, $course->id);
        $this->assertEquals(3, count($result));
        $this->assertEquals(true, isset($result[$anonforumoff->id]));
        $this->assertEquals(true, isset($result[$anonforumoptional->id]));
        $this->assertEquals(true, isset($result[$anonforumforce->id]));
    }
}
