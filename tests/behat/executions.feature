@local @local_coursetransfermanager
Feature: Executions and pending deletions
  In order to keep control of what the rotation removes
  As an administrator
  I need to review the executions and cancel a scheduled deletion before it happens

  Background:
    Given the following "categories" exist:
      | name       | category | idnumber |
      | Archive    | 0        | ARCHIVE  |
      | Year 2012  | ARCHIVE  | Y2012    |
    And the following "local_coursetransfermanager > origin sites" exist:
      | name   | host                       |
      | Origin | https://origin.example.com |
    And the following "local_coursetransfermanager > tasks" exist:
      | name            | originsite                 | targetcategory | nextruntime  |
      | Yearly rotation | https://origin.example.com | ARCHIVE        | ##+1 month## |
    And the following "local_coursetransfermanager > executions" exist:
      | task            | status    | origincategoryname | origincategoryid | deletestatus | scheduleddeleteat |
      | Yearly rotation | completed | Year 2019          | 19               | scheduled    | ##+10 days##      |
    And the following "local_coursetransfermanager > executions" exist:
      | task            | status | origincategoryname | errormessage          |
      | Yearly rotation | error  | Year 2018          | Origin token rejected |

  @javascript
  Scenario: The history lists the executions with their result
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/executions.php"
    And I click on "History" "button"
    Then I should see "Year 2019" in the "[data-region='tab-history']" "css_element"
    And I should see "Year 2018" in the "[data-region='tab-history']" "css_element"
    And I should see "Completed" in the "[data-region='tab-history']" "css_element"
    And I should see "Error" in the "[data-region='tab-history']" "css_element"

  @javascript
  Scenario: Cancel a scheduled deletion in the origin
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/executions.php"
    And I click on "Pending deletions" "button"
    Then I should see "Category «Year 2019»" in the "[data-region='tab-deletions']" "css_element"
    When I click on "Cancel deletion" "button" in the "[data-region='tab-deletions']" "css_element"
    And I should see "Cancel this deletion?"
    And I click on "Yes, cancel the deletion" "button" in the ".modal-dialog" "css_element"
    Then I should see "Cancelled by Admin User" in the "[data-region='tab-deletions']" "css_element"
    And I should not see "Cancel deletion" in the "[data-region='tab-deletions']" "css_element"
    # The cancellation is stored: after a reload the deletion is no longer pending.
    And I reload the page
    And I click on "Pending deletions" "button"
    And I should see "No pending deletions" in the "[data-region='tab-deletions']" "css_element"

  @javascript
  Scenario: Exclude an archive category from the announced pruning
    Given the following "local_coursetransfermanager > pruning candidates" exist:
      | task            | category | categoryname | graceuntil  |
      | Yearly rotation | Y2012    | Year 2012    | ##+7 days## |
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/executions.php"
    And I click on "Pending deletions" "button"
    And I click on "Exclude from pruning" "button" in the "[data-region='tab-deletions']" "css_element"
    And I click on "Yes, keep it" "button" in the ".modal-dialog" "css_element"
    Then I should see "Cancelled by Admin User" in the "[data-region='tab-deletions']" "css_element"
    And I should not see "Exclude from pruning" in the "[data-region='tab-deletions']" "css_element"

  @javascript
  Scenario: Approve the first deletion of a task in the origin
    Given the following "local_coursetransfermanager > executions" exist:
      | task            | status    | origincategoryname | origincategoryid | deletestatus     | scheduleddeleteat |
      | Yearly rotation | completed | Year 2017          | 17               | awaitingapproval | ##yesterday##     |
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/executions.php"
    And I click on "Pending deletions" "button"
    Then I should see "waiting for approval" in the "[data-region='tab-deletions']" "css_element"
    And I click on "Approve deletion" "link" in the "[data-region='tab-deletions']" "css_element"
    And I should see "Approve deleting «Year 2017» from the origin platform"
    And I press "Approve deletion"
    Then I should see "Deletion approved"
