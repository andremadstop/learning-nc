---
phase: 155-certificate-artifact-issuer
type: multi-ai-review
date: 2026-06-27
reviewers: [fabric/review_code, gemini-2.5-pro/review_design, codex/grumpy-security]
scope: git diff f8af12a..HEAD (waves 1-6 + 155-07 non-live gates)
status: triaged — pre-live gate
---

# Phase 155 — Multi-KI Review (Pre-Live Gate)

Drei Runden: fabric (Code) → Gemini (Design) → übel gelaunter Codex (Code & Security).
Jeder Befund gegen den echten Code verifiziert (kein blindes Annehmen/Ablehnen).

## Verdict-Tabelle

| # | Quelle | Verifizierte Severity | Befund | Fix-Scope |
|---|--------|----------------------|--------|-----------|
| R2-2 | Codex | **HIGH** | Idempotenz ohne DB-Unique → parallele Pass-Requests erzeugen mehrere gültige Certs → Revocation-Integrität kaputt (Geschwister-Cert bleibt gültig) | Migration Version009100 (unapplied!) + IssuanceService + Tests |
| R3-7 | Codex | **MED-HIGH (DSGVO)** | `resolveDisplayName()` fällt auf `userId` zurück; NC-User-IDs können Emails sein → Email im signierten, teilbaren JWT | IssuanceService: Email-Pattern blocken / Pseudonym-Fallback |
| R3-4 | Codex | **MEDIUM** | `verify()` prüft nicht `alg/typ/cty/kid` — Algorithm/Key-Confusion-Vorarbeit für Phase 157 (heute nicht exploitbar, da Caller Pubkey liefert) | SigningService.verify Header-Assertions + Tests |
| R6-6 | Codex | **MEDIUM** | `rotate()` retired alten Key VOR `init()` → stirbt init, kein aktiver Key | KeyService.rotate: erst neu erzeugen+persistieren, dann alten retiren |
| R3-3 | Codex | **MEDIUM** (low exploit, UUIDv4) | 403 (fremd) vs 404 (nicht-existent) = Existenz-Orakel; Tests zementieren es | Controller: einheitlich 404; Tests anpassen |
| R5-5 | Codex+Gemini | **MEDIUM** | `hostDid()` droppt Port/Webroot/Subpath; Frontend nutzt `window.location.origin` (Path bereits subpath-safe via generateUrl, commit 3ed5376) | KeyService.hostDid kanonisch aus Config; did:web Port-Encoding |
| R8-8 | Codex | **LOW** | Secret-Zeroing nicht exception-safe (`sign()` wirft → memzero übersprungen) | IssuanceService try/finally |
| R9-9 | Codex | **LOW** | `verificationId` roh in JS-Pfade interpoliert (heute UUIDv4) | encodeURIComponent + Backend-UUID-Regex |
| R1-1 | Codex | **LOW (mitigiert)** | Plaintext-Fallback read-side bei Key-Decrypt | write-seitig durch init() Z.64 abgefangen; optional Envelope-Prefix |
| R1-fab | fabric | **LOW** | `str_contains(..., 'already exists')` Fehlerbehandlung fragil | optional ActiveKeyExistsException |
| R1-fab | fabric | **REJECTED (FP)** | "i18n keys deutsch = kritisch" | DE-Source-Konvention, en.js mappt korrekt — kein Bug (Gemini bestätigt) |

## Gemini Design — operative/Doku-Items (kein Code-Blocker)

- **did:web Domain-Brittleness:** Domainwechsel/Instanz-Migration bricht Verifizierbarkeit aller alten Certs → für Admins dokumentieren. (überlappt R5-5)
- **Issuer-Status-Hinweis** in Admin-Settings (Admin muss `occ learning:cert:init-issuer` laufen) → Future-Enhancement.
- **Revocation-Prozess** definieren (Schema da, Logik fehlt) → Phase 156/157.
- **did.json-Caching** (IMemoryCache) für High-Traffic → Future-Optimierung.

## Sauber bestätigt (positiv)

- Kein `v-html` (XSS-Oberfläche clean), `CertKey::jsonSerialize()` omittet `secret_key_enc`,
  `did.json` publiziert nur public JWK `x` (kein `d`). Architektur/Separation/Leakage-Audit exzellent (alle 3 KI einig).

## Empfohlene Fix-Runde vor Live

**Pflicht vor Prod:** R2-2 (HIGH, Migration jetzt sauber änderbar), R3-7 (DSGVO).
**Stark empfohlen (billig, verhindert Future-Criticals):** R3-4, R6-6, R3-3, R5-5.
**Quick-Wins:** R8-8, R9-9.
**Optional/Defer:** R1-1 (mitigiert), fabric-Exception (Stil), Gemini-Doku-Items (Release/spätere Phasen).

---

## Ergebnis (3 Runden, 2026-06-27)

**Runde 1** (fabric/Gemini/Codex): 9 Codex-Befunde verifiziert + 1 fabric-FP (i18n, verworfen). Gemini-Design: nur Doku/Future.
**Fix-Runde** (2 Cluster): alle 8 verifizierten Befunde behoben.
**Runde 2** (Codex Re-Review): 4 sauber, 4 Restlücken (R2-2/R3-7/R6-6/R8-8).
**Politur** (4 Commits): Restlücken geschlossen (R3-7 unanchored, R8-8 KeyService try/finally, R6-6 rotate-Transaction, R5-5 Default-Ports).
**Runde 3** (SHIP-Gate, Codex): **SHIP** — kein offener CRITICAL/HIGH, Migration cross-DB sicher.

**13 Fix-Commits** (`bd2e03d`..`5484973`). Gates: PHPUnit 118/449, PHPStan L5 clean, ESLint 0, Vitest 1111, Cross-DB GREEN, kein kid-Drift (live via occ verifiziert).

### Offener Follow-up (kein Blocker, dokumentiert)
- **R2-2 Revoke-Nullen:** Wenn Phase 156/157 Revocation baut, MUSS der Revoke-Pfad `active_idem_key = NULL` setzen (sonst bricht Re-Issue-nach-Revoke am UNIQUE). Heute kein Bug (Revocation existiert nicht).
- **Single-Active-Key:** nur Transaction-geschützt, kein DB-Constraint (akzeptiert — rotate ist manuelles occ).
- **Gemini-Design-Doku:** did:web-Domain-Brittleness für Admins dokumentieren; Issuer-Status-Hinweis in Admin-Settings (Future).

### Restrisiko (Codex)
Post-Apply-Live-Gates (`db:show-table`, `kid == did.json`, unabhängiger Verifier) müssen direkt nach dem Live-Apply laufen — = der geplante 155-07-Live-Flow.
