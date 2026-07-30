# Changelog — Course Transfer Manager

All notable changes to this plugin. Versions follow [semantic versioning](https://semver.org).

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
