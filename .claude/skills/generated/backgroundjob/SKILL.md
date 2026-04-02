---
name: backgroundjob
description: "Skill for the BackgroundJob area of learning-nc. 3 symbols across 1 files."
---

# BackgroundJob

3 symbols | 1 files | Cohesion: 100%

## When to Use

- Working with code in `app/`
- Understanding how run, getAllDueCounts, sendNotification work
- Modifying backgroundjob-related functionality

## Key Files

| File | Symbols |
|------|---------|
| `app/lib/BackgroundJob/NotificationJob.php` | run, getAllDueCounts, sendNotification |

## Entry Points

Start here when exploring this area:

- **`run`** (Method) — `app/lib/BackgroundJob/NotificationJob.php:25`
- **`getAllDueCounts`** (Method) — `app/lib/BackgroundJob/NotificationJob.php:87`
- **`sendNotification`** (Method) — `app/lib/BackgroundJob/NotificationJob.php:103`

## Key Symbols

| Symbol | Type | File | Line |
|--------|------|------|------|
| `run` | Method | `app/lib/BackgroundJob/NotificationJob.php` | 25 |
| `getAllDueCounts` | Method | `app/lib/BackgroundJob/NotificationJob.php` | 87 |
| `sendNotification` | Method | `app/lib/BackgroundJob/NotificationJob.php` | 103 |

## How to Explore

1. `gitnexus_context({name: "run"})` — see callers and callees
2. `gitnexus_query({query: "backgroundjob"})` — find related execution flows
3. Read key files listed above for implementation details
