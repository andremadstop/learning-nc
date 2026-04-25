# Inbox

> Parkplatz für Zwischenrufe, Folgeaufträge und Vorschläge die während laufender GSD-Phasen aufkommen.
> Wird vor jeder Phase-Transition geprüft und abgearbeitet.

## Offen

- [2026-04-25] **GitNexus npx-fetch broken** — Während Plan 152-02 Execute schlug `npx gitnexus analyze` fehl mit `Cannot destructure property 'package' of 'node.target' as it is null` (npm v11.x Bug bei tree-sitter-dart SSH-git-Dep). Cached `.npm/_npx/*/gitnexus/` Verzeichnisse sind leere Shells. Workaround: GitNexus-Index bleibt stale bis npm-tree fixiert oder gitnexus global via `npm i -g gitnexus@1.6.3 --force` installiert wird. PostToolUse-Hook warnt nur, blockiert keine Plans.
- [2026-04-25] **GSD-Tools Bug — `state advance-plan` clobbert milestone-scoped frontmatter**: Beim Plan 152-02 close hat `gsd-tools state advance-plan` zwar `current_plan: 2 → 3` korrekt gesetzt, aber STATE.md Frontmatter komplett umgeschrieben: `milestone: v4.4.0 → v2.3`, `total_phases: 5 → 13`, `completed_phases: 3 → 10`, `total_plans: 18 → 37`, `status: phase-151-... → completed`. Init-JSON zeigte `milestone_version: "v2.3"` — Tool greift offenbar auf erste ROADMAP-Milestone-Zeile zu statt aktive. Manuell repariert. Fix: Tool muss aktive Milestone aus PROJECT.md/STATE.md lesen, nicht aus ROADMAP-Reihenfolge.
- [2026-04-25] **Phase 151 Post-Deploy Findings — API/Smoke**: `cd app && npm run test -- --run` gruen (`73` Test-Files, `1036` Tests). `bash scripts/test-api.sh skin-preference` ist als Gesamtscript implementiert; der `skin-preference` Arg wird aktuell nicht als Filter ausgewertet. Lokaler Lauf in Sandbox scheiterte am Playwright/Chromium-Sandbox-Start (`sandbox_host_linux.cc Operation not permitted`). Eskalierte DevCloud-Laeufe erreichten Login, aber die dokumentierten Kandidaten `admin/admin` und `testuser2/test123` wurden abgewiesen (`Kontoname oder Passwort falsch`), daher konnte der Skin-Roundtrip-Block nicht fachlich ausgefuehrt werden. DevCloud-Smoke-Walkthrough aus `151-07-PLAN.md` Task 3 ebenfalls blockiert durch fehlende gueltige Test-Session; die 7 Checks bleiben offen: Picker sichtbar, 2 Optionen, Prof.-Lern-Swap ohne Reload, Gaze, Wave-Autohide, Persistenz nach Reload, Rueckwechsel zu NOVA.
- [2026-04-24] ✓ **Bug: Multi-Select triggert nach 1. Klick** — Root Cause: 16 Questions mit falschem `question_type` (Pool 124 'multiple' statt 'multi' × 3; Pool 138 'single' mit multi-correct × 5; Pool 139 × 8). Gefixt in Commit `dc21430` (DB-Update + Migration Version007800 + LeitnerService Runtime-Normalize).
- [2026-04-24] ✓ **Bug 2 Content-Cleanup komplett** — Total 68 Answers gefixt über Pools 138+139+144. Endstand LENGTH>80: Pool 138 40→13, Pool 139 20→18, Pool 144 101→61. Pool 135+124 sind clean (Gemini sagt: kein Bug). Noch 3 echt ambiguous Items als Follow-up dokumentiert: 58261 (Multi-line Access-List — evtl. legitim), 58798 (SLEALE = SLE+ALE zusammengeklebt), 60675 (Typosquatting+Spear-phishing zusammengeklebt). Reports: `scripts/cleanup_pool{138,139,144}_report.json`.
- [2026-04-08] **PBQ Simulationen N10-009**: 6 Configs in DB, Logik korrekt, visuelle Originaltreue noch offen. User will nochmal durchklicken.
- [2026-04-02] **Security Hinweis**: Schema-Drift analysiert — 4 Findings (BIGINT length, inkonsistente Timestamps, fehlender FK user_answers.answer_id). Kein akutes Risiko, bei naechster Migration aufraemen.
- [2026-04-08] ~~**test-api.sh modernisieren**~~: Erledigt — 25→92 Assertions (~40% Coverage). Committed 92eec14.
- [2026-03-29] **Gemini-Vision**: 5 Zukunfts-Konzepte in `.planning/gemini/` — Squad Identity, Course Archetypes, Teacher Architect, Pedagogical Modes. Fuer Post-v3.8.0 evaluieren.

## Offen (alt)

- [2026-03-29] **Kursende-Experience Konzept** — Verschiedene Optionen fuer verschiedene Zielgruppen. Klassenbuch-Akzeptanz unklar.
- [2026-03-29] **DSGVO-Audit** — Infopflicht pruefen fuer neue Features (Schwarm, Gemini, Telos, Buddy). Formale Datenschutzerklaerung fuer DevCloud?
- [2026-03-28] **PWA/Homescreen-Anleitung** — im DevCloud Dataspace bereitstellen
- [2026-03-24] **Binary Tab Bug** — Clone-Workaround existiert, verifizieren ob noch relevant nach Vue Router (Phase 121)
- [2026-03-24] **Werkzeuge-Tab Design-Konsolidierung** — 8 Simulatoren einheitlich designen. Codex-Handoff bereit.
- [2026-03-24] **Nextcloud Settings Redesign** — Hintergrundbild, Standardsettings, Branding. Idee.
- [2026-03-22] **Honeypot-Idee** — Security-Kurs Easter Egg. Ideenphase.
- [2026-03-30] **Security+ PDF → Vault-Kurs** — Gemini-Extraktion, analog N+

<!-- Format: - [DATUM] **Quelle**: Inhalt — Max 10 Items (SR-13) -->

## Erledigt

- [2026-04-08] ✓ **Screenshots**: 9 neue Screenshots (Admin + User), committed ddd18b8. Alte 8 ersetzt.
- [2026-04-08] ✓ **VirtuProf Truncation**: max_output_tokens 1800→2400 (detailed 2048→3200), committed ddd18b8.
- [2026-04-08] ✓ **Onboarding Emoji-Bug**: \u2B50 Literal → echtes ⭐ in OnbHook.vue, committed ddd18b8.
- [2026-04-08] ✓ **Daily-Goal Spacing**: gap 2px→6px in PoolList.vue, committed ddd18b8.
- [2026-04-08] ✓ **Fragensuche Feature**: Volltext + Nummernsuche implementiert. Backend: QuestionMapper OR-Suche (ID + Text). Frontend: #ID im Dropdown. Deployed + getestet.
- [2026-04-08] ✓ **Phase 121 router-view**: Komplett erledigt seit Phase 129 (Vue 3). router-view in App.vue, v-if nur fuer lokale Mega-Tabs.
- [2026-04-08] ✓ **Schema-Drift analysiert**: 4 Findings dokumentiert (BIGINT length, Timestamps, FK). Kein akutes Risiko.
- [2026-04-08] ✓ **NotebookLM MCP Version**: 0.5.16 → 0.5.17 upgraded
- [2026-04-08] ✓ **E2E CI**: i18n-Fix erfolgreich — E2E + Security Regression beide gruen
- [2026-04-08] ✓ **Gemini App Store Listing**: Bereits mit v4.1.0 aktualisiert (veraltet)
- [2026-04-02] ✓ **Sprint C → v3.8.0 Milestone**: SSE, Pinia, Vue Router, Redis, Push-Notifications, Content Audit — als Phasen 119-124 angelegt
- [2026-04-02] ✓ **v3.7.0 Scope-Entscheidung**: 7-Perspektiven komplett adressiert (A11Y, Didaktik, Kampagnen, Placement SVGs, CLI Feedback, streak_14)
- [2026-04-02] ✓ **NLM-Sync**: 41→45 Quellen, 3-Schichten-Architektur (Snapshots + pyragify + Docs)
- [2026-03-29] ✓ **Codex Phase 113 ICS-Backend**: Implementiert (IcsController, IcsService, Migration)
- [2026-03-29] ✓ **Codex v3.6.0 Ergebnisse**: Tab-Decomposition + Badge-Migration genutzt
- [2026-03-29] ✓ **Gemini v3.6.0 Handoff**: Privacy-Info, Narrative-Prompt, Erklaerbot-UX — alles integriert
- [2026-03-29] ✓ **Gemini-UX**: Tab-Groups, Badge-Audit, Abenteuer-Streamlining — umgesetzt in v3.6.0-v3.7.0
- [2026-03-29] ✓ **Delegation**: Codex/Gemini-Handoffs abgearbeitet
- [2026-03-28] ✓ **RAG Knowledge Features**: Dozenten-Import UI + Schwarm-Moderation + Quellentransparenz — alles gebaut
- [2026-03-24] ✓ Subnetzrechner UX, Beispielszenarien, IP-Eingabefeld, Umlaute, Binär-Rechnung
- [2026-03-22] ✓ VirtuProf Redesign, Invite Dismiss, KI-Erzähler, NC Files Integration, CI+Charaktere
- [2026-03-23] ✓ Security-Review, Kampagnen-Bug, PBQ Terminal, STAS-Vaults, Kursregeln
- [2026-03-24] ✓ LernbotFiles Fix, Werkzeuge-Toggles, VirtuProf Mobile UX, Guide-Modus, Firefox TTS
- [2026-03-26] ✓ VirtuProf Token-Fix, Duell-Invite Dismiss
- [2026-03-27] ✓ Yolo-Mode Phasen 87-89 (Talk, Manifest, Cross-App)
- [2026-03-29] ✓ Kurs-API 403, E2E Mission Claim, CourseController Error-Handling, VirtuProf l10n
