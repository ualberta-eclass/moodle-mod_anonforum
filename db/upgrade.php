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
 * This file keeps track of upgrades to
 * the anonymous forum module
 *
 * Sometimes, changes between versions involve
 * alterations to database structures and other
 * major things that may break installations.
 *
 * The upgrade function in this file will attempt
 * to perform all the necessary actions to upgrade
 * your older installation to the current version.
 *
 * If there's something it cannot do itself, it
 * will tell you what you need to do.
 *
 * The commands in here will all be database-neutral,
 * using the methods of database_manager class
 *
 * Please do not forget to use upgrade_set_timeout()
 * before any action that may take longer time to finish.
 *
 * @package mod-anonforum
 * @copyright 2003 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_anonforum_upgrade($oldversion) {
    global $CFG, $DB, $OUTPUT;

    $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

    // Moodle v2.2.0 release upgrade line.
    // Put any upgrade step following this.

    // Moodle v2.3.0 release upgrade line.
    // Put any upgrade step following this.

    // Moodle v2.4.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2013020500) {

        // Define field displaywordcount to be added to anonymous forum.
        $table = new xmldb_table('anonforum');
        $field = new xmldb_field('displaywordcount', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'completionposts');

        // Conditionally launch add field displaywordcount.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Anonymous forum savepoint reached.
        upgrade_mod_savepoint(true, 2013020500, 'anonforum');
    }

    // Forcefully assign mod/anonforum:allowforcesubscribe to frontpage role, as we missed that when
    // capability was introduced.
    if ($oldversion < 2013021200) {
        // If capability mod/anonforum:allowforcesubscribe is defined then set it for frontpage role.
        if (get_capability_info('mod/anonforum:allowforcesubscribe')) {
            assign_legacy_capabilities('mod/anonforum:allowforcesubscribe', array('frontpage' => CAP_ALLOW));
        }
        // Anonymous forum savepoint reached.
        upgrade_mod_savepoint(true, 2013021200, 'anonforum');
    }


    // Moodle v2.5.0 release upgrade line.
    // Put any upgrade step following this.
    if ($oldversion < 2013071000) {
        // Define table anonforum_digests to be created.
        $table = new xmldb_table('anonforum_digests');

        // Adding fields to table anonforum_digests.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('anonforum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('maildigest', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '-1');

        // Adding keys to table anonforum_digests.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('userid', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));
        $table->add_key('anonforum', XMLDB_KEY_FOREIGN, array('anonforum'), 'anonforum', array('id'));
        $table->add_key('anonforumdigest', XMLDB_KEY_UNIQUE, array('anonforum', 'userid', 'maildigest'));

        // Conditionally launch create table for anonforum_digests.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Anonymous forum savepoint reached.
        upgrade_mod_savepoint(true, 2013071000, 'anonforum');
    }

    if ($oldversion < 2013081402) {

        // Define field anonymous to be added to anonymous forum
        $table = new xmldb_table('anonforum');
        $field = new xmldb_field('anonymous', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'displaywordcount');
        $tablepost = new xmldb_table('anonforum_posts');
        $fieldpost = new xmldb_field('anonymouspost', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'mailnow');

        // Conditionally launch add field anonymous
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Conditionally launch add field anonymous
        if (!$dbman->field_exists($tablepost, $fieldpost)) {
            $dbman->add_field($tablepost, $fieldpost);
        }

        // Anonymous forum savepoint reached
        upgrade_mod_savepoint(true, 2013081402, 'anonforum');
    }

    // Moodle v2.6.0 release upgrade line.
    // Put any upgrade step following this.

    return true;
}
