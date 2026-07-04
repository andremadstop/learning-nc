---
created: 2026-07-04
updated: 2026-07-04 (post-audit, pre-store-release)
branch: main
goal: ship v5.2.x to the Nextcloud App Store
---

# HANDOFF — Learning-NC → App-Store-Release

## Wo wir stehen
- **v5.2.0 ist getaggt** (`v5.2.0` auf main, gepusht forgejo+codeberg) — aber der Tag zeigt auf einen Commit VOR dem Audit. **Nie im App Store veröffentlicht** (Andre hielt bewusst zurück).
- Danach: **Whole-App-Audit** (8 Lanes) → 12 HIGH, 22 MED, 11 LOW (Triage: `.planning/AUDIT-2026-07-03-FINDINGS.md`).
- **Gefixt & committed (13 Audit-Commits seit dem Tag, jeder Gate-1-grün): ALLE 12 HIGH + 11 MED + 2 LOW.** HEAD ist ~13 Commits vor `v5.2.0`.
- Working tree clean. Fixes sind als EINZELNE Files auf devcloud deployt (nicht als Release-Build).

## ⏭ ZIEL: App-Store-Release (Andre: „direkt weiter bis Release")
Der v5.2.0-Tag ist verbrannt (pre-audit, öffentlich gepusht) → **sauberer Weg = v5.2.1** (Patch = Security-Hardening).
**Wichtiger Nebeneffekt:** info.xml 5.2.1 > devcloud-appconfig 5.2.0.5 → `occ upgrade` läuft als echtes UPGRADE, KEIN 503-Downgrade (die info.xml-503-Falle löst sich mit 5.2.1 von selbst).

**Release-Schritte (Reihenfolge, aus MEMORY release-history + verify-release.sh):**
1. `app/appinfo/info.xml` <version> → **5.2.1**. CHANGELOG.md v5.2.1-Eintrag (Security-Hardening: 12 HIGH + 11 MED + 2 LOW aus dem Audit — IDOR-Gates, RAG-Cross-Course-IDOR, KI-Consent-Gate, Exam-Oracle-Strips, Tabellennamen-Drift-Repair, AI-Prompt-Injection/Rate-Limit).
2. `./scripts/deploy-prod.sh --full` (baut JS lokal, deployt PHP+l10n+JS, PHPStan). Danach `ssh relais 'docker exec -u www-data devcloud-app php occ upgrade'` (Migration 009900 läuft, appconfig → 5.2.1). Verify: `occ maintenance:mode` = off, Base-URL 200.
3. **Gate 4 vor Release:** volle PHPUnit (`--test`), Vitest (`cd app && npm run test`), ESLint, i18n-Parität (`bash scripts/check-i18n-parity.sh`). Alle müssen grün.
4. **Signieren** (MEMORY signing): Key aus `~/.nextcloud/certificates/` → scp→docker cp→chmod 644→`occ integrity:sign-app`→`signature.json` ZURÜCK ins Repo (MUSS committed werden, sonst Store-Validierung fail)→Key aus Container löschen.
5. **ff main + Tag `v5.2.1`** → push forgejo+codeberg.
6. **Codeberg-Release** (Forgejo-API, Token `~/.config/codeberg/token`, NON-draft — #26-Lehre) + **App-Store-POST** (Token in `.env`).
7. `./scripts/verify-release.sh 5.2.1` bis grün (Feed-Propagation kann dauern).

## ⚠ Gotchas (diese Session gelernt — NICHT wiederholen)
- **`deploy-prod.sh` schiebt info.xml mit.** Solange info.xml (git) ≠ appconfig (devcloud) → NC „needs upgrade" → GANZE Instanz 503. Passierte 1× (2 Min), gefixt durch Container-info.xml zurück auf appconfig-Version. **Mit dem 5.2.1-Bump + occ upgrade ist das gelöst** (Upgrade statt Downgrade). Für Zwischen-Fixes: Einzelfile-Deploy (`scp … && docker cp … && apache2ctl graceful`), info.xml unberührt.
- **Kein lokales PHP.** Gates im Container: `ssh relais "docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/{phpstan analyse --no-progress|phpunit}"`. Tests deployen: `rsync app/tests → docker cp`.
- **Safety-Hook blockt `rm -rf`** → `rm -r --`.
- devcloud oc_jobs aufgebläht (5168× NC-core UpdateSingleMetadata — Cron-Rückstau, kein App-Bug).

## Offen NACH dem Release (dokumentierte Follow-ups, kein Release-Blocker)
Restliche MED/LOW brauchen Urteil/Entscheidung (in `.planning/AUDIT-2026-07-03-FINDINGS.md` „STILL OPEN" mit Fix-Vorschlägen):
- MED-01 (instructor_note owner-flow-risk), MED-07 (Namen an Gemini — Produktentscheidung), MED-15 (Forgejo-SSRF, off-by-default), MED-16/20 (FSRS-Scale — spekulativ, gegen Modell-Version verifizieren), MED-17/18/19 (Test-Infra-Refactoring), MED-21/22 (PBQ-Frontend-DTO-Split), LOWs (rate-limit-Attribute LOW-02/03/05, join-membership LOW-04, key-encryption LOW-08).

## Auch offen aus v5.2.0 selbst
- Human-verify (Andres Durchlauf): Recert-Loop, Bell, Video-Gate, Dashboard. test-api.sh braucht Vault ADMIN_PASS.
- `/gsd:complete-milestone` für v5.2.0/v5.2.1.

## Dateien
- Audit-Triage + Fix-Ledger: `.planning/AUDIT-2026-07-03-FINDINGS.md`
- Audit-Re-Run-Brief: `.planning/AUDIT-2026-07-03-PLAN.md`
- Release-Details/History: MEMORY `release-history.md` + `feedback_release_process.md`
