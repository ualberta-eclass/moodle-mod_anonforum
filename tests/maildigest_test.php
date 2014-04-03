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
 * The module forums external functions unit tests
 *
 * @package    mod_anonforum
 * @category   external
 * @copyright  2013 Andrew Nicols
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

class mod_anonforum_maildigest_testcase extends advanced_testcase {

    public function test_mod_anonforum_set_maildigest() {
        global $USER, $DB;

        $this->resetAfterTest(true);

        // Create a user.
        $user = self::getDataGenerator()->create_user();

        // Set to the user.
        self::setUser($user);

        // Create courses to add the modules.
        $course1 = self::getDataGenerator()->create_course();

        $anonforumids = array();

        // First forum.
        $record = new stdClass();
        $record->introformat = FORMAT_HTML;
        $record->course = $course1->id;
        $anonforum1 = self::getDataGenerator()->create_module('anonforum', $record);
        $anonforumids[] = $anonforum1->id;

        // Check the forum was correctly created.
        list ($test, $params) = $DB->get_in_or_equal($anonforumids, SQL_PARAMS_NAMED, 'anonforum');

        $this->assertEquals(count($anonforumids),
            $DB->count_records_select('anonforum', 'id ' . $test, $params));

        // Enrol the user in the courses.
        // DataGenerator->enrol_user automatically sets a role for the user
        $this->getDataGenerator()->enrol_user($user->id, $course1->id, null, 'manual');

        // Confirm that there is no current value.
        $currentsetting = $DB->get_record('anonforum_digests', array(
            'anonforum' => $anonforum1->id,
            'userid' => $user->id,
        ));
        $this->assertFalse($currentsetting);

        // Test with each of the valid values:
        // 0, 1, and 2 are valid values.
        forum_set_user_maildigest($anonforum1, 0, $user);
        $currentsetting = $DB->get_record('anonforum_digests', array(
            'anonforum' => $anonforum1->id,
            'userid' => $user->id,
        ));
        $this->assertEquals($currentsetting->maildigest, 0);

        forum_set_user_maildigest($anonforum1, 1, $user);
        $currentsetting = $DB->get_record('anonforum_digests', array(
            'anonforum' => $anonforum1->id,
            'userid' => $user->id,
        ));
        $this->assertEquals($currentsetting->maildigest, 1);

        forum_set_user_maildigest($anonforum1, 2, $user);
        $currentsetting = $DB->get_record('anonforum_digests', array(
            'anonforum' => $anonforum1->id,
            'userid' => $user->id,
        ));
        $this->assertEquals($currentsetting->maildigest, 2);

        // And the default value - this should delete the record again
        forum_set_user_maildigest($anonforum1, -1, $user);
        $currentsetting = $DB->get_record('anonforum_digests', array(
            'anonforum' => $anonforum1->id,
            'userid' => $user->id,
        ));
        $this->assertFalse($currentsetting);

        // Try with an invalid value.
        $this->setExpectedException('moodle_exception');
        forum_set_user_maildigest($anonforum1, 42, $user);
    }

    public function test_mod_anonforum_get_user_digest_options_default() {
        global $USER, $DB;

        $this->resetAfterTest(true);

        // Create a user.
        $user = self::getDataGenerator()->create_user();

        // Set to the user.
        self::setUser($user);

        // We test against these options.
        $digestoptions = array(
            '0' => get_string('emaildigestoffshort', 'mod_anonforum'),
            '1' => get_string('emaildigestcompleteshort', 'mod_anonforum'),
            '2' => get_string('emaildigestsubjectsshort', 'mod_anonforum'),
        );

        // The default settings is 0.
        $this->assertEquals(0, $user->maildigest);
        $options = forum_get_user_digest_options();
        $this->assertEquals($options[-1], get_string('emaildigestdefault', 'mod_anonforum', $digestoptions[0]));

        // Update the setting to 1.
        $USER->maildigest = 1;
        $this->assertEquals(1, $USER->maildigest);
        $options = forum_get_user_digest_options();
        $this->assertEquals($options[-1], get_string('emaildigestdefault', 'mod_anonforum', $digestoptions[1]));

        // Update the setting to 2.
        $USER->maildigest = 2;
        $this->assertEquals(2, $USER->maildigest);
        $options = forum_get_user_digest_options();
        $this->assertEquals($options[-1], get_string('emaildigestdefault', 'mod_anonforum', $digestoptions[2]));
    }

    public function test_mod_anonforum_get_user_digest_options_sorting() {
        global $USER, $DB;

        $this->resetAfterTest(true);

        // Create a user.
        $user = self::getDataGenerator()->create_user();

        // Set to the user.
        self::setUser($user);

        // Retrieve the list of applicable options.
        $options = forum_get_user_digest_options();

        // The default option must always be at the top of the list.
        $lastoption = -2;
        foreach ($options as $value => $description) {
            $this->assertGreaterThan($lastoption, $value);
            $lastoption = $value;
        }
    }

}
