# Pre-Publish Multi-AI Review — Demo-Kurs „I am not an idiot test"

**Datum:** 2026-06-28
**Gegenstand:** `app/examples/i-am-not-an-idiot-de.json` + `-en.json` (je 18 MCQ) + Cert-Config (Spec §3)
**Gate:** Pre-Live-Gate (`feedback_prelive_review_gate.md`), feste Reihenfolge fabric → Gemini → grumpy Codex.
**Ergebnis:** ✅ **SHIP** (grumpy Codex, Bestätigungs-Pass).

---

## Runde 1 — Findings

### Gemini (`fabric --model gemini-2.5-pro`) — Fakten & Übersetzung
Inhalte korrekt + aktuell für 2026, Übersetzungen präzise, Distraktoren plausibel. **1 Finding:**
- **Q4 [Mehrdeutig]** — die richtige Antwort beschrieb die Fake-Domain ungenau als „echtes PayPal + Zusatz", obwohl `paypa1-sicherheit` eine eigenständige Fremd-Domain ist.

### grumpy Codex (`codex exec --sandbox read-only`) — Security/Code/Config
- **Q1 [absolut]** „Echte Paketdienste verlangen NIE…" → als „behandle Fee-Links als Betrug, verifiziere via offizielle App" formulieren.
- **Q4 [DE/EN-Drift]** „foreign extra domain" liest sich wie Auslands-Domain → „unrelated lookalike domain". *(deckt sich mit Gemini)*
- **Q6 [Nuance]** „jede Stelle vervielfacht Aufwand" lässt vorhersehbar-langen Müll stark wirken → Unvorhersehbarkeit betonen.
- **Q8 [arguable + schwache Distraktoren]** „keine 2FA" + „Mädchenname" zu albern → TOTP-App + Push-Bestätigung; passkey-ist-nicht-immer-2FA (pedantisch).
- **Q11 [GEFÄHRLICH]** Erklärung „unangekündigter Videocall entlarvt Fakes" lehrt fälschlich „Video = Beweis" (Live-Deepfakes).
- **Q12 [GEFÄHRLICH/überbreit]** „kein seriöses Investment garantiert Gewinne" zu pauschal → „garantierte Verdopplung"; schwache Distraktoren (Likes/Website).
- **Q15 [DE/EN-Drift]** pyramid ≠ Ponzi → konsistent Ponzi/Schneeballsystem; „mathematisch unmöglich" lockern.
- **Q16 [überbreit + schwache Distraktoren]** „fast alle Maschen" falsch (Romance/Fake-Shops/Malware ohne Zeitdruck).
- **Q18 [GEFÄHRLICH]** „VPN senkt Sicherheit nie" zu absolut → unseriöser VPN verlagert Vertrauen, kann schaden.
- **Q1–Q18 [cert-config]** Pool-JSONs tragen keine Titel/Cert-Settings → 70%/365d/Titel müssen bei Kurs-Anlage gesetzt werden.

## Triage (receiving-code-review: prüfen, nicht blind übernehmen)

**Übernommen (valide, v.a. gefährlich):** Q2 (Werktag→https-Distraktor), Q4 (Domain-Präzisierung, beide Reviewer), Q6 (Unvorhersehbarkeit), Q8 (TOTP+Push-Distraktoren), Q11 (Video ≠ Beweis), Q12 (garantierte Verdopplung + stärkere Distraktoren), Q15 (Ponzi-Konsistenz), Q16 (→„häufiges Warnsignal in vielen Maschen"), Q18 (VPN-Vertrauen).

**Akzeptiert/zurückgewiesen:**
- **Q1** — Rat ist korrekt + sicher; minimal entschärft („behandle als Betrugsversuch"), keine inhaltliche Gefahr.
- **Q8 passkey-ist-nicht-immer-2FA** — Haarspalterei für einen Endnutzer-Kurs; Stem auf „phishing-resistenteste 2FA-Methode" geschärft, passkey bleibt korrekt.
- **Q1–Q18 cert-config** — KORREKT, aber **kein Content-Bug**: Titel/`cert_enabled`/`cert_pass_percent=70`/`cert_validity 365d` werden bei der Kurs-Anlage gesetzt (Plan Task 4 Step 5), nicht im Pool-JSON. Als Design bestätigt, keine Content-Änderung.

## Runde 2 — Bestätigungs-Pass (grumpy Codex)
Codex prüfte die revidierten Dateien + eigene jq-Checks (genau 1 korrekte Antwort/Frage, Antwort-Anzahl-Parität, kein DE/EN-Korrekt-Index-Drift) + Web-Recherche zu DHL-Zoll-SMS-Praxis (Q1):

```
RESOLVED Q2 Q4 Q6 Q8 Q11 Q12 Q15 Q16 Q18
VERDICT: SHIP
```

## Automatisierte Gates (nach Fixes)
- `validate-pool-json.mjs`: beide Pools 18/18 valide.
- DE/EN-Parität (Anzahl + Korrekt-Index): OK.
- Korrekt-Antwort-Positionen verteilt: {1:5, 2:5, 3:4, 4:4} — kein Antwort-Positions-Muster.

**Gate bestanden — Content ist publish-reif.** Live-Gang bleibt der user-gated Provisioning-Pass (Plan Task 4, Regel 15).

---

## Pre-Release Cross-Check (2026-06-28) — role-swap (Andre)

Final gate before public v5.0.0 store publish. Round 1 normal roles (Codex=code) found+fixed
a real BLOCKER (verify expiry trusted mutable DB expires_at, not signed validUntil — commit
103489c, RELEASE: GO). Then role-swap requested.

### Gemini → CODE: **RELEASE: GO**
All release-critical PHP (verify/sign/key/issuance/public-route/did/cert-controller) judged clean;
praised the EdDSA header-contract gate, sodium_memzero key hygiene, owner-scoped reads, no-oracle
public route. Independently confirms the code side.

### grumpy Codex → DESIGN/UX: **UX-RELEASE: NO-GO** — real findings (verified against templates)
- **BLOCKER** verify.php: green "✓ Digital verifiziert" trust badge renders for withdrawn/expired too (lines 133-135, hardcoded green) → make status-aware (only valid = green verified; withdrawn/expired = neutral "signature genuine but no longer valid").
- **BLOCKER** verify.php: banner white text fails WCAG contrast on withdrawn #e69900 (2.35:1) and borderline green #2f9a48 (3.6:1) → darken withdrawn; (colors are Playwright-asserted → update spec).
- **MAJOR** verify.php: valid state over-claims for employers (no "verifies the document ID, not the holder's identity") → add a "what this proves / does not prove" block.
- **MAJOR** verify.php: unknown/invalid gives no decision guidance → "do not accept as verified; request a fresh PDF/QR".
- **MAJOR** verify.php: DSGVO explainer says ID is "above" but renders below.
- **MAJOR** demo content + cert title: "I am not an idiot test" reads as victim-shaming on a public cert/LinkedIn → public title "Internet-Mündigkeit 2026" / "Internet Street-Smarts 2026", playful tone inside. (NB: live cert 2/3 currently carry the bare "(DE)/(EN)" title — a deviation from spec §3's intended professional title; would need re-mint to change the SIGNED achievement.name.)
- **MAJOR** EN content: "legal imprint" is German legal jargon → "seller identity, business address, contact details".
- **MAJOR** Certificate.vue: print layout not certificate-grade (no A4/Letter sizing/margins/QR mm); cert-ID affordance weak.
- MINORs: RTL bidi isolation for IDs/dates; "digitale Unterschrift"→"Signatur"; explanation density; romance "attractive profile" wording; LinkedIn CTA label; issuer-logo alt.

**Status:** code GO, UX NO-GO. Public store publish HELD pending UX fixes + the cert-title decision (user's call; also a spec-compliance fix).
