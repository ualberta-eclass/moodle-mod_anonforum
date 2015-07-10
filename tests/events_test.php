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
 * Tests for anonforum events.
 *
 * @package    mod_anonforum
 * @category   test
 * @copyright  2014 Dan Poltawski <dan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for anonforum events.
 *
 * @package    mod_anonforum
 * @category   test
 * @copyright  2014 Dan Poltawski <dan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
class mod_anonforum_events_testcase extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp() {
        $this->resetAfterTest();
    }

    /**
     * Ensure course_searched event validates that searchterm is set.
     */
    public function test_course_searched_searchterm_validation() {
        $course = $this->getDataGenerator()->create_course();
        $coursectx = context_course::instance($course->id);
        $params = array(
                'context' => $coursectx,
        );

        $this->setExpectedException('coding_exception', 'The \'searchterm\' value must be set in other.');
        \mod_anonforum\event\course_searched::create($params);
    }

    /**
     * Ensure course_searched event validates that context is the correct level.
     */
    public function test_course_searched_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);
        $params = array(
                'context' => $context,
                'other' => array('searchterm' => 'testing'),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_COURSE.');
        \mod_anonforum\event\course_searched::create($params);
    }

    /**
     * Test course_searched event.
     */
    public function test_course_searched() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $coursectx = context_course::instance($course->id);
        $searchterm = 'testing123';

        $params = array(
                'context' => $coursectx,
                'other' => array('searchterm' => $searchterm),
        );

        // Create event.
        $event = \mod_anonforum\event\course_searched::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\course_searched', $event);
        $this->assertEquals($coursectx, $event->get_context());
        $expected = array($course->id, 'anonforum', 'search', "search.php?id={$course->id}&amp;search={$searchterm}", $searchterm);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_created event validates that anonforumid is set.
     */
    public function test_discussion_created_anonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\discussion_created::create($params);
    }

    /**
     * Ensure discussion_created event validates that the context is the correct level.
     */
    public function test_discussion_created_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_system::instance(),
                'other' => array('anonforumid' => $anonforum->id),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\discussion_created::create($params);
    }

    /**
     * Test discussion_created event.
     */
    public function test_discussion_created() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => array('anonforumid' => $anonforum->id),
        );

        // Create the event.
        $event = \mod_anonforum\event\discussion_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Check that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\discussion_created', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'add discussion', "discuss.php?d={$discussion->id}", $discussion->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_updated event validates that anonforumid is set.
     */
    public function test_discussion_updated_anonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\discussion_updated::create($params);
    }

    /**
     * Ensure discussion_created event validates that the context is the correct level.
     */
    public function test_discussion_updated_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_system::instance(),
                'other' => array('anonforumid' => $anonforum->id),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\discussion_updated::create($params);
    }

    /**
     * Test discussion_created event.
     */
    public function test_discussion_updated() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => array('anonforumid' => $anonforum->id),
        );

        // Create the event.
        $event = \mod_anonforum\event\discussion_updated::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Check that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\discussion_updated', $event);
        $this->assertEquals($context, $event->get_context());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_deleted event validates that anonforumid is set.
     */
    public function test_discussion_deleted_anonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\discussion_deleted::create($params);
    }

    /**
     * Ensure discussion_deleted event validates that context is of the correct level.
     */
    public function test_discussion_deleted_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_system::instance(),
                'other' => array('anonforumid' => $anonforum->id),
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\discussion_deleted::create($params);
    }

    /**
     * Test discussion_deleted event.
     */
    public function test_discussion_deleted() {

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => array('anonforumid' => $anonforum->id),
        );

        $event = \mod_anonforum\event\discussion_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\discussion_deleted', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'delete discussion', "view.php?id={$anonforum->cmid}", $anonforum->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure discussion_moved event validates that fromanonforumid is set.
     */
    public function test_discussion_moved_fromanonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $toanonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $context = context_module::instance($toanonforum->cmid);

        $params = array(
                'context' => $context,
                'other' => array('toanonforumid' => $toanonforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'fromanonforumid\' value must be set in other.');
        \mod_anonforum\event\discussion_moved::create($params);
    }

    /**
     * Ensure discussion_moved event validates that toanonforumid is set.
     */
    public function test_discussion_moved_toanonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $fromanonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $toanonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($toanonforum->cmid);

        $params = array(
                'context' => $context,
                'other' => array('fromanonforumid' => $fromanonforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'toanonforumid\' value must be set in other.');
        \mod_anonforum\event\discussion_moved::create($params);
    }

    /**
     * Ensure discussion_moved event validates that the context level is correct.
     */
    public function test_discussion_moved_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $fromanonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $toanonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $fromanonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $params = array(
                'context' => context_system::instance(),
                'objectid' => $discussion->id,
                'other' => array('fromanonforumid' => $fromanonforum->id, 'toanonforumid' => $toanonforum->id)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\discussion_moved::create($params);
    }

    /**
     * Test discussion_moved event.
     */
    public function test_discussion_moved() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $fromanonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $toanonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $fromanonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $context = context_module::instance($toanonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => array('fromanonforumid' => $fromanonforum->id, 'toanonforumid' => $toanonforum->id)
        );

        $event = \mod_anonforum\event\discussion_moved::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\discussion_moved', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'move discussion', "discuss.php?d={$discussion->id}",
        $discussion->id, $toanonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }


    /**
     * Ensure discussion_viewed event validates that the contextlevel is correct.
     */
    public function test_discussion_viewed_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $params = array(
                'context' => context_system::instance(),
                'objectid' => $discussion->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\discussion_viewed::create($params);
    }

    /**
     * Test discussion_viewed event.
     */
    public function test_discussion_viewed() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
        );

        $event = \mod_anonforum\event\discussion_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\discussion_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'view discussion', "discuss.php?d={$discussion->id}",
        $discussion->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure course_module_viewed event validates that the contextlevel is correct.
     */
    public function test_course_module_viewed_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_system::instance(),
                'objectid' => $anonforum->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\course_module_viewed::create($params);
    }

    /**
     * Test the course_module_viewed event.
     */
    public function test_course_module_viewed() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $anonforum->id,
        );

        $event = \mod_anonforum\event\course_module_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\course_module_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'view anonforum', "view.php?f={$anonforum->id}", $anonforum->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/view.php', array('f' => $anonforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Ensure readtracking_enabled event validates that the anonforumid is set.
     */
    public function test_readtracking_enabled_anonforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\readtracking_enabled::create($params);
    }

    /**
     * Ensure readtracking_enabled event validates that the relateduserid is set.
     */
    public function test_readtracking_enabled_relateduserid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $anonforum->id,
        );

        $this->setExpectedException('coding_exception', 'The \'relateduserid\' must be set.');
        \mod_anonforum\event\readtracking_enabled::create($params);
    }

    /**
     * Ensure readtracking_enabled event validates that the contextlevel is correct.
     */
    public function test_readtracking_enabled_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_system::instance(),
                'other' => array('anonforumid' => $anonforum->id),
                'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\readtracking_enabled::create($params);
    }

    /**
     * Test the readtracking_enabled event.
     */
    public function test_readtracking_enabled() {
        // Setup test data.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'other' => array('anonforumid' => $anonforum->id),
                'relateduserid' => $user->id,
        );

        $event = \mod_anonforum\event\readtracking_enabled::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\readtracking_enabled', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'start tracking', "view.php?f={$anonforum->id}", $anonforum->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/view.php', array('f' => $anonforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure readtracking_disabled event validates that the anonforumid is set.
     */
    public function test_readtracking_disabled_anonforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\readtracking_disabled::create($params);
    }

    /**
     *  Ensure readtracking_disabled event validates that the relateduserid is set.
     */
    public function test_readtracking_disabled_relateduserid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $anonforum->id,
        );

        $this->setExpectedException('coding_exception', 'The \'relateduserid\' must be set.');
        \mod_anonforum\event\readtracking_disabled::create($params);
    }

    /**
     *  Ensure readtracking_disabled event validates that the contextlevel is correct
     */
    public function test_readtracking_disabled_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_system::instance(),
                'other' => array('anonforumid' => $anonforum->id),
                'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\readtracking_disabled::create($params);
    }

    /**
     *  Test the readtracking_disabled event.
     */
    public function test_readtracking_disabled() {
        // Setup test data.
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'other' => array('anonforumid' => $anonforum->id),
                'relateduserid' => $user->id,
        );

        $event = \mod_anonforum\event\readtracking_disabled::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\readtracking_disabled', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'stop tracking', "view.php?f={$anonforum->id}", $anonforum->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/view.php', array('f' => $anonforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure subscribers_viewed event validates that the anonforumid is set.
     */
    public function test_subscribers_viewed_anonforumid_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\subscribers_viewed::create($params);
    }

    /**
     *  Ensure subscribers_viewed event validates that the contextlevel is correct.
     */
    public function test_subscribers_viewed_contextlevel_validation() {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));

        $params = array(
                'context' => context_system::instance(),
                'other' => array('anonforumid' => $anonforum->id),
                'relateduserid' => $user->id,
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE.');
        \mod_anonforum\event\subscribers_viewed::create($params);
    }

    /**
     *  Test the subscribers_viewed event.
     */
    public function test_subscribers_viewed() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'other' => array('anonforumid' => $anonforum->id),
        );

        $event = \mod_anonforum\event\subscribers_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\subscribers_viewed', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'view subscribers', "subscribers.php?id={$anonforum->id}", $anonforum->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure post_created event validates that the postid is set.
     */
    public function test_post_created_postid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'other' => array('anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type, 'discussionid' => $discussion->id)
        );

        \mod_anonforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the discussionid is set.
     */
    public function test_post_created_discussionid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'discussionid\' value must be set in other.');
        \mod_anonforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the anonforumid is set.
     */
    public function test_post_created_anonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the anonforumtype is set.
     */
    public function test_post_created_anonforumtype_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumtype\' value must be set in other.');
        \mod_anonforum\event\post_created::create($params);
    }

    /**
     *  Ensure post_created event validates that the contextlevel is correct.
     */
    public function test_post_created_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_system::instance(),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE');
        \mod_anonforum\event\post_created::create($params);
    }

    /**
     * Test the post_created event.
     */
    public function test_post_created() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $event = \mod_anonforum\event\post_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\post_created', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'add post', "discuss.php?d={$discussion->id}#p{$post->id}",
        $anonforum->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/discuss.php', array('d' => $discussion->id));
        $url->set_anchor('p'.$event->objectid);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test the post_created event for a single discussion anonforum.
     */
    public function test_post_created_single() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id, 'type' => 'single'));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $event = \mod_anonforum\event\post_created::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\post_created', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'add post', "view.php?f={$anonforum->id}#p{$post->id}",
        $anonforum->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/view.php', array('f' => $anonforum->id));
        $url->set_anchor('p'.$event->objectid);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure post_deleted event validates that the postid is set.
     */
    public function test_post_deleted_postid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'other' => array('anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type, 'discussionid' => $discussion->id)
        );

        \mod_anonforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the discussionid is set.
     */
    public function test_post_deleted_discussionid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'discussionid\' value must be set in other.');
        \mod_anonforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the anonforumid is set.
     */
    public function test_post_deleted_anonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the anonforumtype is set.
     */
    public function test_post_deleted_anonforumtype_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumtype\' value must be set in other.');
        \mod_anonforum\event\post_deleted::create($params);
    }

    /**
     *  Ensure post_deleted event validates that the contextlevel is correct.
     */
    public function test_post_deleted_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_system::instance(),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE');
        \mod_anonforum\event\post_deleted::create($params);
    }

    /**
     * Test post_deleted event.
     */
    public function test_post_deleted() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $event = \mod_anonforum\event\post_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\post_deleted', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'delete post', "discuss.php?d={$discussion->id}", $post->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/discuss.php', array('d' => $discussion->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test post_deleted event for a single discussion anonforum.
     */
    public function test_post_deleted_single() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id, 'type' => 'single'));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $event = \mod_anonforum\event\post_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\post_deleted', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'delete post', "view.php?f={$anonforum->id}", $post->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/view.php', array('f' => $anonforum->id));
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     *  Ensure post_updated event validates that the discussionid is set.
     */
    public function test_post_updated_discussionid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'discussionid\' value must be set in other.');
        \mod_anonforum\event\post_updated::create($params);
    }

    /**
     *  Ensure post_updated event validates that the anonforumid is set.
     */
    public function test_post_updated_anonforumid_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumid\' value must be set in other.');
        \mod_anonforum\event\post_updated::create($params);
    }

    /**
     *  Ensure post_updated event validates that the anonforumtype is set.
     */
    public function test_post_updated_anonforumtype_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_module::instance($anonforum->cmid),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id)
        );

        $this->setExpectedException('coding_exception', 'The \'anonforumtype\' value must be set in other.');
        \mod_anonforum\event\post_updated::create($params);
    }

    /**
     *  Ensure post_updated event validates that the contextlevel is correct.
     */
    public function test_post_updated_context_validation() {
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $params = array(
                'context' => context_system::instance(),
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $this->setExpectedException('coding_exception', 'Context level must be CONTEXT_MODULE');
        \mod_anonforum\event\post_updated::create($params);
    }

    /**
     * Test post_updated event.
     */
    public function test_post_updated() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $event = \mod_anonforum\event\post_updated::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\post_updated', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'update post', "discuss.php?d={$discussion->id}#p{$post->id}",
        $post->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/discuss.php', array('d' => $discussion->id));
        $url->set_anchor('p'.$event->objectid);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test post_updated event.
     */
    public function test_post_updated_single() {
        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $anonforum = $this->getDataGenerator()->create_module('anonforum', array('course' => $course->id, 'type' => 'single'));
        $user = $this->getDataGenerator()->create_user();

        // Add a discussion.
        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $anonforum->id;
        $record['userid'] = $user->id;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_discussion($record);

        // Add a post.
        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $this->getDataGenerator()->get_plugin_generator('mod_anonforum')->create_post($record);

        $context = context_module::instance($anonforum->cmid);

        $params = array(
                'context' => $context,
                'objectid' => $post->id,
                'other' => array('discussionid' => $discussion->id, 'anonforumid' => $anonforum->id, 'anonforumtype' => $anonforum->type)
        );

        $event = \mod_anonforum\event\post_updated::create($params);

        // Trigger and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_anonforum\event\post_updated', $event);
        $this->assertEquals($context, $event->get_context());
        $expected = array($course->id, 'anonforum', 'update post', "view.php?f={$anonforum->id}#p{$post->id}",
        $post->id, $anonforum->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $url = new \moodle_url('/mod/anonforum/view.php', array('f' => $anonforum->id));
        $url->set_anchor('p'.$post->id);
        $this->assertEquals($url, $event->get_url());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());
    }

    /**
     * Test mod_anonforum_observer methods.
     */
    public function test_observers() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/anonforum/lib.php');

        $anonforumgen = $this->getDataGenerator()->get_plugin_generator('mod_anonforum');

        $course = $this->getDataGenerator()->create_course();
        $trackedrecord = array('course' => $course->id, 'type' => 'general', 'forcesubscribe' => ANONFORUM_INITIALSUBSCRIBE);
        $untrackedrecord = array('course' => $course->id, 'type' => 'general');
        $trackedanonforum = $this->getDataGenerator()->create_module('anonforum', $trackedrecord);
        $untrackedanonforum = $this->getDataGenerator()->create_module('anonforum', $untrackedrecord);

        // Used functions don't require these settings; adding
        // them just in case there are APIs changes in future.
        $user = $this->getDataGenerator()->create_user(array(
                'maildigest' => 1,
                'trackanonforums' => 1
        ));

        $manplugin = enrol_get_plugin('manual');
        $manualenrol = $DB->get_record('enrol', array('courseid' => $course->id, 'enrol' => 'manual'));
        $student = $DB->get_record('role', array('shortname' => 'student'));

        // The role_assign observer does it's job adding the anonforum_subscriptions record.
        $manplugin->enrol_user($manualenrol, $user->id, $student->id);

        // They are not required, but in a real environment they are supposed to be required;
        // adding them just in case there are APIs changes in future.
        set_config('anonforum_trackingtype', 1);
        set_config('anonforum_trackreadposts', 1);

        $record = array();
        $record['course'] = $course->id;
        $record['anonforum'] = $trackedanonforum->id;
        $record['userid'] = $user->id;
        $discussion = $anonforumgen->create_discussion($record);

        $record = array();
        $record['discussion'] = $discussion->id;
        $record['userid'] = $user->id;
        $post = $anonforumgen->create_post($record);

        anonforum_tp_add_read_record($user->id, $post->id);
        anonforum_set_user_maildigest($trackedanonforum, 2, $user);
        anonforum_tp_stop_tracking($untrackedanonforum->id, $user->id);

        $this->assertEquals(1, $DB->count_records('anonforum_subscriptions'));
        $this->assertEquals(1, $DB->count_records('anonforum_digests'));
        $this->assertEquals(1, $DB->count_records('anonforum_track_prefs'));
        $this->assertEquals(1, $DB->count_records('anonforum_read'));

        // The course_module_created observer does it's job adding a subscription.
        $anonforumrecord = array('course' => $course->id, 'type' => 'general', 'forcesubscribe' => ANONFORUM_INITIALSUBSCRIBE);
        $extraanonforum = $this->getDataGenerator()->create_module('anonforum', $anonforumrecord);
        $this->assertEquals(2, $DB->count_records('anonforum_subscriptions'));

        $manplugin->unenrol_user($manualenrol, $user->id);

        $this->assertEquals(0, $DB->count_records('anonforum_digests'));
        $this->assertEquals(0, $DB->count_records('anonforum_subscriptions'));
        $this->assertEquals(0, $DB->count_records('anonforum_track_prefs'));
        $this->assertEquals(0, $DB->count_records('anonforum_read'));
    }

}
