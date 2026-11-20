<!-- English version. Versión en español: README.es.md · Versió en català: README.ca.md -->
<p align="center">
  <img src="pix/logo.png" alt="" width="280">
</p>

<h1 align="center">Course Transfer Manager</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.2.0-informational" alt="Version">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.3-orange?logo=moodle" alt="Moodle"></a>
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/License-GPL--3.0-green" alt="License">
  <a href="https://tresipunt.com"><img src="https://img.shields.io/badge/made%20by-Tresipunt-F84015" alt="Made by Tresipunt"></a>
</p>

<p align="center"><b>Scheduled archiving of course categories between Moodle platforms — announced and cancellable deletions.</b></p>

<p align="center"><b>🇬🇧 English</b> · <a href="README.es.md">🇪🇸 Español</a> · <a href="README.ca.md">Català</a></p>

Course Transfer Manager automates the yearly archiving of course categories on
top of [Course Transfer](https://github.com/3iPunt/moodle-local_coursetransfer):
a task brings a whole category from another platform once a year, files it in
your archive and, after the retention you set, deletes it from the origin. It
adds no transfer machinery of its own — it orchestrates Course Transfer — and
because it deletes content in a **remote** platform months later, every
deletion is announced in advance, visible on screen and cancellable until the
moment it happens.

---

## ✨ What it does

- **Management panel** — health checks of the prerequisites (Course Transfer,
  cron, the plugin scheduled task), a chronological **"coming up"** agenda that
  merges the next executions with the pending deletions, and task cards with
  their last execution, an on/off switch and a guarded "Run now".
- **Guided task wizard** — five steps that verify as you go: **test the category
  pattern against the origin** before saving, schedule it in plain words (cron
  available as an advanced mode) with a live preview of the next runs, see the
  **real dates** of every deletion the task will cause, and review the full
  lifecycle timeline before confirming.
- **Tracking screen** — what is running now, what fires next, and everything
  that is going to be deleted, with a unified history; each request links to its
  fine-grained detail in Course Transfer.
- **Nine lifecycle notifications** — bell and email at every milestone,
  including an **advance notice with a cancel link** before deleting in the
  origin, a notice when a deletion is **held** by a safety check, and a request
  to **approve** a task's first deletion.
- **Safety locks** — the original is never deleted unless the restoration is
  complete, every restored course is still in the archive and the origin has not
  changed since; the first deletion of each task waits for a person; and an
  **emergency switch** pauses every deletion at once (see *Safeguards*).
- **Simulation** — what a task would archive, delete and prune right now,
  without touching anything.

## 🧭 Use cases

- **Yearly archive of an academic year** — every 1st of September, bring the
  category of the year that just ended from the production platform into an
  archive instance, and remove it from production a month later once the copy
  has been reviewed.
- **Free up space in production predictably** — keep only the current years
  online: the archive keeps the last N years and announces the older ones as
  pruning candidates, giving you time to exclude anything worth keeping.
- **Consolidate several origins into one archive** — one task per origin
  platform, each with its own pattern and retention, all visible in the same
  panel.
- **Archive without deleting anything** — set the retention high (or cancel the
  deletion each year): you get the automated yearly copy while the original
  stays untouched in the origin.

## ⚙️ How it works

- A **task** defines: the origin platform, a **naming mask** that recognises the
  yearly categories, the destination archive category, when it runs, and the two
  retention windows.
- **It rotates on its own.** The mask is not one category: it recognises the
  whole series. On a run of academic year A the task archives A−P and deletes
  A−P−V for good, where **P** is the years kept in production and **V** the
  years kept in the archive. Nobody edits the task in September.

  | Mask | Recognises | Placeholders |
  |---|---|---|
  | `CAT-{YEAR}-{NEXTYEAR}` | `CAT-2025-2026` | four-digit years |
  | `CAT-{YEAR}-{NEXTYY}` | `CAT-2025-26` | mixed |
  | `CAT-{YY}-{NEXTYY}` | `CAT-25-26` | two-digit years |
  | `SJD{YEAR}` | `SJD2025` | a single year |
  | `{ANY}-{YEAR}-{NEXTYEAR}` | `GINF-2025-2026`, `MED-2025-2026` | one task, every degree |

  With P=2 and V=4, the run of 2027/28 keeps 2027/28 and 2026/27 in production,
  holds four years in the archive, and deletes 2021/22 for good. The wizard and
  the **task lifecycle view** (`task.php?id=N`) project that table six runs
  ahead, so nothing has to be worked out in your head.
- On the scheduled date the task reads the origin's categories, picks the year
  that has outstayed the production window and asks Course Transfer to restore
  it, then files it under the chosen archive category.
- The deletion in the origin is scheduled the task's **retention days** after
  the launch, but it never runs before Course Transfer reports the restoration as
  **complete**.
- Before deleting in the origin the plugin sends an **advance notice** (days
  configurable) and runs every safeguard again. If one fails, the deletion is
  **held** and reported instead of executed.
- The task talks to the origin **as its creator** (or as whoever presses "Run
  now"): that user needs an equivalent account in the origin with the
  capabilities Course Transfer requires there.
- Categories that reached the archive **without** the task are invisible to the
  pruning by design — that is what protects foreign content. Adopting one so the
  task may delete it is an explicit, audited and reversible decision from the
  lifecycle view.
- Everything is **asynchronous**: it relies on Moodle **cron** on both
  platforms, so cron must be running on each side.

## 📋 Requirements

| Requirement | Version |
|---|---|
| Moodle | 4.5, 5.1 or 5.3 |
| PHP | 8.1+ |
| Other plugins | `local_coursetransfer` **2.2.0 or later in this platform** (the remote origin only needs its stable backend web services, 2.2.0 recommended) |
| Cron | Running on **both** platforms |

## 🚀 Installation

1. Copy the code into `local/coursetransfermanager/` (`public/local/…` on
   Moodle 5.x).
2. Complete the installation from **Site administration › Notifications** (or
   `php admin/cli/upgrade.php --non-interactive`).
3. Purge the caches (**Site administration › Development › Purge caches**).
4. Make sure the origin platforms are already paired in Course Transfer, and
   that **cron runs on both platforms**.

## 🔧 Settings

In **Site administration › Plugins › Local plugins › Course Transfer Manager**:

| Setting | Effect |
|---|---|
| **Days of notice before deleting in the origin** | How long before a scheduled deletion the cancellable advance notice is sent. Default: 7, minimum 1. |
| **Grace days before pruning the archive** | Time between announcing the pruning candidates and deleting them, so they can be excluded. Default: 7, minimum 1. |
| **Hold the deletion if the origin changed (R3)** (`holdiforiginchanged`) | On by default. See *Safeguards*. |
| **Approve each task's first deletion** (`requirefirstdeletionapproval`) | On by default. See *Safeguards*. |
| **Pause every deletion (emergency switch)** | While active, no deletion or pruning runs and their due dates keep sliding forward, so lifting the pause never triggers a backlog. Restorations are unaffected. |
| **Month the academic year starts** | Used to know which course is running when a task executes. Before that month the running course is still the previous one: in May 2026 the course is 2025/26. Default: September. |
| **Maximum categories archived per run** | Cap so a delayed year cannot flood the origin with simultaneous restorations. |

The retention windows (**years kept in production** and **years kept in the
archive**) belong to each task, not to the site: different origins can keep
different amounts of history.

Notification channels are managed with Moodle's standard notification
preferences, per site and per user.

## 🛡️ Safeguards

A deletion in the origin is the only step that removes content that may exist
nowhere else. Right before it runs, these checks are made; if one fails, the
deletion is **held**, postponed and notified, never executed silently:

| Safeguard | What it checks | Setting |
|---|---|---|
| **R1 — verified restore** | Course Transfer reported the restoration as complete (not just launched) and verified: no missing modules or files, and the restored courses match the origin. | Always on |
| **R2 — intact archive** | Every course of that restoration is still in this platform, inside the archived category. | Always on |
| **Usable identity** | The task's creator exists and is active: the origin is never asked under another identity. | Always on |
| **R3 — unchanged origin** | The origin category still has the courses that were archived; new or removed courses, or an origin that does not answer, hold the deletion. The origin does not report modification dates, so only the course list is compared. | `holdiforiginchanged` (on) |
| **First-deletion approval** | The first deletion of each task, and the first after its mask, years or origin change, waits in *Executions and deletions* until a manager approves it. The safeguards run again after the approval. | `requirefirstdeletionapproval` (on) |
| **Advance notice** | A cancellable notice is sent before the deletion, never in the same cron pass. | Days of notice (≥ 1) |
| **Emergency switch** | While on, no deletion or pruning runs and their dates slide forward. | Pause every deletion |

Pruning the archive has its own guards: only categories the task brought or
adopted are considered, candidates are announced with a grace period
(excludable), and each one is checked again before deleting; one that no longer
meets the policy is excluded for good. On the origin, Course Transfer applies its
own protections (recycle bin retention, audit events).

**Simulate** a task (button on its lifecycle view) to see what it would archive,
which deletions would run, be held or wait for approval, and what it would prune,
without changing anything. Every deletion, hold, approval, cancellation and
pruning is also recorded as an event in the site logs.

## 🗑️ Uninstallation

Removing the plugin deletes its tasks, execution history and pruning records.
Nothing else is touched: the categories already archived stay where they are,
and no further deletion is ever scheduled.

## 🛠️ Development

```bash
# Unit tests
vendor/bin/phpunit --testsuite local_coursetransfermanager_testsuite

# JavaScript build (after editing amd/src)
npx grunt amd --root=local/coursetransfermanager
```

## 📄 License

[GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html) — 2026 [Tresipunt](https://tresipunt.com) (contacte@tresipunt.com)

---

<p align="center">
  <a href="https://tresipunt.com"><img src="pix/tresipunt_logo.png" alt="Tresipunt" width="160"></a>
</p>
