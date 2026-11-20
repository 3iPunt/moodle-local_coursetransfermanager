@local @local_coursetransfermanager
Feature: Panel of scheduled transfers
  In order to know what the archive rotation will do
  As an administrator
  I need to see the configured tasks, their state and what comes next

  Background:
    Given the following "categories" exist:
      | name    | category | idnumber |
      | Archive | 0        | ARCHIVE  |
    And the following "local_coursetransfermanager > origin sites" exist:
      | name   | host                       | lasttest  | lastteststatus |
      | Origin | https://origin.example.com | ##today## | 1              |

  Scenario: The panel lists every task with its origin, mask, destination and last result
    Given the following "local_coursetransfermanager > tasks" exist:
      | name              | originsite                 | targetcategory | categorypattern   | enabled | nextruntime  |
      | Yearly rotation   | https://origin.example.com | ARCHIVE        | {YEAR}-{NEXTYEAR} | 1       | ##+1 month## |
      | Paused rotation   | https://origin.example.com | ARCHIVE        | SJD{YEAR}         | 0       | ##+1 month## |
    And the following "local_coursetransfermanager > executions" exist:
      | task            | status | errormessage                        |
      | Yearly rotation | error  | Could not reach the origin platform |
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/manage.php"
    Then I should see "Configured tasks"
    And I should see "2 task(s)"
    And I should see "Yearly rotation"
    And I should see "https://origin.example.com"
    And I should see "{YEAR}-{NEXTYEAR}"
    And I should see "Archive"
    And I should see "The last execution failed: Could not reach the origin platform"
    And I should see "Paused rotation"
    And I should see "Inactive"
    And I should see "Never run yet"
    And I should see "Next execution"

  Scenario: An empty panel invites to create the first task
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/manage.php"
    Then I should see "No archive tasks yet"
    And I should see "New task"
