---
created: 2026-07-03
milestone: v5.2.0 Pflichtschulung
branch: feature/v5.2.0-pflichtschulung
resume_at: Phase 164 Wave 3 done — NEXT = Post-Impl-Codex-Review von RECERT-05 (164-04), dann Waves 164-05/06/07
---

# HANDOFF — v5.2.0 Pflichtschulung — Resume 2026-07-03

> Working tree clean (nur regenerierbare Build-Artefakte `app/css/learning.css`, `app/js/learning.{css,js}` uncommitted — beim nächsten Build/Deploy neu).
> Mandat: **vollständig autonom bauen bis v5.2.0 komplett**, stoppen nur bei Secret/Release/Blocker.
> Der finale **Release-Akt** wird dem User zur Freigabe vorgelegt (einziger Stopp).

## Meilenstein-Stand: 4/5 Phasen (80%)
- **160** ✓ · **161** ✓ · **162** ✓ · **163 Teamleiter-RBAC-Reports** ✓ (2026-07-02, 4-pass Codex SHIP)
- **164 Re-Zertifizierung + Retention + i18n** — IN ARBEIT, **4/7 Pläne** committed.

## ⏭ UNMITTELBAR NÄCHSTER SCHRITT: Post-Impl-Codex-Review von RECERT-05 (164-04)
- **NICHT übersprungen — nur wegen Rechner-Neustart vertagt.** Prompt-Vorlage: `scratchpad/codex-164-postimpl.txt` (scratchpad session-flüchtig → ggf. neu schreiben aus dieser HANDOFF + 164-04-Punkten unten).
- Befehl: `bash -c 'codex exec --sandbox read-only "$(cat <promptfile>)" < /dev/null'` (**bash, NICHT fish** — fish `(...)`-Substitution schlägt fehl).
- Reviewt den **echten implementierten Code**: wasCreated-Korrektheit, emit-only-on-winner, mayIssue-Union, closePeriod (expires_at=past + null cert active_idem_key, idempotent, per-user), DST computeExpiry, Atomicity, Regressionen.
- Findings → fixen mit lockendem Test, mehrere Pässe bis **VERDICT: SHIP** (163 brauchte 4).

## Phase 164 — Wave-Struktur
W1 164-01 (Schema/Migration 009600) ✓ · W2 164-02/03 (RED-Scaffolding) ✓ · W3 164-04 (RECERT-05, Codex-gated) ✓ IMPL · **W4 164-05/06/07 OFFEN.**

### 164-04 (RECERT-05 „open-heart surgery" — Design 3× Codex-bestätigt, IMPL fertig, Gate1 grün)
- **Guard** `PassCriteriaService::mayIssue()`: Branch A `hasEverIssuedCertificate()` (findByUserAndCourse UNFILTERED — revoked/expired zählt → kein Auto-Reissue nach punitivem Revoke) OR Branch B (offene per-user Period: `active_period_key IS NOT NULL AND status IN ('assigned','in_progress','overdue')` — Allow-List, NICHT `!= passed`).
- **evaluate() reordered:** gate mayIssue → issueIfPassedResult ZUERST → COURSE_PASSED + markPassed NUR bei `wasCreated=true` (CAS-Winner = active_idem_key UNIQUE-Insert). Loser emittiert nichts.
- **issueIfPassedResult()** → `IssueResult{cert, wasCreated}`. **computeExpiry()** DST-safe `modify('+N months')` (N = override ?? course.cert_validity_months ?? 12; ≤0 → null).
- **closePeriod()** 3 writes: cert `expires_at=now-1` + `active_idem_key=NULL` (revoked UNBERÜHRT → verify „expired" nicht „withdrawn"); assignment `active_period_key=NULL`; INSERT fresh row (catch UNIQUE = idempotent). `logComplianceEvent(PERIOD_CLOSED)`.
- **Gate 1:** PHPStan 20 benigne Transiente (alle in 164-05/06/07-Skeletons; kein neuer echter Fehler). PHPUnit: **alle 7 RECERT-05-Locking-Tests GRÜN**; nur 3 RED = spätere Waves.

### OFFENE Waves (jede: Impl → Gate1 → ggf. Codex)
- **164-05** RecertPeriodCloseJob (daily TimedJob, ruft closePeriod) → flippt `testDoubleRunSingleRow`. Registrierung schon in Application::boot() (164-03).
- **164-06** T-30/T-7 Reminders (RecertReminderService, insert-on-fire `learning_recert_reminders` UNIQUE(cert_id,threshold_days)) + Verify-Lifecycle-States → flippt `testOncePerThreshold`.
- **164-07** Retention crypto-erasure (null user_id + scrub credential_json + `anonymized_at`, audit-chain bleibt verifizierbar) + 5-Sprachen-i18n (`RecertL10n.test.js` + `l10n_js_sync.py`) + Betriebsvereinbarung/Permanence-Docs → flippt `testAnonymizeKeepsChain` + i18n-Parität.
- **Danach:** STATE/ROADMAP/REQUIREMENTS **manuell** (gsd-tools korrumpiert Frontmatter) → Phase 164 complete → Meilenstein inhaltlich fertig.

## Release-Akt v5.2.0 (USER-FREIGABE — hier stoppen)
info.xml `5.2.0.3`→**`5.2.0`** · CHANGELOG · ff `main` · Tag `v5.2.0` · Codeberg-Release (Forgejo-API, Token `~/.config/codeberg/token`) · App-Store-POST (Token in `.env`) · `./scripts/verify-release.sh 5.2.0` · Key-Hygiene.
**Human-verify (Andres Durchlauf, kein Blocker):** Live-IDOR-curls (test-api.sh braucht Vault ADMIN_PASS/SECOND_PASS), Notification-Bell, Video-Gate live, Re-Cert-Loop, Dashboard-Optik. Jan (AWO #20). AWO-Betriebsvereinbarung BetrVG §87 (organisatorisch).

## Gotchas (diese Session)
- **API-Stalls häufig** (mid-stream, ~13 Tool-Uses = Lesephase): Executor committet nichts → deterministischer Retry (meist 2.–3. Versuch); bei delikatem bounded Work ggf. selbst inline.
- **Commit-Race** bei parallelen Executors (geteilter Worktree) → Waves **sequenziell**; Prompt: „NEVER git add -A".
- **Kein lokales PHP** → Deploy+Gate1 zentral. `--php-only`=deploy+PHPStan, `--test`=PHPStan+PHPUnit (deployt NICHT). PHPUnit direkt: `rsync tests → docker cp → docker exec phpunit`. Migration: info.xml-Bump + `occ upgrade`.
- **observed-RED:** transiente PHPStan-„never read" bis impl-Wave; beim Gate auf **Nicht-„never read"** filtern.
- **Codex via bash -c** (nicht fish). Pre-Impl-Design-Gate lohnt bei open-heart surgery (fing 6 Löcher vor Code).

## Kontext-Dateien
- Pläne/Research/Validation/SUMMARYs: `.planning/phases/164-rezertifizierung-retention-i18n/` (gitignored — nur lokal!)
- `.planning/STATE.md` (current_phase 164, 4/7) · `.planning/ROADMAP.md`
- Memory: `project_v52_pflichtschulung.md`
