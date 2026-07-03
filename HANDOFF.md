---
created: 2026-07-03
milestone: v5.2.0 Pflichtschulung
branch: feature/v5.2.0-pflichtschulung
resume_at: MEILENSTEIN INHALTLICH FERTIG (5/5 Phasen, 164 = 7/7 + 4 Codex-Pässe SHIP) — NEXT = Release-Akt v5.2.0, NUR MIT ANDRES FREIGABE
---

# HANDOFF — v5.2.0 Pflichtschulung — Stand 2026-07-03 abends

> Working tree: nur regenerierbare Build-Artefakte (`app/css/learning.css`, `app/js/learning.{css,js}`) + lokale gitignorte `.planning/phases`-Docs uncommitted.
> **Mandat war: autonom bis v5.2.0 komplett, Stopp nur bei Secret/Release/Blocker → Release-Stopp ERREICHT.**

## Meilenstein-Stand: 5/5 Phasen (100% inhaltlich)
160 ✓ · 161 ✓ · 162 ✓ · 163 ✓ · **164 ✓ COMPLETE 2026-07-03** (7/7 Pläne).

## Phase 164 — was heute passierte (Session 2026-07-03)
1. **Post-Impl-Codex-Review 164-04** (RECERT-05-Surgery): Pass 1 = 7 Findings (2 BLOCKER: closePeriod nicht idempotent + non-atomic strand; 3 HIGH: cert-ohne-COURSE_PASSED, stilles 12-Monats-Default, PERIOD_CLOSED-PII; 2 MED) → alle gefixt (closePeriod CAS auf certId + EINE TX; evaluate() outer TX; notify best-effort; computeExpiry legacy-days-Fallback; EOM-Clamp; @deprecated issueIfPassed) → Pass 2 **SHIP**.
2. **Wave 164-05**: RecertPeriodCloseJob + closeExpiredPeriods (cert-driven Query, per-row-isoliert; Write-Split CAS/Expiry-Clamp — historisches expires_at bleibt).
3. **Wave 164-06**: deriveLifecycleState (anonymized/valid/expiring/expired/overdue — overdue=NACH Grace, Plan autoritativ), anonymized-Tombstone-Branch im Verify, T-30/T-7-Reminders, Notifier recert_reminder (msgids = RecertL10n-Keys).
4. **Wave 164-07**: Retention crypto-erasure (Scope-Guard: nur active_idem_key IS NULL altert aus), 10+17+4 i18n-Keys × 5 Sprachen (Parität 2298), README-Compliance-Sektion (RECERT-07-Permanenz + BetrVG §87).
5. **Codex-Review Waves 05-07, 4 Pässe → SHIP**: P1 = 6 Findings, darunter **NOT-NULL-Schema-BLOCKER** (user_id/subject_id) → **Migration 009700**; P2 = Outbox-Pattern (delivered_at, **Migration 009800**) statt Delete-Kompensation; P3 = atomarer **reclaimStale-CAS** gegen Doppel-Bell-Race; P4 = FIX-CONFIRMED, SHIP.

## Gates (final)
- PHPUnit **317/317** · PHPStan **clean (0)** · Vitest **1220/1220** (86 Suiten) · ESLint 0 Errors · i18n-Parität 2298×5 + js-sync.
- Migrationen **009600/009700/009800 live** auf devcloud, info.xml **5.2.0.5**, alle 7 Learning-Jobs in oc_jobs registriert, Public-Verify 200.
- Gate 2 (test-api.sh, 92 Assertions) = **Human-verify**: braucht Vault ADMIN_PASS (DevCloud-Zugangsdaten.md — auf Workstation aktuell nicht gefunden, evtl. LiveSync/cockpit).

## ⏭ RELEASE-AKT v5.2.0 (NUR MIT FREIGABE — hier gestoppt)
info.xml `5.2.0.5`→**`5.2.0`** · CHANGELOG · ff `main` · Tag `v5.2.0` · Codeberg-Release (Forgejo-API, Token `~/.config/codeberg/token`) · App-Store-POST (Token `.env`) · `./scripts/verify-release.sh 5.2.0` · Key-Hygiene · danach `/gsd:complete-milestone`.

## Human-verify (Andres Durchlauf, kein Release-Blocker)
Live-IDOR-curls/test-api.sh (Vault-Creds) · Notification-Bell (recert_reminder) · Video-Gate live · Re-Cert-Loop end-to-end · Dashboard-Optik · Jan/AWO (#20) · Betriebsvereinbarung (organisatorisch, README dokumentiert).

## Follow-ups (nach Release, INBOX)
- cert_validity_months **UI-Feld** (API fertig; Clear-auf-NULL vorsehen). Per-Assignment-Override = Signatur-Seam ohne Datenpfad.
- retention_years=3 mit AWO/DSB bestätigen (FLAGGED in Code+README).
- devcloud Ops: oc_jobs aufgebläht (5168× NC-Core UpdateSingleMetadata) — Cron-Rückstau prüfen.
- RecertL10n.test.js-Kommentar hat overdue/expired-Labels vertauscht (nur Kommentar; Übersetzungen folgen Plan-Semantik).

## Gotchas (Session-Learnings)
- Codex-Review-Kaskade lohnt MASSIV: 14 echte Findings über 6 Pässe, davon 1 Schema-BLOCKER den Unit-Tests (Mocks!) nie sehen konnten. Muster: mock-basierte Tests + Live-Constraint-Checks gehören zusammen.
- deploy-prod.sh `--test` bricht bei PHPStan-Transienten ab BEVOR PHPUnit läuft → PHPUnit direkt (rsync tests → docker cp → docker exec phpunit).
- occ `background-job:list` paginiert (500) — oc_jobs direkt in PG prüfen (User `oc_admin`, DB `nextcloud`).
- Node 22 (Reboot-Update) brach 1 Vitest-Suite via NcDialog-dist-CSS → @nextcloud/dialogs-Mock (Muster StudentDashboard.test.js).
- check-i18n-parity.sh war an Newline-Key kaputt → python-set-diff; dabei 17 vorbestehende 163er-Lücken in fr/ru/ar gefunden+gefüllt.
- NC Job-Basisklasse exponiert `$time` NICHT an Subklassen → eigene Property.

## Kontext-Dateien
- SUMMARYs/Pläne: `.planning/phases/164-rezertifizierung-retention-i18n/` (gitignored, nur lokal!)
- `.planning/STATE.md` (100%, Release-Akt offen) · ROADMAP (164-Zeile komplett) · REQUIREMENTS (alle 41 ✓)
- Codex-Protokolle: `scratchpad/codex-164-*-OUTPUT.txt` (session-flüchtig)
- Memory: `project_v52_pflichtschulung.md`

---

## ⚠ NACHTRAG 2026-07-03 ~15:00 — Ganzheitlicher App-Audit angestoßen, ABGEBROCHEN
Andre (nach Fable-5-Umstellung mitten im Bau): gesamte App + Hilfsfiles vom besten Modell prüfen lassen.
7 Review-Agents gestartet → **ALLE 7 sofort ins accountweite Session-Limit (Reset 19:00), 0 Ergebnisse.**
**→ In frischer Session ≥19:00 neu starten. Vollständiger Auftrag + 8 Agent-Prompts + Triage-Regeln:
`.planning/AUDIT-2026-07-03-PLAN.md`.** (8. Agent = Doku/Hilfsfiles war noch nicht gestartet.)
CLAUDE.md Header-Zeile Vue2.7→3.5.32 bereits gefixt. v5.2.0 selbst = fertig+getaggt (nichts offen dadurch).
Build-Artefakte im Stash `stash@{0}` (regenerierbar, kein Verlust).
