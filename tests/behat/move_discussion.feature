@mod @mod_anonforum @mod_anonforum_move
Feature: Move Discussion
  If there exist two or more anonymous forum in the same course, I can move a
  disussion in one of those forum to another forum.

  Background: Login, create a course, and two anonymous forums.
    Given I log in as "admin"
    And I create a course with:
      | Course full name | DogeComedy |
      | Course short name | suchluagh |
    And I follow "DogeComedy"
    And I navigate to "Turn editing on" node in "Course administration"
    And I add a "Anonymous forum" to section "1" and I fill the form with:
      | Anonymous forum name | verylerning |
      | Description | Such learning. So wow. |
    And I add a "Anonymous forum" to section "1" and I fill the form with:
      | Anonymous forum name | suchserious |
      | Description | Much seryus. Very not laugf. |

  @javascript
  Scenario: Base case, all anonymous post.
    Given I add a new discussion to "verylerning" anonymous forum with:
      | Subject | Such concise. |
      | Message | Such inspired. Much sleek. Very wow. |
    And I reply "Such concise." post from "verylerning" anonymous forum with:
      | Subject | OMG. Wow. |
      | Message | Much academic. |
    And I set the field "jump" to "suchserious"
    And I press "Move"
    And I follow "DogeComedy"
    And I follow "suchserious"
    And I follow "Such concise."
    Then I should see "Such concise."
    And I should see "OMG. Wow."

  @javascript
  Scenario: Edge Case: One of the post is non anonymous. This used to cause
    a coding error.
    Given I add a new discussion to "suchserious" anonymous forum with:
      | Subject | Privat. Dont look. |
      | Message | Such teknology. Very entrosive. |
    And I reply "Privat. Dont look." post from "suchserious" anonymous forum with:
      | Subject | Much Very trutful |
      | Message | Such heartake. Omg very wow. |
      | anonymouspost | 0 |
    And I set the field "jump" to "verylerning"
    And I press "Move"
    And I follow "DogeComedy"
    And I follow "verylerning"
    And I follow "Privat. Dont look."
    Then I should see "Privat. Dont look."
    And I should see "Much Very trutful"