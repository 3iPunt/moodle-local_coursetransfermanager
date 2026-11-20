@local @local_coursetransfermanager
Feature: Simulate a rotation task
  In order to know what a task would archive and delete before it does
  As an administrator
  I need a dry run that only reads and says which identity it uses

  # The task has no origin site: the simulation reports the origin as unreadable without
  # calling any host (a blocked fake host would still emit a debugging() that fails Behat).
  Background:
    Given the following "categories" exist:
      | name    | category | idnumber |
      | Archive | 0        | ARCHIVE  |
    And the following "local_coursetransfermanager > tasks" exist:
      | name            | targetcategory | nextruntime  |
      | Yearly rotation | ARCHIVE        | ##+1 month## |
    And the following "local_coursetransfermanager > executions" exist:
      | task            | status    | origincategoryname | origincategoryid | deletestatus | scheduleddeleteat |
      | Yearly rotation | completed | Year 2019          | 19               | scheduled    | ##+10 days##      |

  Scenario: Simulate a task without running anything
    When I log in as "admin"
    And I visit "/local/coursetransfermanager/manage.php"
    And I click on "View lifecycle" "link"
    And I click on "Simulate" "link"
    Then I should see "Simulation: Yearly rotation"
    And I should see "nothing was launched, deleted or saved"
    And I should see "The origin was asked as Admin User, the task creator"
    And I should see "The origin could not be read:"
    And I should see "No origin site has been configured for this task."
    And I should see "Year 2019"
    And I should see "Would be held"
