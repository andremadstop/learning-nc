# Phase 123 — Push Notifications

## Ergebnis

NC-native Reminder-Notifications fuer faellige Karten, Streak-Warnungen und Pruefungstermine sind umgesetzt.

## Aenderungen

- `app/lib/Service/ReminderService.php`
  - neue Reminder-Logik fuer:
    - due cards
    - streak warnings
    - exam reminders (7/3/1 Tage vorher)
  - Deduplizierung ueber `IConfig` User-Values:
    - `last_due_reminder`
    - `last_streak_warning`
    - `last_exam_reminder_{courseId}`
  - respektiert bestehendes Personal-Setting `notifications_enabled`

- `app/lib/BackgroundJob/SendRemindersJob.php`
  - neuer `TimedJob`
  - Intervall `3600`
  - 09:00: due reminders
  - 20:00: streak warnings
  - jede Stunde: exam reminders

- `app/lib/Notification/Notifier.php`
  - bestehender Notifier erweitert um:
    - `due_reminder`
    - `exam_reminder`
  - `streak_warning`-Text auf die neue Reminder-Form angepasst
  - kompatibel zu bestehenden Badge-Notifications
  - `UnknownNotificationException` statt `InvalidArgumentException`

- `app/lib/AppInfo/Application.php`
  - alter `NotificationJob` wird bei Bootstrap aus der JobList entfernt
  - neuer `SendRemindersJob` wird registriert

- `app/appinfo/info.xml`
  - deklarativer Background-Job-Eintrag fuer `SendRemindersJob`

- `app/l10n/de.json`
- `app/l10n/de.js`
  - neue deutsche Notification-Texte fuer due/streak/exam

## Verifikation

- lokal:
  - `git diff --check`
  - `node --check app/l10n/de.js`
  - JSON-Parse fuer `app/l10n/de.json`
  - `xmllint --noout app/appinfo/info.xml`

- `learning-dev`:
  - `./scripts/deploy-dev.sh --php-only`
  - gezieltes PHPStan fuer:
    - `ReminderService.php`
    - `Notifier.php`
    - `SendRemindersJob.php`
    - `Application.php`
  - volles PHPStan gruen
  - Job in `oc_jobs` vorhanden:
    - ID `1812`
    - Klasse `OCA\\Learning\\BackgroundJob\\SendRemindersJob`
  - manueller Lauf erfolgreich:
    - `php occ background-job:execute --force-execute 1812`

## Randbefunde

- `php occ background-job:execute` akzeptiert nur Job-IDs, nicht Klassennamen.
- `php occ background-job:list` ist standardmaessig limitiert; fuer spaete IDs braucht man z.B. `--limit=2500`.
- Der Deploy-Script-Check `require AppInfo/Application.php` meldet weiterhin erwartbar einen Bootstrap-Warnpfad, ist aber kein Syntaxfehler. PHPStan und echter Job-Lauf waren gruene Gates.
