@mod @mod_anonforum @mod_anonforum_add_forum
Feature: Add anonymous forum activities and discussions
  In order to discuss topics with other users
  As a teacher
  I need to add anonymous forum activities to moodle courses

  @javascript
  Scenario: Add a anonymous forum and a discussion
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@asd.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
    And I log in as "teacher1"
    And I follow "Course 1"
    And I turn editing mode on
    And I add a "Anonymous forum" to section "1" and I fill the form with:
      | Anonymous forum name | Test anonymous forum name |
      | Anonymous forum type | Standard anonymous forum for general use |
      | Description | Test anonymous forum description |
    When I add a new discussion to "Test anonymous forum name" anonymous forum with:
      | Subject | Anonymous forum post 1 |
      | Message | This is the body |
    Then I should see "Test anonymous forum name"
