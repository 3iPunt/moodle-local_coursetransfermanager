<!-- English version. Versión en español: README.es.md · Versió en català: README.ca.md -->
<p align="center">
  <img src="pix/logo.png" alt="" width="280">
</p>

<h1 align="center">Course Transfer Manager</h1>

<p align="center">
  <img src="https://img.shields.io/badge/version-2.1.1-informational" alt="Version">
  <a href="https://moodle.org"><img src="https://img.shields.io/badge/Moodle-4.5%20--%205.1-orange?logo=moodle" alt="Moodle"></a>
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
- **Eight lifecycle notifications** — bell and email at every milestone,
  including an **advance notice with a cancel link** before deleting in the
  origin, and a notice when a deletion is **held** by a safety check.
- **Safety locks** — the original is never deleted unless the restoration is
  registered as complete and the archived copy still exists locally with courses
  in it; plus an **emergency switch** that pauses every deletion at once.

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
- A restoration is only **complete** when Course Transfer says so; that is when
  the retention countdown starts.
- Before deleting in the origin the plugin sends an **advance notice** (days
  configurable) and checks that the archived copy is really there. If it is not,
  the deletion is **held** and reported instead of executed.
- Categories that reached the archive **without** the task are invisible to the
  pruning by design — that is what protects foreign content. Adopting one so the
  task may delete it is an explicit, audited and reversible decision from the
  lifecycle view.
- Everything is **asynchronous**: it relies on Moodle **cron** on both
  platforms, so cron must be running on each side.

## 📋 Requirements

| Requirement | Version |
|---|---|
| Moodle | 4.5 – 5.1 |
| PHP | 8.1+ |
| Other plugins | `local_coursetransfer` **2.0.0 or later in this platform** (the remote origin only needs its stable backend web services) |

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
| **Days of notice before deleting in the origin** | How long before a scheduled deletion the cancellable advance notice is sent. Default: 7. |
| **Grace days before pruning the archive** | Time between announcing the pruning candidates and deleting them, so they can be excluded. Default: 7. |
| **Pause every deletion (emergency switch)** | While active, no deletion or pruning runs and their due dates keep sliding forward, so lifting the pause never triggers a backlog. Restorations are unaffected. |
| **Month the academic year starts** | Used to know which course is running when a task executes. Before that month the running course is still the previous one: in May 2026 the course is 2025/26. Default: September. |
| **Maximum categories archived per run** | Cap so a delayed year cannot flood the origin with simultaneous restorations. |

The retention windows (**years kept in production** and **years kept in the
archive**) belong to each task, not to the site: different origins can keep
different amounts of history.

Notification channels are managed with Moodle's standard notification
preferences, per site and per user.

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
