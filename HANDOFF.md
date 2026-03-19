# HANDOFF

Stand: 2026-03-19 14:20 Europe/Berlin

## TL;DR

- Repo lokal und auf `learning-dev` ist auf `82753b8`.
- `learning-dev` ist sauber per `git pull` synchronisiert; `git status` dort ist clean.
- Groesster neue Feature-Block seit gestern: Kurs-Navigation fuer Schueler, Liga, Mehrsprachigkeit, VirtuProf, Support-Tickets, Duel-Einladungen via VirtuProf.
- Duel-Invites sind backend- und frontendseitig implementiert und auf `learning-dev` deployt.
- Dev-Schema-Drift wurde mehrfach manuell nachgezogen; fuer Duel-Invites wurde `oc_learning_duel_invites` direkt in Postgres angelegt und an `oc_admin` berechtigt.
- Remote-`npm run build` ist gruen, nur die bekannten Webpack-Size-Warnings bleiben.
- API-Smokes fuer VirtuProf-Language, Duel-Opponents, Duel-Invites (`create -> visible -> cancel`) sind gruen.
- Offener Hauptrest: echter Zwei-Browser-UI-E2E fuer Invite-`accept -> open duel -> play` sowie weitere Produktentscheidungen rund um Duel-Einladungen/Notifications.

## Ziel & Rahmen

Projekt ist eine Nextcloud-App (`app/`) fuer Lernkarten, Leitner, Exam, Duelle, Kurse und Liga. Aktueller Schwerpunkt war, die App fuer reale Schueler-/Dozenten-Nutzung auszubauen: klare Kursnavigation, Mehrsprachigkeit, VirtuProf als dauerhafte Hilfe, Support-Tickets und direkte Dueleinladungen ohne Code-Sharing. Wichtige Rahmenbedingungen: Vue 2.7, Nextcloud App Framework, PostgreSQL, Deploy nach `learning-dev` streng ueber Git-first + Remote-Build + Container-Sync, keine Secrets in Doku, Doku append-only in den Ops-Backlog.

## Aktueller Stand

### Fertig

- Schueler-Kursnavigation in [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue): direkte Lernmodus-Tabs, Pool-Auswahl erst nach Moduswahl.
- Duel-Feedback in [app/src/components/DuelMode.vue](app/src/components/DuelMode.vue): abgegebene Antwort bleibt markiert, richtige Antwort immer sichtbar.
- Liga komplett: Backend, Migration, Frontend-Tab.
- Kammermann-/Kapitelmodell auf Pool- und Frageebene.
- Frage-Sprachmodell (`content_language`) und Runtime-Uebersetzung in allen Modi inkl. Swipe und SmartQueue.
- Sprachumschalter pro Frage in Training, Leitner, Swipe, Exam, Duel.
- Pool-Sprachhinweise in Kursansicht.
- Exam-Ende/Abort-Bug gefixt.
- Dozenten-Regeln fuer Kurs-Pools (`required`, `required_enforced`, Exam-/Kapitel-/Question-Filter).
- VirtuProf als permanenter Avatar mit umfangreichen FAQs.
- VirtuProf Support-Tickets mit Admin-Inbox.
- VirtuProf-L10n fuer `de/en/ru/ar`.
- VirtuProf kleiner eigener Sprachtoggle.
- VirtuProf-Sprache jetzt serverpersistent.
- VirtuProf-Duel-Inbox + direkte Duel-Einladungen via Gegnerauswahl im Kursduell.

### Halb-fertig / noch pruefen

- Duel-Invite-UX ist technisch live, aber der vollstaendige Browserpfad `incoming invite -> accept -> deep-link in duel -> ready/start -> finish` wurde noch nicht mit zwei Browser-Sessions E2E durchgespielt.
- Arabisch ist als Inhaltssprache technisch aktiv, aber nicht jede Oberflaeche wurde bereits explizit auf RTL-Politur geprueft.
- `learning-dev` braucht weiter manuelle DB-Nachzuege, weil App-Migrationen dort praktisch nicht automatisch laufen.

## Wichtige Dateien / Module

- [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue)  
  Zweck: Kursdetail, Schueler-/Lehrer-Tabs, Pool-Sprachhinweise, Pool-Regeln, Duel-/Liga-Einstieg.  
  Status: aktiv im Produkt, zuletzt erweitert fuer Sprachhinweise und Duel-Preset-Deep-Link.

- [app/src/components/DuelMode.vue](app/src/components/DuelMode.vue)  
  Zweck: komplettes Duel-Frontend.  
  Status: erweitert um Gegnerauswahl, Invite-Lobby-Zustaende, VirtuProf-Refresh-Hooks, Sprachumschalter, Feedback-Fix.

- [app/src/components/VirtuProf.vue](app/src/components/VirtuProf.vue)  
  Zweck: Avatar-Controller, Trigger-Queue, FAQ-Navigation, Ticket-Flow, Duel-Inbox, Language-Persistenz.  
  Status: zentraler UI-Orchestrator fuer VirtuProf; aktuell live.

- [app/src/components/VirtuProfBubble.vue](app/src/components/VirtuProfBubble.vue)  
  Zweck: Bubble-Rendering fuer FAQs, Tickets, Invite-Liste.  
  Status: live, inkl. kleinem Sprachschalter und RTL-`dir`.

- [app/src/utils/virtuprof-scripts.js](app/src/utils/virtuprof-scripts.js)  
  Zweck: VirtuProf-Inhalte/FAQs.  
  Status: stark ausgebaut, 41+ FAQ-Eintraege und Themennavigation.

- [app/src/utils/virtuprof-i18n.js](app/src/utils/virtuprof-i18n.js)  
  Zweck: VirtuProf-UI-I18n und Browserfallback.  
  Status: live, zusammen mit serverseitiger Persistenz.

- [app/lib/Controller/VirtuProfController.php](app/lib/Controller/VirtuProfController.php)  
  Zweck: `state`, `dismiss`, `enabled`, `language`.  
  Status: live; `language` speichert `virtuprof_language` pro User.

- [app/lib/Controller/DuelController.php](app/lib/Controller/DuelController.php)  
  Zweck: normale Duelle + neue Invite-Endpunkte.  
  Status: live; enthaelt `invite`, `invites`, `acceptInvite`, `declineInvite`, `cancelInvite`, `opponents`.

- [app/lib/Service/DuelService.php](app/lib/Service/DuelService.php)  
  Zweck: Duel-Logik inkl. Invite-Erzeugung, Invite-Inbox, Accept/Decline/Cancel, Opponent-Liste.  
  Status: live, API-smoke geprueft.

- [app/lib/Db/DuelInvite.php](app/lib/Db/DuelInvite.php)  
  Zweck: neue Entity fuer Einladungen.  
  Status: live.

- [app/lib/Db/DuelInviteMapper.php](app/lib/Db/DuelInviteMapper.php)  
  Zweck: Mapper fuer Einladungen (`findActiveForUser`, `findActiveBetweenUsers`, etc.).  
  Status: live.

- [app/lib/Migration/Version003300Date20260319223000.php](app/lib/Migration/Version003300Date20260319223000.php)  
  Zweck: Schema fuer `learning_duel_invites`.  
  Status: im Repo; auf `learning-dev` manuell in Postgres nachgebaut.

- [app/appinfo/routes.php](app/appinfo/routes.php)  
  Zweck: alle API-Routen.  
  Status: erweitert um VirtuProf-Language und Duel-Invite-Routen.

- [app/l10n/de.json](app/l10n/de.json), [app/l10n/en.json](app/l10n/en.json), [app/l10n/ru.json](app/l10n/ru.json), [app/l10n/ar.json](app/l10n/ar.json)  
  Zweck: UI-Texte inkl. VirtuProf/Duel-Invite-Texte.  
  Status: fuer VirtuProf-Keys vollstaendig.

- [CODEX_HANDOFF.md](CODEX_HANDOFF.md)  
  Zweck: projektspezifischer Workflow/Deploy-Hinweise.  
  Status: bereits um Git-first-Deploy-Workflow erweitert.

## Commands / How-To

### Lokaler Status

```bash
cd /home/andre/Workspace/Code/learning-nc
git status
git log --oneline -5
```

### learning-dev aktualisieren

```bash
ssh learning-dev 'cd ~/learning-nc && git pull origin main'
```

### Frontend-Build auf learning-dev

```bash
ssh learning-dev 'cd ~/learning-nc/app && npm run build'
```

### JS-Bundle in Container deployen

```bash
ssh learning-dev '
cd ~/learning-nc/app &&
tar cf /tmp/js-bundle.tar js/ &&
docker cp /tmp/js-bundle.tar learning-app:/tmp/ &&
docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && tar xf /tmp/js-bundle.tar"
'
```

### Runtime-Dateien in Container deployen

```bash
ssh learning-dev '
cd ~/learning-nc/app &&
tar cf /tmp/learning-runtime.tar appinfo lib l10n &&
docker cp /tmp/learning-runtime.tar learning-app:/tmp/ &&
docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && tar xf /tmp/learning-runtime.tar" &&
docker exec learning-app php -r "opcache_reset();" &&
docker exec learning-app apache2ctl graceful
'
```

### Relevante Smoke-Calls

```bash
curl -s -u testuser2:test123 -H 'OCS-APIREQUEST: true' \
  http://192.168.178.65:8080/apps/learning/api/virtuprof/state

curl -s -u testuser2:test123 -H 'OCS-APIREQUEST: true' \
  http://192.168.178.65:8080/apps/learning/api/courses/20/duel-opponents

curl -s -u testuser2:test123 -H 'OCS-APIREQUEST: true' \
  http://192.168.178.65:8080/apps/learning/api/duel-invites
```

## Offene Punkte

1. Vollstaendigen Duel-Invite-Browser-E2E testen:
   - Student A erstellt Invite
   - Student B bekommt VirtuProf-Popup
   - Student B akzeptiert
   - Deep-Link in Kurs/Duelltab
   - beide `ready`
   - Duel anspielen/abschliessen

2. Produktentscheidung fuer Duel-Invite-Popup schaerfen:
   - wie aggressiv darf VirtuProf auto-oeffnen?
   - soll zusaetzlich ein Count-Badge am Avatar sichtbar sein?
   - sollen Instructoren ebenfalls Invites sehen duerfen oder nur Schueler?

3. RTL-/Arabisch-Finishing:
   - VirtuProf visuell pruefen
   - Frage-Layouts mit Mixed LTR/RTL pruefen
   - Duel-/Exam-Feedback bei `ar` durchklicken

4. Weiterer Sprach-/Inhaltsausbau:
   - `AR`-Inhalt fuer weitere Pools
   - evtl. weitere Runtime-Imports ausser Pool 81

5. Optional: Duel-Invite-Historie / Gelesen-Status / Ablaufzeit einfuehren.

6. Optional: echte Admin-/Dozenten-Antwortzustaendigkeit fuer Tickets feiner trennen.

## Bekannte Bugs / Fehler

- Kein akuter Codefehler mehr im zuletzt bearbeiteten Invite-Block.
- `learning-dev` hat weiterhin das strukturelle Problem, dass App-Migrationen nicht automatisch sauber laufen. Deshalb wurden mehrere Schemateile manuell angelegt/umbenannt.
- Build liefert weiterhin die bekannten Webpack-Asset-Size-Warnings. Kein Blocker, aber noisy.

## Dev-Schema-Drift auf learning-dev

Diese Punkte wurden manuell repariert und koennen auf einem frischen Dev-/Stage-System erneut fehlen:

- Teile von `Version001900Date20260311020000.php`  
  `review_status`, `reviewer_id`, `reviewed_at` und Indexe fuer Pools/Questions.

- `Version002100Date20260316000000.php`  
  Rename von Translation-Tabellen auf `oc_learning_qst_translations` und `oc_learning_ans_translations`.

- `Version003200Date20260319193000.php`  
  Arabisch als erlaubte Sprache in den Translation-Constraints.

- `Version003300Date20260319223000.php`  
  neue Tabelle `oc_learning_duel_invites`.

Wichtig: Fuer `oc_learning_duel_invites` wurde auf `learning-dev` nicht nur die Tabelle, sondern auch Ownership/GRANTs fuer `oc_admin` gesetzt.

## Annahmen & offene Fragen

- Annahme: `learning-dev` bleibt weiterhin die Referenz fuer manuelle DB-Nachzuege, bis ein sauberer Migrationspfad etabliert ist.
- Annahme: Duel-Einladungen sollen vorerst nur zwischen Schuelern innerhalb desselben Kurses moeglich sein. Genau so ist es derzeit umgesetzt.
- Offene Frage: Soll VirtuProf fuer Duel-Einladungen dauerhaft ein Badge oder Zaehler am Avatar bekommen?
- Offene Frage: Sollen Einladungen automatisch ablaufen, wenn sie laenger offen bleiben?
- Offene Frage: Sollen Duelleinladungen spaeter in die Liga integriert oder bewusst getrennt bleiben?

## Do not forget

- Niemals wieder Dateien direkt auf `learning-dev` erzeugen und per Drift deployen; immer Git-first.
- Nach PHP-Aenderungen: Container-Runtime syncen und `opcache_reset()` plus `apache2ctl graceful`.
- Nach Vue-Aenderungen: Remote `npm run build` auf `learning-dev`, dann frisches JS-Bundle deployen.
- `learning-dev`-Schema immer gegen neue Migrationsdateien abgleichen; automatische Migration ist dort nicht verlaesslich.
- Bei Duel-/VirtuProf-Arbeit zuerst API-Pfade smoke-testen, dann Browser-E2E.
- BACKLOG bleibt append-only in `~/ObsidianVaults/Personal/Ops/backlog/BACKLOG.md`.
