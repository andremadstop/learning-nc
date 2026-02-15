# Fix Plan — Learning-NC

## High Priority — Security Fixes (2026-02-15 Audit, alle gefixt 2026-02-15)

- [x] SEC-HIGH-1: Open Enrollment ohne Group-Check. IGroupManager injected, `enroll()` prueft jetzt `isInGroup()` wenn `nc_group_id` gesetzt. `CourseService.php`.
- [x] SEC-HIGH-2: Instructor Cross-Visibility. `findById()` globaler `isInstructor()` Fallback entfernt — nur noch Zugriff fuer Mitglieder des eigenen Kurses. `CourseService.php`.
- [x] SEC-MED-1: Exception-Messages an Client geleakt. Alle catch-Bloecke in `CourseController.php` geben jetzt generische Fehlermeldungen zurueck.
- [x] SEC-MED-2: Translation Write-Access fuer Read-Only User. `TranslationController.php` nutzt jetzt `verifyEditAccess()` / `verifyAnswerEditAccess()` mit `canEditPool()` fuer set/delete-Operationen.
- [x] SEC-MED-3: Course Description Laengenbegrenzung + mb_strlen. `CourseService.php:create/update` — Title nutzt jetzt `mb_strlen()`, Description max 5000 chars.

## High Priority (erledigt)

- [x] Frontend Smoke-Test: 4 Bugs gefixed (2026-02-15). App.vue: TrainingMode @back → backToPools; openPoolFromCourse fetches real pool name statt hardcoded '...'. CourseDetail: formatDate multipliziert jetzt Unix-Timestamps *1000; Drag-Handle durch Sort-Order-Nummer ersetzt. CourseList: "Instructor:" → "by" Label. NcTextField :value.sync = korrekt für @nextcloud/vue 8.x. Build + Deploy erfolgreich, HTTP 200.
- [x] API Smoke-Test: 51/51 Endpoints passing (2026-02-15). Minor findings: search param=`query` not `q`, removeMember uses userId string not numeric ID.
- [x] info.xml Version-Bump: 1.1.0 → 1.2.0, occ upgrade erfolgreich (2026-02-15). Description um Course Management, i18n, Exam Mode, Search erweitert.
- [x] Git Repo aufraeumen: 17 Stray-Dateien in /tmp auf learning-dev gelöscht (alle identisch oder ältere Drafts). Lokaler Git-Stand sauber: info.xml bump + 2 PHP-Fixes (CourseMemberMapper.findById, CourseService.removeMember) (2026-02-15)

## Medium Priority

- [x] README.md + CHANGELOG.md aktualisieren: v1.2.0 Changelog mit allen neuen Features, README um Course Management + neue Features erweitert (2026-02-15)
- [x] App Store Listing: EN/DE um ExamMode, SwipeMode, Kurs-Management, i18n, Search, Analytics erweitert + neue Tags (2026-02-15)
- [x] Release-Tarball: build/learning-1.2.0.tar.gz gebaut (1.2 MB, 82 Dateien, 0 .map, keine node_modules) (2026-02-15)
- [x] NC-Kompatibilitaet: NC 29.0.16 + NC 31.0.14 getestet (2026-02-15). 2 Migration-Bugs gefixed: (1) Types::BOOLEAN mit notnull=true → notnull=false (NC-Validator rejects NotNull booleans), (2) 12 Index-Namen zu lang (>27 chars mit oc_-Prefix) → gekuerzt auf learn_*-Schema. App installiert+aktiviert auf allen 3 Versionen, Frontend HTTP 200, Pools/Courses/Leitner APIs OK. Release-Tarball neu gebaut.

## Low Priority

- [ ] Screenshots: 4-6 Screenshots fuer App Store (Pool-Liste, Training, Leitner, Kurs-Uebersicht, Instructor-Dashboard) — MANUELL im Browser
- [ ] Signing Certificate: Bei Nextcloud beantragen — MANUELL
- [ ] App Store Account: apps.nextcloud.com Account anlegen — MANUELL
- [ ] Release-Tarball signieren + hochladen — MANUELL

## Post-Launch (nach App Store Approval)

- [ ] Community-Posts: NC Forum, Reddit (r/selfhosted, r/nextcloud), Hacker News
- [ ] Demo-Video: 2-3 Min Screencast
- [ ] Gamification: Streaks, XP, Achievements, Leaderboard
- [ ] Notifications: Daily Reminder, Streak Warning, Pool Shared
- [ ] Admin Settings: Default-Pools, Feature-Toggles, Instructor-Gruppe konfigurierbar
- [ ] PDF Certificates, Activity Stream, Pool Templates

## Completed

- [x] Phase A: Stabilisierung (DB-Ownership, 15 Services, Smoke-Test)
- [x] Phase B1: Leitner UI (5 Boxes, Due Banner, Stats, MC Review)
- [x] Phase B2: Pool Sharing (Dialog, Permissions, Share-aware Backend)
- [x] Phase B4: CSV/JSON Import (Controller, Dialog, Preview)
- [x] Phase C1: Dashboard Widget (IAPIWidgetV2)
- [x] Phase D1+D4: Mobile/Responsive + Error Handling
- [x] Phase E: Security Audit (3 Auth-Bypass-Fixes + 12 Codex-Findings)
- [x] Phase F: App Store Prep (info.xml, CHANGELOG, LICENSE, README, v1.0.0, Tarball)
- [x] Phase G: Marketing (Listings DE+EN, Blog, Demo Content)
- [x] Phase H: UI Overhaul (Gemini Audit, @nextcloud/vue Components)
- [x] Phase I: Neue Features (SwipeMode, Search, Language Filter)
- [x] Phase J: Security Audit Fixes (12 Codex-Findings)
- [x] Phase K: Dozenten-Rolle + Kurs-Management (15 Endpoints, 3 Components)

## Notes

- Ralph kann die High+Medium Priority Tasks automatisch abarbeiten
- Low Priority Tasks sind MANUELL (Browser, Nextcloud Account, Signing) — Ralph markiert sie als BLOCKED
- Der Code liegt lokal unter `app/` und wird per SSH/rsync auf learning-dev deployed
- Secrets (Logins): admin/admin, testuser/T3stUs3r!2026Secure
