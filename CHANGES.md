# Changelog — Course Transfer Manager

All notable changes to this plugin. Versions follow [semantic versioning](https://semver.org).

## 2.2.3 — 2026-11-25 (`2026112401`)

Maintenance release. Supported: Moodle 4.5, 5.1 and 5.3. Requires **Course Transfer 2.2.1**
(`local_coursetransfer` ≥ `2026112002`) on this platform; **2.2.3 is recommended**.

### Fixed

- An execution whose Course Transfer request is deleted while in progress fails with the reason, and the
  removal of the origin it scheduled is cancelled, instead of waiting forever.
- «Run now» no longer shows «SyntaxError … is not valid JSON» when the task runs fine: the
  progress lines meant for the cron log no longer reach the answer.
- A deletion held because the origin refuses the account used for the check says so, instead
  of saying that the origin did not answer.
- A held deletion is shown in the executions log with its rule and reason, not as a failed run,
  and a candidate excluded by a person while the cron prunes is no longer reported as invalid.

## 2.2.2 — 2026-11-23 (`2026112300`)

Data-safety release. Supported: Moodle 4.5, 5.1 and 5.3. Requires **Course Transfer 2.2.1**
(`local_coursetransfer` ≥ `2026112002`) on this platform; **2.2.2 is recommended**, and the origin
platforms should be upgraded to 2.2.1 or later first.

### Fixed

- Archived courses keep their course settings (summary, image, format, dates, idnumber, custom
  fields, completion): categories are now restored as new courses.
- A deletion is only requested to the platform the copy came from; the origin of a task cannot be
  changed while it has deletions pending.
- A deletion is held when the task asked for user data and the copy arrived without it (or
  anonymised), or when the origin reports content of the category that the task creator cannot see
  there (it was not copied, and the deletion in the origin is recursive).
- The checks before a deletion run in a safer order (creator, origin unchanged, then the restore
  manifest), so an unreachable origin or an unusable account no longer leaves a deletion held for
  good. The simulation writes nothing: courses not verified yet are shown as pending.
- A pruning candidate excluded while the cron prunes others is no longer deleted.
- An archive that failed before creating anything is tried again on the next run.
- An origin that refuses the creator's account (not found, duplicated, suspended) is reported as
  an account to fix, not as an unreachable origin retried several times.

### Changed

- A held deletion shows its rule and reason in the tracking screen, and the held notice explains
  the ways out (fix the cause and wait for the next check, or cancel and delete by hand).
- The "deletion executed" notice now says the deletion was requested to the origin and accepted;
  it runs there in the background.

### Upgrade notes

- After upgrading Course Transfer to 2.2.x, deletions of archives made with older versions may be
  held by its stricter checks (for example, a backup without its manifest). Review them in the
  tracking screen: cancel and delete by hand after checking the copy, or archive again.
- With Course Transfer `transferlogs` on (default), backups with user data include the logs and
  can be larger.

## 2.2.0 — 2026-11-20 (`2026112001`)

Safeguards release: nothing is deleted from the origin unless the archived copy
is complete and the origin has not changed since, and **Moodle 5.3 support**.
Supported: Moodle 4.5, 5.1 and 5.3 (`requires` 4.5, `supported = [405, 503]`).
Requires **Course Transfer 2.2.0** (`local_coursetransfer` ≥ `2026112001`) on
this platform; the remote origin only needs Course Transfer's stable backend web
services (≥ `2026051301`), although upgrading it to 2.2.0 is strongly advised.
No database changes.

### ⚠️ Breaking changes

- **Course Transfer 2.2.0 is required on this platform.** Upgrade
  `local_coursetransfer` first, or in the same upgrade.
- **The task's creator is its identity in the origin.** Listings, restores and
  deletions are asked of the origin as the user who created the task (or, for
  "Run now", the user who presses it), never as whoever runs the cron. That user
  needs an equivalent account in the origin, with the capabilities Course
  Transfer requires there (backup, and removal of categories). If the creator no
  longer exists or is suspended, runs fail with `creator_unavailable` and due
  deletions are held: edit and save the task to take it over.
- **The first deletion in the origin of each task waits for approval**
  (`requirefirstdeletionapproval`, on by default), and so does the first one after
  its mask, years in production, years in the archive or origin change. Once a
  person approves it in **Executions and deletions**, the next deletions of the
  same policy run on their own. Upgraded tasks count as never approved: their
  next deletion waits too.
- **A deletion is held if the origin changed after archiving** (R3,
  `holdiforiginchanged`, on by default): right before deleting, the task asks the
  origin for the category's courses and compares them with what it archived. New
  or removed courses, or an origin that does not answer, hold the deletion and
  notify. The origin does not report when its courses were modified, so only the
  course list is compared.
- **R1 requires a verified restore**: the deletion in the origin only runs when
  Course Transfer reports the restoration as complete **and** verified (no
  missing modules or files, and the restored courses match the origin manifest).
  A restore completed with issues holds the deletion.
- **R2 is stricter**: every course of the execution's restore must still be in
  the archive, inside the archived category. Moving or deleting one of them by
  hand holds the deletion in the origin.
- **The notice and grace periods are at least one day.** `deletionwarningdays`
  and `prunegracedays` only accept whole days from 1 to 9999; a stored `0` is read
  as 1, so a notice and its deletion never happen in the same cron pass.
- **Archive pruning is checked again before running.** A candidate that stopped
  meeting the policy during its grace period (no longer managed by the task,
  moved out of its destination, not old enough) is not pruned and is excluded for
  good; only a person can bring it back.

### Added

- **Simulation** (`simulate.php`, button **Simulate** on the task view): what the
  task would archive, delete from the origin (with the result of each safety rule)
  and prune, right now. The origin is only read; nothing is launched, deleted or
  saved.
- **Approval of the first deletion** (`approve.php`, from the deletion card), with
  its notification (N9) and the new deletion status *Waiting for approval*.
- **Safety copy of the backups** on this platform for the restores launched by a
  task (Course Transfer keeps the `.mbz` until the origin has been deleted and
  its recycle bin retention has passed).
- **Settings** `holdiforiginchanged` and `requirefirstdeletionapproval` (both on).
- **Audit events** of the destructive lifecycle: `deletion_requested`,
  `deletion_executed`, `deletion_held`, `deletion_cancelled`,
  `deletion_approved`, `prune_announced`, `prune_executed` and
  `prune_excluded`.
- Web service functions `local_coursetransfermanager_deletion_approve` and
  `local_coursetransfermanager_task_simulate` (capability
  `local/coursetransfermanager:managetasks`).
- Behat scenarios for the panel, the task wizard and the tracking screen.

### Fixed

- **Every task created with the wizard kept 2 years in production**, whatever the
  user chose: the value was not saved. A task with another value archived and
  deleted from the origin a different year than shown. **Review your tasks.**
- **A run could archive the same category twice**: a restore that failed half way
  is now counted as brought, and the cron and "Run now" share a lock per task.
- **The restored category was located by idnumber**, so a duplicate idnumber
  could make the task move or manage a category it did not bring. It is now
  resolved from the restore request itself; when that is not certain, the
  category is left where it is, not managed, and its deletion in the origin is
  held.
- A due deletion could be cancelled while it was being sent to the origin, or
  sent twice by two cron runs: it is now claimed before calling the origin, and a
  claim left by a crashed cron is released after one hour.
- An advance notice sent late (cron down, short retention) no longer arrives at
  the same time as the deletion: the deletion is moved to after the notice period.
- A cron expression that never fires (31 February) is rejected by the wizard, and
  tasks that lost their schedule get one without running.
- A transient origin failure is retried within the same cycle (up to three
  times) instead of waiting a year; a category that fails three times waits for
  the next cycle.
- "Run now" reports which categories were launched and which failed, and refuses
  to start while the task is running.
- Notifications to a suspended recipient go to the site administrator instead.

### Permissions and privacy

- `local/coursetransfermanager:managetasks` declares its risks
  (`RISK_DATALOSS`, `RISK_CONFIG`, `RISK_PERSONAL`).
- The user search of the wizard only shows and searches emails for users allowed
  to see them (`showuseridentity`).

### Changed

- The privacy provider also declares the adopted categories (who adopted each
  one) and the notifications sent through the messaging system.

### Upgrade notes

- Upgrade `local_coursetransfer` to 2.2.0 on this platform, and preferably on the
  origins too (its improvements only apply to the sites that run it).
- Check that the creator of each task exists, is active and has an equivalent
  user with the right capabilities in the origin. Run **Simulate** on each task:
  it shows what the next run would archive, and which deletions would be held or
  wait for approval.
- Review the **years in production** of every task created with the wizard (see
  Fixed).
- Expect each task's next deletion in the origin to wait for approval, unless
  `requirefirstdeletionapproval` is turned off.
- Cron must run on both platforms.

## 2.1.1 — 2026-09-15

Housekeeping release required for publication. **No behaviour changes.**

### Changed

- **The four database tables now carry the component prefix.** They were named
  `local_ctm_*`, an abbreviation that Moodle's own plugin validation rejects —
  and one that was never needed, since the name limit is 53 characters and the
  full names fit well within it:

  | Before | Now |
  |---|---|
  | `local_ctm_tasks` | `local_coursetransfermanager_tasks` |
  | `local_ctm_executions` | `local_coursetransfermanager_executions` |
  | `local_ctm_adopted` | `local_coursetransfermanager_adopted` |
  | `local_ctm_prune` | `local_coursetransfermanager_prune` |

  **Existing data is preserved**: the upgrade step renames the tables, it does
  not recreate them. It is guarded both ways, so re-running it, or installing
  from scratch, does nothing.

- **Continuous integration** on every push and pull request, running the full
  `moodle-plugin-ci` suite across PHP 8.1 – 8.4, Moodle 4.5 and 5.1, and both
  PostgreSQL and MariaDB.

- The code now passes `moodle-plugin-ci` clean: Moodle Code Checker, PHPDoc
  Checker, Validate, Upgrade savepoints, Mustache Lint and Grunt. This meant
  sorting the language files, dropping the comments inside them, flattening
  seventeen nested promise chains in the AMD modules, and replacing the
  obsolete table attributes of the notification email with their CSS
  equivalent.

## 2.1.0 — 2026-07-31

Automatic rotation by academic year. Until now the task pointed at *one*
category and somebody had to edit it every September; from this release the
pattern is a **naming mask** and two retention windows decide, on their own,
what is archived and what is deleted for good on each run. **The task is never
edited again.**

### ⚠️ Breaking changes

- **The category pattern is now a naming mask, not a regular expression.**
  `{YEAR}` no longer means "the current year" but "the academic year this
  category belongs to", and the mask must recognise the whole yearly series.
  Existing patterns are migrated on upgrade: regex anchors (`^` `$`) are
  stripped and `{PREVYEAR}-{YEAR}` becomes `{YEAR}-{NEXTYEAR}` (the starting
  year must come first, or the whole policy would sit one year off).
  `{PREVYEAR}` no longer exists.
- **The legacy single-year path has been removed.** Every task rotates by
  policy, so the `patternmode` column is dropped on upgrade. A task whose mask
  carries no year placeholder now fails its run with a clear message instead of
  silently archiving nothing.

### Added

- **Rotation policy per task.** Two settings decide everything: *academic years
  kept in production* (P) and *years kept in the archive* (V). On a run of
  academic year A the task archives A−P and deletes A−P−V for good, so a course
  survives P+V years in total. Both are edited in the wizard, with presets.
- **Naming mask placeholders**: `{YEAR}`, `{NEXTYEAR}`, `{YY}`, `{NEXTYY}` for
  the year, plus `{ANY}` and `{DIGITS}` as wildcards so a single task can cover
  several degrees (`{ANY}-{YEAR}-{NEXTYEAR}`). Two-year masks are checked for
  consecutiveness, so `CAT-2025-2030` is never mistaken for a course.
- **Lifecycle projection table** in the wizard and in the new task view: six
  runs ahead, showing what stays in production, what waits in the archive and
  what is deleted on each one. Recalculated live by the engine itself
  (`policy_preview`), never by the browser, and it tells apart what exists today
  from what is only projected.
- **Task lifecycle view** (`task.php?id=N`, "View lifecycle" in the task menu):
  the rule in force, the projection, the next run step by step with its real
  dates, what is already scheduled, what the task manages in the archive, and
  the categories it deliberately does not.
- **Adoption of pre-existing archive categories.** Categories that reached the
  archive without the task are invisible to the pruning by design — that is the
  rule protecting foreign content. Adopting one is an explicit, audited and
  reversible decision, scoped to the task's own archive category.
- **Setting: month the academic year starts** (September by default). Before
  that month the running course is still the previous one, so a run in May
  behaves like the admin expects.
- **"Test the mask in the origin"** now lists every yearly category the mask
  recognises there, with its academic year and course count, and says *why* when
  the origin does not answer instead of a generic failure.

### Changed

- The archive retention preview and the confirmation timeline use the real
  formula (A−P−V). They previously derived the cutoff from the run date and
  ignored the production window.
- A rejected save now names the field that is wrong (`originkeepyears`,
  `destinationkeepyears`, `retentiondays`) instead of a generic "retention".
- Reading the origin's categories goes through CourseTransfer's own client, so
  it authenticates exactly like every other cross-platform call.

## 2.0.0 — 2026-07-30

First stable release. Complete redesign of the interface and a hardened
engine: the plugin schedules deletions of course categories in **remote**
platforms months after bringing them, so this release is built around one
principle — **you always know what is going to happen, and you can always
stop it**.

### ⚠️ Breaking changes

- **Requires CourseTransfer 2.0.0 or later in this platform.** The manager
  relies on its `request_completed` event (to know when a restoration really
  finished) and on its stored connection tests. CourseTransfer 1.x will not
  work. The remote *origin* platform is unaffected: it only needs the stable
  backend web services (2026051301 or later), so origins can stay on 1.x.
- **The "Years to keep at origin" setting has been removed.** Nothing ever
  read it: deletion in the origin is governed by the retention days, and the
  local archive by its own years-to-keep. The database column is dropped on
  upgrade.
- **`index.php` has been removed.** It was an orphan, degraded copy of the
  management page. Use the management panel instead.
- **Notification providers replaced.** The single `restorecomplete` provider
  is gone, superseded by the eight lifecycle notices below. Users who had
  customised their preference for it must set it again for the new ones.
- **The visible plugin name changed** to "Course Transfer Manager"; the
  tracking page no longer requires a task id (it now defaults to a global
  view).

### Added

- **Management panel** (`manage.php`): prerequisite health checks
  (CourseTransfer, cron, the plugin scheduled task), a chronological
  "coming up" agenda merging next executions with pending deletions and
  prunings, task cards with their last execution, an enable/disable switch
  and a guarded "Run now".
- **Task wizard** (`edit.php`): five steps with live verification — test the
  category pattern against the origin before saving, schedule in plain words
  (with the cron expression as an advanced mode) with a dynamic preview of
  the next runs, explicit consequences on the retention fields with real
  dates, per-task notification recipients and level, and a review step with
  the full lifecycle timeline plus an explicit confirmation of the deletions.
- **Tracking screen** (`executions.php`): global by default (or scoped to a
  task), with tabs for what is running, the pending deletions and the unified
  history; every request links to its fine-grained detail in CourseTransfer.
- **Eight lifecycle notifications** (bell and email): restoration launched,
  restoration completed for real, advance notice before the origin deletion
  (with a cancel link), deletion executed, pruning announced, pruning
  executed, execution failed, and **deletion held by a safety lock**. HTML
  bodies are laid out for email clients (inline styles, no external CSS) and
  signed with the plugin and site that sent them.
- **Cancellable deletions**: any pending deletion or pruning candidate can be
  cancelled or excluded from the interface while it is still pending, with an
  audit trail of who did it and when.
- **Safety locks on the origin deletion**: it only runs when the restoration
  is registered as completed AND the archived copy still exists locally with
  courses in it; otherwise it is held, postponed and reported. Plus an
  **emergency switch** that pauses every deletion and pruning (sliding their
  due dates so lifting it never triggers a backlog), and disabling a task now
  freezes its pending deletions too.
- **Privacy provider** (the plugin now stores user references: task creator,
  notification recipients, who cancelled a deletion or excluded a pruning).
- **PHPUnit test suite** (32 tests), the first one for this plugin.
- **Settings page**: days of advance notice before an origin deletion, grace
  days before pruning the archive, and the emergency switch.

### Changed

- **Archive pruning is now two-phase and scoped**: candidates are announced
  with a grace period before anything is deleted, only categories this plugin
  created are ever considered, and the year is extracted strictly. Previously
  a category whose idnumber merely contained four digits (for example
  `MED1042`) could be deleted along with its courses, even if the plugin had
  never created it.
- **The restored category is relocated using the transfer request** instead
  of a global idnumber lookup, so duplicate idnumbers no longer mark a
  successful restoration as failed. A relocation problem no longer fails the
  execution either.
- **Notifications reach the task creator** (and the recipients configured in
  the task) instead of the cron user, who never saw them.
- **Schedules are computed field by field** instead of scanning minute by
  minute: a yearly expression now resolves in milliseconds.
- Execution states are shown translated and with a single vocabulary,
  including the new "completed" (all courses restored) as distinct from
  "launched".
- The interface adopts the CourseTransfer design system, is responsive, and
  its pages hang from the CourseTransfer administration category.

### Fixed

- Filtering the task list by "From" only produced a database error.
- Category and task names are now escaped for output.
- Rejected remote deletions are no longer retried forever; they are reported
  for review.
- The `managetasks` capability had no language string (it showed as a raw key
  in the roles interface).

### Removed

- Dead code and strings: the legacy form, tables and filter classes, about
  fifty unused language keys (task types that were never implemented) and the
  whole unused stylesheet.
- A development artefact (`.claude/settings.local.json`) that shipped inside
  the plugin.

## 0.3.0 — 2026-05-21

Internal alpha: scheduled category restorations with origin retention and
archive pruning, driven by a per-task cron expression.
