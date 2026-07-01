---
created: 2026-07-01T20:15:00+02:00
milestone: v5.2.0 Pflichtschulung
branch: feature/v5.2.0-pflichtschulung
resume_at: Phase 162 (planning) — Video-/Material-Gating
author: Claude (Opus 4.8 1M) — autonomous run
---

# HANDOFF — v5.2.0 „Pflichtschulung", resume bei Phase 162

> Fresh-context-Übergabe im autonomen Milestone-Lauf. Alles committet, working tree clean bei `d14e193`.
> **Release-Entscheidung getroffen (2026-07-01): User will „weiter bis v5.2.0 komplett" — 162→163→164 autonom, dann Go für Release-Akt.**

## 🎛 Autonomie-Vertrag (vom User freigegeben — „YOLO / vollständig autonom bis fertig")
- **Ohne Rückfrage durchlaufen.** An Phasengrenzen **informieren** (nicht fragen). **Stoppen NUR bei:** (1) Secret/Extern nötig (Forgejo-Anker-Token), (2) Release/Prod/`main`-Merge/Tag/App-Store (bewusste irreversible Akte → am Milestone-Ende dem User vorlegen), (3) echter Blocker.
- **3-KI-Team-Modell:** Claude orchestriert. **Gemini** (`fabric --model gemini-2.5-pro < input`) = Design/Completeness-Review der Pläne VOR dem Bauen. **Codex** (`codex exec --sandbox read-only "<prompt>" < /dev/null`) = Security-Review des security-kritischen Codes NACH dem Bauen. Gate-Reihenfolge je Phase: fabric→Gemini→Codex.

## 📍 Stand
- Milestone v5.2.0, Branch `feature/v5.2.0-pflichtschulung` (off `main`). **2/5 Phasen (40%).**
- **Phase 160 COMPLETE + verifiziert (12/12)**, commit `f71ce32`. Audit-Hash-Chain + Assignment-Fundament.
- **Phase 161 (Audit Hardening) COMPLETE + verifiziert (6/6 automated)**, commit `d14e193`. Ed25519-Checkpoints (Migration 009302) + Forgejo-Anker + `occ audit:verify` + Auditor-Export + Liveness-Widget + Fork-Runbook. **Grumpy-Codex: 7/7 Security-Funde gefixt** (F1 prev_hash-BLOCKER..F7). Gates: PHPStan L5 clean, PHPUnit 222/768, live occ-verify exit 0. **3 human-verify offen** (Andres Durchlauf: Live-Checkpoint-Mint auf non-empty Chain, Overdue-Banner-DOM, Export-UI-Optik).
- devcloud: `learning 5.2.0.1` (Dev-Bump für Migration 009302; **beim Release auf 5.2.0 zurücksetzen**). PG16. Gruppe `learning-auditors` angelegt.
- LPIC-1-Prüfung Andre **03.07.** (Ghostline v5.1.0 pausiert, eigener Branch, unangetastet).

## ▶ RESUME — nächste Schritte
1. **`/gsd:plan-phase 162`** (Video-/Material-Gating + DSGVO Art.13, VIDEO-01..09 + DSGVO-04). Frischer Kontext empfohlen (`/clear` zuerst) — 162 ist die komplexeste Phase.
2. Pro Phase die **Pipeline** fahren (s.u.). 162 ist security-kritisch (Streaming-Auth, Gating) → **Codex-Review Pflicht**.
3. Dann 163 (RBAC-Reports), 164 (Re-Zert). Milestone-Ende → **Release-Akt dem User vorlegen** (info.xml→5.2.0, CHANGELOG, ff `main`, Tag, Codeberg, App-Store) — NICHT autonom.

## 🔁 Per-Phase-Pipeline (der autonome Loop)
`plan-phase` (gsd-phase-researcher konsolidiert vorhandene Research → VALIDATION.md manuell → gsd-planner → **parallel** gsd-plan-checker + Gemini-Plan-Review → 1 gezielte Revision) → `execute-phase` (gsd-executor je Plan, Wave-parallel) → **zentraler Gate 1** (deploy + PHPStan + PHPUnit) → **Codex-Security-Review** (bei security-kritischen Phasen) → gsd-verifier → complete (STATE/ROADMAP/REQUIREMENTS **manuell**).

## ⚠ KRITISCHE Mechanik (diese Session hart gelernt — nicht neu erleiden)
- **Container-Test-Teilung:** PHPStan L5 + PHPUnit laufen NUR im devcloud-Container (**lokal KEIN PHP-Binary**). Executor **schreiben+committen nur** (kein deploy/ssh/php-l → keine Parallel-Deploy-Races). **Orchestrator deployt + fährt Gate 1 zentral pro Wave.**
- **Deploy:** `./scripts/deploy-prod.sh --php-only` = deploy lib/ + PHPStan (deploy_php läuft VOR phpstan → Code ist deployed auch wenn PHPStan abbricht; der „Class OCP\AppFramework\App not found"-Fatal beim Deploy-Verify ist ein Artefakt, IGNORIEREN). `--test` = PHPStan + volle PHPUnit. **run_phpunit wurde diese Session gefixt** (synct jetzt ganzes tests/ in den Container; vorher nur 3 Support-Dateien → stale Tests).
- **PHPStan scannt nur lib/** (Migrationen + Tests exkludiert) → per-Wave-PHPStan inkrementell clean; RED-Stubs die Zukunfts-Klassen referenzieren brechen PHPStan NICHT. **PHPUnit-GREEN erst am Phasenende.**
- **Unit-Tests laufen gegen STUBS** (`app/tests/Support/PhpUnitStubs.php` = handgeschriebene OCP-Interfaces), NICHT echtes NC. Neue OCP-Fläche (Klassen/Methoden) MUSS in PhpUnitStubs ergänzt werden, sonst fatalen die Tests (uns getroffen: IJobList, QueuedJob, createUser, getEMailAddress, IGroup, QueryBuilder-Methoden).
- **Migrationen:** brauchen info.xml `<version>` > installiert um via `occ upgrade` zu laufen (kein `migrations:execute` in dieser NC). **info.xml ist auf 5.2.0** (diese Session gebumpt). Neue Migration → `occ upgrade`. Schema prüfen: `ssh relais 'docker exec devcloud-db psql -U oc_admin -d nextcloud -c "\d oc_learning_..."'`.
- **gsd-tools state/roadmap Mutations-Commands KORRUMPIEREN Frontmatter** → STATE.md/ROADMAP.md **manuell** editieren. (init / roadmap get-phase / phase-plan-index sind read-only, safe.)

## 🔐 Phase-161-Specifics (locked in 161-RESEARCH.md)
- **Checkpoint-Signing:** `KeyService::getActiveSigningMaterial()` → `sodium_crypto_sign_detached` (NICHT `SigningService::sign()` — Header frozen typ:vc+jwt, ADR-155). `signed_payload TEXT` für exakt-Bytes-Verify.
- **`occ learning:audit:verify` (AUDIT-06) MUSS die FROZEN 6-Feld-Canonical rekonstruieren** (aus `AuditService::logComplianceEvent`): `ksort({seq,event_key,user_ref,course_id,created_at,payload_hash})` → `json_encode(UNESCAPED_UNICODE|UNESCAPED_SLASHES)` → `sha256($canonical.'|'.$prevHash)`. Regeln: `user_ref` aus Spalte (NIE neu berechnen — DSGVO-Löschung nullt user_id); `payload_hash`=sha256(RAW context_json); `course_id`=json_decode(context_json)['course_id']. **Sonst meldet verify alles als manipuliert.**
- **Export (AUDIT-07):** JSONL + detached `.sig` (sodium über exakte JSONL-Bytes) + HTML-`@media print` (window.print, null Deps). Gate: `@NoAdminRequired` + `isInGroup('learning-auditors')` (NICHT admin).
- **Forgejo-Anker (AUDIT-05):** OFF by default, `anchor_url` nullable, soft-fail (DB-first), **Token kommt LATER vom User** — nur scaffolden. Migration **Version009302**.

## 🗺 Restliche Phasen
- **161** Audit Hardening (AUDIT-04..09) — in Planung
- **162** Video-/Material-Gating + DSGVO Art.13 (VIDEO-01..09, DSGVO-04) — `VideoStreamController` (`IRootFolder->getUserFolder(instructorId)->fopen` + Range 206) ist der Crux; server-seitiges Gate in `TrainingService::startSession()`; DSGVO-transiente Segmente; Heartbeat-Throttle; a11y; Vimeo/YT-nocookie+Consent. Vimeo/YT SDK via CDN (0 npm-Deps).
- **163** Teamleiter-RBAC-Reports + Art.20 (RBAC-02..04, DSGVO-02) — `CertificateReportService::getGroupReport()` IDOR-safe auf `learning_oversight` (schon in P160 angelegt); assertTeamLeadForGroup FIRST + DB-Level-Filter.
- **164** Re-Zertifizierung + Retention + i18n (RECERT-01..07, DSGVO-03/05) — **RECERT-05 Guard-Redesign = PFLICHT-Codex-Review** (OP am offenen Cert-Herzen; `PassCriteriaService::emitPassEventIfFirst` prüft active_period_key statt „je bestanden"; `RecertPeriodCloseJob`). DST-safe `DateTimeImmutable::modify('+1 year')`. Betriebsvereinbarungs-Hinweis für AWO.

## 🏢 Parallel: UG-Session (Business-Layer)
- Compliance-Schulung-as-a-Service, App bleibt FOSS. **App↔UG-Kanal:** `~/ObsidianVaults/Personal/Projekte/Learning-NC/App-Requirements-Compliance-Business.md` (hat „⬅ Rückkanal-Update" mit AVV/Betriebsrat/Barrierefreiheit/Retention/Auditor-Export). UG-Konzept kanonisch: `UG-Souverain/Strategie/strategie/32-Compliance-Schulung-Service-Konzept.md`.
- **Jan (AWO Issue #20)** hat geantwortet, wird warm gehalten → Rückmeldung sobald Video-Gating (Phase 162) steht.

## 📄 Artefakte
- Research: `.planning/research/{STACK,STACK-FOUNDATIONS,FEATURES,ARCHITECTURE,PITFALLS,SUMMARY}.md` (v5.2.0). Ghostline-Research archiviert unter `research/_v5.1.0-ghostline-archive/`.
- Reviews: `.planning/reviews/2026-07-01-gemini-v52-{concept,roadmap}-review.md`.
- Phase 160: `.planning/phases/160-foundation-audit-assignment/` (6 PLAN+SUMMARY, VERIFICATION 12/12).
- REQUIREMENTS.md: 41 Reqs, 12 (Phase 160) abgehakt. ROADMAP.md: Phase 160 ✓, 6-Feld-Canonical-Forward-Dep in Phase-161-Notes.

## ⓘ Sonstiges
- GitNexus-Index stale (non-blocking; direkt gereadet/gegreppt statt reindext).
- Codex-Model = gpt-5.5 (reasoning high). Gemini via fabric = `gemini-2.5-pro` (stabil; 3.x-Previews vorhanden aber ungenutzt).
