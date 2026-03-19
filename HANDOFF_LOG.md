[2026-03-19 14:20] VirtuProf, Language, Duel-Invite Ausbau
Session-ID:
Arbeitsdauer: ca. 1 Arbeitstag seit gestern ueber mehrere Teilbloeke
TL;DR:
- Schueler-Kursnavigation, Liga, Mehrsprachigkeit und VirtuProf massiv ausgebaut.
- VirtuProf ist jetzt permanenter Helfer mit FAQs, Tickets, eigenem Sprachtoggle und serverseitiger Sprachpersistenz.
- Direkte Dueleinladungen mit Gegnerauswahl, VirtuProf-Inbox und Deep-Link in den Duel-Tab sind implementiert und auf learning-dev live.
- learning-dev wurde mehrfach gegen Schema-Drift manuell repariert.
- Offener Hauptrest ist ein voller Invite-`accept -> play`-Browser-E2E.
Aenderungen:
- App-Frontend: CourseDetail, DuelMode, VirtuProf, VirtuProfBubble, App, Sprachumschalter, Exam-Fix, LeagueTab.
- App-Backend: LeagueService/Controller, DuelController/Service, VirtuProfController, SupportTicket-Backend, CourseService-Regeln, TranslationService-Pfade.
- DB/Migrationen: League, Pool-/Question-Metadaten, Arabisch-Lang-Constraint, Support-Tickets, DuelInvites.
- Doku/Workflow: CODEX_HANDOFF.md und .gitignore gepflegt; Backlog erweitert.
Entscheidungen:
- Git-first-Workflow auf learning-dev ist verbindlich.
- Bestehende Pools bleiben bestehen; Kapitel-/Exam-Logik ueber Metadaten statt neuer Pool-Vervielfachung.
- Duel-Einladungen sind kursgebunden und nur fuer Schueler im selben Kurs.
- VirtuProf-Sprache wird pro Nutzer serverseitig gespeichert; Browserstorage bleibt nur Fallback.
Offene Punkte:
- Browser-E2E fuer Invite-Accept-Pfad.
- Weitere RTL-/AR-Politur.
- Eventuell Badge/Counter fuer offene Invites am VirtuProf-Avatar.
Naechste Schritte:
- Invite-Flow mit zwei Nutzern im Browser komplett testen.
- Gefundene Kanten klein fixen.
- Danach Produktentscheidungen fuer Invite-UX nachziehen.
Risiken/Unsicherheiten:
- learning-dev fuehrt App-Migrationen nicht verlaesslich automatisch aus.
- Weitere neue Migrationsdateien muessen dort vermutlich wieder manuell nachgezogen werden.
- Ein Teil der groesseren Features ist API-smoke-verifiziert, aber noch nicht jeder UI-Pfad ist hart E2E-abgehakt.
Verweise:
- HEAD lokal/remote/dev: `82753b8`
- Feature-Commit Duel-Invites: `32264e5`
- Deploy-Ziel: `learning-dev` / Container `learning-app`
