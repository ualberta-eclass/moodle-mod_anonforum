@mod @mod_anonforum
Feature: Set a certain number of discussions as a completion condition for a anonymous forum
  In order to ensure students are participating on anonymous forums
  As a teacher
  I need to set a minimum number of discussions to mark the anonymous forum activity as completed

  @javascript
  Scenario: Set X number of discussions as a condition
    Given the following "users" exists:
      | username | firstname | lastname | email |
      | student1 | Student | 1 | student1@asd.com |
      | teacher1 | Teacher | 1 | teacher1@asd.com |
    And the following "courses" exists:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exists:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And I log in as "admin"
    And I set the following administration settings values:
      | Enable completion tracking | 1 |
      | Enable conditional access | 1 |
    And I log out
    And I log in as "teacher1"
    And I follow "Course 1"
    And I turn editing mode on
    And I click on "Edit settings" "link" in the "Administration" "block"
    And I fill the moodle form with:
      | Enable completion tracking | Yes |
    And I press "Save changes"
    When I add a "Anonymous forum" to section "1" and I fill the form with:
      | Anonymous forum name | Test Anonymous forum name |
      | Description | Test anonymous forum description |
      | Completion tracking | Show activity as complete when conditions are met |
      | completiondiscussionsenabled | 1 |
      | completiondiscussions | 2 |
    And I log out
    And I log in as "student1"
    And I follow "Course 1"
    Then I hover "//li[contains(concat(' ', normalize-space(@class), ' '), ' modtype_anonforum ')]/descendant::img[@alt='Not completed: Test anonymous forum name']" "xpath_element"
    And I add a new discussion to "Test anonymous anonymous forum name" anonymous forum with:
      | Subject | Post 1 subject |
      | Message | Body 1 content |
    And I add a new discussion to "Test anonymous forum name" anonymous forum with:
      | Subject | Post 2 subject |
      | Message | Body 2 content |
    And I follow "Course 1"
    And I hover "//li[contains(concat(' ', normalize-space(@class), ' '), ' modtype_anonforum ')]/descendant::img[contains(@alt, 'Completed: Test anonymous forum name')]" "xpath_element"
    And I log out
    And I log in as "teacher1"
    And I follow "Course 1"
    And "Student 1" user has completed "Test anonymous forum name" activity
