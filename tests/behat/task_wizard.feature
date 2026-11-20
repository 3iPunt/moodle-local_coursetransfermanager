@local @local_coursetransfermanager
Feature: Create a rotation task with the wizard
  In order to archive every academic year automatically
  As an administrator
  I need to create a task step by step and review it before activating it

  # The origin is a fake host under example.com and the wizard is driven without the
  # "Test the mask in the origin" button, the only step that would call the origin.
  # The cURL security helper blocks example.com as a safety net.
  Background:
    Given the following config values are set as admin:
      | curlsecurityblockedhosts | *.example.com |
    And the following config values are set as admin:
      | ignorecurlsecurity | 0 | local_coursetransfer |
    And the following "categories" exist:
      | name    | category | idnumber |
      | Archive | 0        | ARCHIVE  |
    And the following "local_coursetransfermanager > origin sites" exist:
      | name   | host                       |
      | Origin | https://origin.example.com |

  @javascript
  Scenario: Create and activate a task through the five steps
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/manage.php"
    And I click on "New task" "link"
    # Step 1: origin and mask (the default mask {YEAR}-{NEXTYEAR} is kept).
    And I should see "1. Where the content comes from"
    And I should see "This year it reads"
    And I set the field "Name" to "Yearly rotation"
    # The wizard listens to "input" events, which WebDriver's option selection does not fire:
    # pick the origin with the keyboard, as a person would (the label focuses the select).
    And I click on "label[for='ctm-site']" "css_element"
    And I press the down key
    And the field "Origin site" matches value "Origin — https://origin.example.com"
    And I should see "https://origin.example.com" in the "[data-region='sitecard']" "css_element"
    And I click on "Continue" "button"
    And I should see "2. Where it is stored in this platform"
    # Step 2: archive category, picked from the live search.
    And I set the field "Destination category" to "Archi"
    And I click on "Archive" "button" in the "[data-region='category-drop']" "css_element"
    And I click on "Continue" "button"
    # Steps 3 and 4: schedule and retention keep their defaults.
    And I click on "Continue" "button"
    And I click on "Continue" "button"
    # Step 5: review and explicit confirmation.
    And I should see "5. Review before activating"
    And the "Save and activate the task" "button" should be disabled
    And I click on "#ctm-confirm" "css_element"
    And I click on "Save and activate the task" "button"
    Then I should see "Task saved and active"
    And I click on "Go to the panel" "link"
    And I should see "Yearly rotation"
    And I should see "https://origin.example.com"
    And I should see "1 task(s)"

  @javascript
  Scenario: The wizard does not move on without a name and an origin
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/edit.php"
    And I click on "Continue" "button"
    Then I should see "1. Where the content comes from"
    And "[data-region='step-1']" "css_element" should be visible
    And "[data-region='step-2']" "css_element" should not be visible
