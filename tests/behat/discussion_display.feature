@mod @mod_anonforum @mod_anonforum_discussion_display
Feature: Students can choose from 4 discussion display options and their choice is remembered
  In order to read anonymous forum posts in a suitable view
  As a user
  I need to select which display method I want to use

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
    And I follow "Course 1"
    And I turn editing mode on
    And I add a "Anonymous forum" to section "1" and I fill the form with:
      | Anonymous forum name | Test anonymous forum name |
      | Description | Test anonymous forum description |
    And I add a new discussion to "Test anonymous forum name" anonymous forum with:
      | Subject | Discussion 1 |
      | Message | Discussion contents 1, first message |
    And I reply "Discussion 1" post from "Test anonymous forum name" anonymous forum with:
      | Subject | Reply 1 to discussion 1 |
      | Message | Discussion contents 1, second message |
    And I add a new discussion to "Test anonymous forum name" anonymous forum with:
      | Subject | Discussion 2 |
      | Message | Discussion contents 2, first message |
    And I reply "Discussion 2" post from "Test anonymous forum name" anonymous forum with:
      | Subject | Reply 1 to discussion 2 |
      | Message | Discussion contents 2, second message |
    And I log out
    And I log in as "student1"
    And I follow "Course 1"

  @javascript
  Scenario: Display replies flat, with oldest first
    Given I reply "Discussion 1" post from "Test anonymous forum name" anonymous forum with:
      | Subject | Reply 2 to discussion 1 |
      | Message | Discussion contents 1, third message |
    When I set the field "mode" to "Display replies flat, with oldest first"
    Then I should see "Discussion contents 1, first message" in the "div.firstpost.starter" "css_element"
    And I should see "Discussion contents 1, second message" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' anonforumpost ') and not(contains(@class, 'starter'))]" "xpath_element"
    And I reply "Discussion 2" post from "Test anonymous forum name" anonymous forum with:
      | Subject | Reply 2 to discussion 2 |
      | Message | Discussion contents 2, third message |
    And I set the field "mode" to "Display replies flat, with oldest first"
    And I should see "Discussion contents 2, first message" in the "div.firstpost.starter" "css_element"
    And I should see "Discussion contents 2, second message" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' anonforumpost ') and not(contains(@class, 'starter'))]" "xpath_element"

  @javascript
  Scenario: Display replies flat, with newest first
    Given I reply "Discussion 1" post from "Test anonymous forum name" anonymous forum with:
      | Subject | Reply 2 to discussion 1 |
      | Message | Discussion contents 1, third message |
    When I set the field "mode" to "Display replies flat, with newest first"
    Then I should see "Discussion contents 1, first message" in the "div.firstpost.starter" "css_element"
    And I should see "Discussion contents 1, third message" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' anonforumpost ') and not(contains(@class, 'starter'))]" "xpath_element"
    And I reply "Discussion 2" post from "Test anonymous forum name" anonymous forum with:
      | Subject | Reply 2 to discussion 2 |
      | Message | Discussion contents 2, third message |
    And I set the field "mode" to "Display replies flat, with newest first"
    And I should see "Discussion contents 2, first message" in the "div.firstpost.starter" "css_element"
    And I should see "Discussion contents 2, third message" in the "//div[contains(concat(' ', normalize-space(@class), ' '), ' anonforumpost ') and not(contains(@class, 'starter'))]" "xpath_element"

  @javascript
  Scenario: Display replies in threaded form
    Given I follow "Test anonymous forum name"
    And I follow "Discussion 1"
    And I set the field "mode" to "Display replies in threaded form"
    Then I should see "Discussion contents 1, first message"
    And I should see "Reply 1 to discussion 1" in the "span.anonforumthread" "css_element"
    And I follow "Test anonymous forum name"
    And I follow "Discussion 2"
    And I set the field "mode" to "Display replies in threaded form"
    And I should see "Discussion contents 2, first message"
    And I should see "Reply 1 to discussion 2" in the "span.anonforumthread" "css_element"

  @javascript
  Scenario: Display replies in nested form
    Given I follow "Test anonymous forum name"
    And I follow "Discussion 1"
    When I set the field "mode" to "Display replies in nested form"
    Then I should see "Discussion contents 1, first message" in the "div.firstpost.starter" "css_element"
    And I should see "Discussion contents 1, second message" in the "div.indent div.anonforumpost" "css_element"
    And I follow "Test anonymous forum name"
    And I follow "Discussion 2"
    And I set the field "mode" to "Display replies in nested form"
    And I should see "Discussion contents 2, first message" in the "div.firstpost.starter" "css_element"
    And I should see "Discussion contents 2, second message" in the "div.indent div.anonforumpost" "css_element"
