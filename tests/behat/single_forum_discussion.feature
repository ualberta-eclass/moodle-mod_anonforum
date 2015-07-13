@mod @mod_anonforum
Feature: Single simple anonymous forum discussion type
  In order to restrict the discussion topic to one
  As a teacher
  I need to create a anonymous forum with a single simple discussion

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@asd.com |
      | student1 | Student | 1 | student1@asd.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And I log in as "teacher1"
    And I follow "Course 1"
    And I turn editing mode on
    And I add a "Anonymous forum" to section "1" and I fill the form with:
      | Anonymous forum name | Single discussion anonymous forum name |
      | Anonymous forum type | A single simple discussion |
      | Description | Single discussion anonymous forum description |

  @javascript
  Scenario: Teacher can start the single simple discussion
    When I follow "Single discussion anonymous forum name"
    Then I should see "Single discussion anonymous forum description" in the "div.firstpost.starter" "css_element"
    And I should not see "Add a new discussion topic"

  @javascript
  Scenario: Student can not add more discussions
    Given I log out
    And I log in as "student1"
    And I follow "Course 1"
    When I reply "Single discussion anonymous forum name" post from "Single discussion anonymous forum name" anonymous forum with:
      | Subject | Reply to single discussion subject |
      | Message | Reply to single discussion message |
    Then I should not see "Add a new discussion topic"
    And I should see "Reply" in the "div.firstpost.starter" "css_element"
    And I should see "Reply to single discussion message"
