@mod @mod_anonforum @mod_anonforum_edit_post_student
Feature: Students can edit or delete their anonymous forum posts within a set time limit
  In order to refine anonymous forum posts
  As a user
  I need to edit or delete my anonymous forum posts within a certain period of time after posting

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | student1 | Student | 1 | student1@asd.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | student1 | C1 | student |
    And I log in as "admin"
    And I expand "Site administration" node
    And I expand "Security" node
    And I follow "Site policies"
    And I set the field "Maximum time to edit posts" to "1 minutes"
    And I press "Save changes"
    And I am on homepage
    And I follow "Course 1"
    And I turn editing mode on
    And I add a "Anonymous forum" to section "1" and I fill the form with:
      | Anonymous forum name | Test anonymous forum name |
      | Anonymous forum type | Standard anonymous forum for general use |
      | Description | Test anonymous forum description |
    And I log out
    And I follow "Course 1"
    And I log in as "student1"
    And I add a new discussion to "Test anonymous forum name" anonymous forum with:
      | Subject | Anonymous forum post subject |
      | Message | This is the body |

  Scenario: Edit anonymous forum post
    When I follow "Anonymous forum post subject"
    And I follow "Edit"
    And I fill the moodle form with:
      | Subject | Edited post subject |
      | Message | Edited post body |
    And I press "Save changes"
    And I wait to be redirected
    Then I should see "Edited post subject"
    And I should see "Edited post body"

  @javascript
  Scenario: Delete anonymous forum post
    When I follow "Anonymous forum post subject"
    And I follow "Delete"
    And I press "Continue"
    Then I should not see "Anonymous forum post subject"

  @javascript
  Scenario: Time limit expires
    When I wait "70" seconds
    And I follow "Anonymous forum post subject"
    Then I should not see "Edit" in the "region-main" "region"
    And I should not see "Delete" in the "region-main" "region"
