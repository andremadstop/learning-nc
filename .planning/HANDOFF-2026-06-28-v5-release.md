# HANDOFF — v5.0.0 Certification: RELEASE-READY, paused for Andre's course run-through

**Stand:** 2026-06-28 Abend. Branch `feature/v5.0.0-certification`. Working tree committed.
**Nächster Schritt:** Andre geht morgen den Demo-Kurs selbst durch → finales Go → **öffentlicher v5.0.0-Release** (Schritte unten).

## TL;DR
v5.0.0 ist **feature-complete + voll live-verifiziert + von zwei KI-Gates abgenommen**. Der einzige offene Schritt ist der **öffentliche Store-Release** — bewusst pausiert, weil Andre den Kurs erst selbst durchgehen will. Nichts wurde publiziert.

## Was heute passierte (chronologisch)
1. **Phase 157 geschlossen** (Notbremse-Reste aufgeräumt) → v5.0.0 feature-complete (154–157).
2. **Demo-Kurs „I am not an idiot test" gebaut**: brainstorm → Spec → Plan → 18 MCQ DE+EN, Multi-KI-Gate (fabric→Gemini→grumpy Codex) → SHIP. (`docs/superpowers/specs|plans/2026-06-28-*`)
3. **Provisioning-Pass (live, autorisiert)**: occ upgrade (Version009200 `revoked_at` live, info.xml **4.4.9**), 2 Pools, 2 Kurse, echter Cert über die Pipeline. **Alle 6 VERIFY live-grün** (03 DOM-Leak, 04 indep. Ed25519, 05 Revoke→withdrawn, 06 429).
4. **Pre-Release-Review, normale Rollen**: Codex (Code) fand BLOCKER (Expiry trust mutable DB statt signed validUntil) → **gefixt** (`103489c`, +Regressionstest).
5. **Rollen-Swap-Review (Andre-Wunsch)**: Gemini (Code) = **GO**; grumpy Codex (UX) = **NO-GO** → echte Findings.
6. **Voller Politur-Durchgang** (Andre wählte das + professionellen Titel):
   - **verify.php**: status-aware Badge, WCAG-Farben (valid #2e7d32 / withdrawn #8a5a00), „beweist/beweist nicht"-Block, unknown-Handlungshinweis, RTL-Isolation, „Signatur". i18n ×5.
   - **Titel + Re-Mint**: Kurse/Pools → „Internet-Mündigkeit 2026 — I am not an idiot test" / „Internet Street-Smarts 2026 — …"; beide Certs neu gemintet mit signiertem Titel.
   - **Certificate.vue**: A4-Print (border-box/165mm), QR 30mm, prominente Cert-ID, a11y, CTA. ESLint 0, gebaut, deployed.
   - **Content**: EN „seller details" statt „legal imprint", Romance-Framing entschärft. Source+live.
   - → **beide Gates GO** (Gemini Code + grumpy Codex UX). 8 Fix-Commits.
7. **Store-Screenshot**: verify-Seite als `screenshots/07-certificate-verify.png` + info.xml-Eintrag.

## LIVE-Zustand auf devcloud (relais)
| Objekt | Wert |
|--------|------|
| info.xml | **4.4.9** (Migration-Vehikel; →5.0.0 beim Release) |
| Migration Version009200 | live appliziert (`revoked_at` auf PG16) |
| Pools | 165 „Internet-Mündigkeit 2026 (DE)", 166 „… Street-Smarts (EN)" (owner andre) |
| Kurse | **62** (DE) + **63** (EN), andre-owned, cert 70%/365d |
| Demo-Cert DE | id 4, vid **5362f079-8c6f-4f16-ab14-e3de3bb6df1f**, VALID |
| Demo-Cert EN | id 5, vid **cc8f7e65-0571-43fe-9a95-1e224806c95b**, VALID |
| Student (Demo, hat Cert) | `demo-idiottest` (PW: relais `/tmp/idiottest-stu-pw.txt`) |
| Student (Andres frischer Test) | `andre-learner` — eingeschrieben 62+63, **Gate-2 vorgeseedet**, **0 Certs** (Exam offen) |

## ANDRE — so gehst du morgen den Kurs durch (Konto `andre-learner`)
1. Als DevCloud-Admin: Einstellungen → Administration → Benutzer → „André (Test)" / `andre-learner` → **Passwort setzen**.
2. Privates Browserfenster → https://devcloud.andrestiebitz.de → login `andre-learner`.
3. Kurs „Internet-Mündigkeit 2026 — I am not an idiot test" → **Training** (Inhalt) → **Prüfung** (18 Fragen, ≥70 %).
4. Bestehen → **Cert mintet live** (Gate 2 vorbefüllt) → „Zertifikat ansehen" → polierte Karte (Print/QR/Cert-ID/LinkedIn). QR scannen → öffentliche verify-Seite.
5. ⚠ Auf deinem `andre`-Account siehst du die **Dozenten**-Ansicht (du bist Kurs-Owner), nicht den Lerner-Flow — drum `andre-learner`.

## DER RELEASE (morgen, nach Andres Go) — Schritte 1–5
1. `info.xml` **4.4.9 → 5.0.0**; CHANGELOG.md `app/CHANGELOG.md` v5.0.0-Eintrag (Cert-as-a-Service: Pass-Def, Cert-Artifact/Issuer, Compliance-Report, Public-Verify).
2. `scripts/verify-release.sh` (Version-Sync + Signatur-Gate) — muss grün sein.
3. `occ upgrade` auf devcloud (4.4.9→5.0.0; Maintenance kurz) ODER info.xml-Bump mitdeployen.
4. git tag **v5.0.0** + push (Forgejo/GitHub-Mirror); signiertes Paket → **Codeberg/Nextcloud App Store** (Token `~/.config/codeberg/token`). Release-Details: Memory `release-history.md`.
5. `/gsd:complete-milestone` → REQUIREMENTS/STATE final flippen, Milestone archivieren.

## OFFENE PUNKTE (nicht-blockierend, in INBOX)
- **Avatar/Figuren überarbeiten** (Andre: „sehen kacke aus, ALLE überarbeiten") — die Onboarding-Chibi-Figur + ganze Avatar-Familie. Eigener Design-Durchgang, NICHT v5.0.0. `feedback_avatar_design.md`, geparktes v4.5.0 Avatar-Skins.
- **Cert-Karten-Store-Screenshot**: Automatik scheiterte (Onboarding-Tour + SPA-Selektoren, 2× Timeout). Morgen am besten manuell aufnehmen wenn Andre im Cert-Flow ist (oder als demo-idiottest). Aktuell nur die verify-Seite als Store-Shot.
- **Store-Screenshot-Reihenfolge**: 07 hinten angehängt; ggf. Cert prominenter platzieren (Andres Marketing-Call).
- **python3-cryptography im Container** non-persistent (läuft aktuell 43.0.0) — (c) ins Image backen für Reproduzierbarkeit (INBOX).
- **Cleanup nach Release**: Demo-Throwaway-Accounts `demo-idiottest` + `andre-learner` + ihre Certs ggf. abräumen (oder demo-idiottest als persistenter Demo behalten). Scratch auf relais: `~/learning-nc/{provision-idiottest,retitle-remint,content-sync,create-learner}.sh`, `idiot-pools-wrapper.json`, `verify-credential.py`, `/tmp/idiottest-stu-pw.txt`.

## Referenzen
- Provisioning-Record: `.planning/phases/157-public-verify/157-PROVISIONING.md`
- Review-Log: `docs/superpowers/plans/2026-06-28-demo-course-REVIEW.md`
- REQUIREMENTS: alle VERIFY-01..06 live-proven Complete.
- gsd-tools-Bug: STATE/ROADMAP/REQUIREMENTS IMMER hand-editieren (korrumpiert v5.0.0-Frontmatter).
