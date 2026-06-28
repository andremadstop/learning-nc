# Design Spec — Demo-Kurs „I am not an idiot test"

**Datum:** 2026-06-28
**Kontext:** v5.0.0 Certification-as-a-Service ist feature-complete (Phasen 154–157 alle COMPLETE). Dieser Demo-Kurs ist der letzte Baustein vor dem Release: das Vehikel, um den ersten echten Cert zu minten und die noch gated 157-Checks live zu beweisen — und gleichzeitig ein echter, teilbarer Kurs.
**Status:** Design genehmigt (Andre, 2026-06-28). Nächster Schritt nach User-Review: writing-plans.

---

## 1. Zweck & Doppelrolle

Ein echter, öffentlich teilbarer Mini-Kurs zur digitalen Selbstverteidigung 2026 — **und** das Vehikel für die v5.0.0-Live-Aktivierung:

- **Produkt:** spielerischer, nützlicher Kurs „I am not an idiot test" — das Wichtigste, was man 2026 übers Internet wissen muss, um nicht abgezockt zu werden.
- **Demo-Vehikel:** trägt den ersten echten v5.0.0-Cert. Auf ihm werden die in Phase 157 noch *gated* Checks live grün gefahren (VERIFY-03 DOM-No-Leak, VERIFY-05 Revoke-Tombstone, VERIFY-06 429-Rate-Limit) und die in 155 vertagten visuellen Checks (CERT-07/08/13: print / QR / LinkedIn-Share) nachgeholt.
- **Bleibt bestehen** nach dem Release als Marketing-/Demo-Kurs (kein Wegwerf wie der 155-Smoke).

## 2. Inhalt

- **18 MCQ-Fragen**, ausgewogen über 4 Themen (je ~4–5 Fragen):
  1. **Phishing & Fake-Shops** — gefälschte Mails/SMS/Links, betrügerische Shops, Fake-Paket-/Bank-Benachrichtigungen
  2. **Passwörter & 2FA** — sichere Passwörter, Passwort-Manager, Zwei-Faktor, warum SMS-2FA schwächer ist, Passkeys
  3. **KI-Scams & Deepfakes** — Stimm-Klon-Enkeltrick, KI-Fake-Profile/Videos, ChatGPT-Phishing, Fake-Promi-Investment-Werbung
  4. **Geld-Betrug & Datenschutz** — Krypto-/Investment-Scams, Romance-Scam, „sofort handeln"-Druck, App-Datensammlung, öffentliches WLAN
- **Format:** Single-/Multiple-Choice, 4 Optionen je Frage, jede Frage mit kurzer **faktischer Erklärung** (warum richtig/falsch — der eigentliche Lernwert).
- **Ton:** Rahmung (Titel, Intro, Ergebnis-Texte) augenzwinkernd; Fragen + Erklärungen sachlich-korrekt. Bsp.: *„Eine SMS sagt, dein Paket hängt im Zoll — klick hier zum Bezahlen. Was tust du?"*

### 2a. Zweisprachigkeit (DE + EN) — Andre-Anforderung 2026-06-28

Der Kurs wird **zweisprachig** erstellt: dieselben 18 Fragen in **Deutsch und Englisch**.

- **Struktur:** zwei sprach-getaggte Pools (`lang='de'` / `lang='en'`) — folgt der etablierten Projekt-Konvention (CySA+: getrennte Pools 157 MCQ-EN / 158 MCQ-DE). Inhalt 1:1 übersetzt, nicht neu erfunden.
- **Content-Artefakt:** zwei JSON-Dateien im Repo-Import-Format `[{text, answers:[{text,is_correct}], explanation}]` (Vorbild: `app/examples/gdpr-basics.json`):
  - `app/examples/i-am-not-an-idiot-de.json`
  - `app/examples/i-am-not-an-idiot-en.json`
- **Kurs-/Cert-Wiring (Empfehlung, final im Plan):** ein Kurs, der den sprach-passenden Pool nutzt — bevorzugt **zwei parallele Kurse** (DE-Kurs → DE-Pool, EN-Kurs → EN-Pool), je mit eigenem Cert. Sauber, spiegelt ein echtes bilinguales Angebot, und gibt uns zwei echte Demo-Certs (einen DE, einen EN). Genaue Verdrahtung (1 vs 2 Kurse) wird im Implementierungsplan festgezurrt.

## 3. Kurs- & Zertifikats-Konfiguration

| Feld | Wert |
|------|------|
| `cert_enabled` | `true` |
| `cert_pass_percent` | **70 %** (≈13/18 korrekt) |
| `certRequiredPoolIds` | `[<jeweiliger Sprach-Pool>]` |
| Gültigkeit | **365 Tage** (zeigt das Expiry-Feature im Cert ehrlich) |
| Cert-Titel / `achievement.name` | DE: „Internet-Mündigkeit 2026 — I am not an idiot test" · EN: „Internet Street-Smarts 2026 — I am not an idiot test" |
| Issuer | DevCloud-Instanz (bestehender did:web-Issuer-Key, `UI3V-D_j…`) — **keine neue Krypto** |

## 4. Bau-Weg (high-level — Details im Plan)

Echter persistenter Kurs über die regulären App-Pfade (`CourseController` / `PoolController` / `QuestionController`), **kein** Wegwerf-DB-Seed wie beim 155-Smoke.

1. 18 Fragen DE + EN als JSON-Artefakte ins Repo schreiben (faktisch fundiert, von mir verfasst).
2. Pools über den regulären Import-Pfad anlegen (Format wie `app/examples/*.json`).
3. Kurs(e) anlegen, Owner = realer Dozenten-Account auf der DevCloud, Cert-Config wie §3.
4. Gate-1-Hygiene wie immer (PHPStan/ESLint/Vitest unberührt — reines Content; falls Import-Code angefasst wird, Tests).

## 5. Pre-Publish-Gate — volles Multi-KI-Review (Andre-Anforderung 2026-06-28)

**Vor dem nächsten Publishen** (= bevor irgendetwas live/öffentlich geht: Provisioning-Pass-Mint, Release-Tag, Store-Release) läuft das etablierte **Pre-Live-Gate** (`feedback_prelive_review_gate.md`), fest geordnet:

1. **fabric** (Struktur/Erstpass)
2. **Gemini** (Design/Inhalt — auch faktische Korrektheit der 36 Fragen + Übersetzungs-Treue DE↔EN)
3. **sehr übel gelaunter Codex** (Code/Security — Import-Pfad, Cert-Config, kein PII-Leak, keine Regression an den 157-Surfaces)

Findings fixen bis SHIP (≈3 Runden üblich). **Faktische Korrektheit ist hier sicherheitskritisch** — ein Sicherheits-Kurs, der falschen Rat gibt, ist schlimmer als kein Kurs. Gemini bekommt explizit den Auftrag, jede Frage gegen den Stand 2026 zu prüfen.

## 6. Übergang zum Provisioning-Pass (separat, user-gated, Regel 15)

Nach Bau + bestandenem Pre-Publish-Gate, als **eigener autorisierter Schritt** (kein Auto-Run):

- `occ upgrade` (+ info.xml-Bump) → wendet die dormant Version009200 `revoked_at`-Migration auf Live-PG16 an (kurzes Maintenance-Fenster — traffic-arm timen).
- **Cert-Mint:** offene Entscheidung beim Provisioning-Schritt — **echte Person besteht den Test** (authentischster Weg, dogfoodet die UX) vs. Pass-Gates seeden wie in 155 (schneller). Empfehlung: echte Person, mindestens für einen der beiden Certs.
- Dann gated Checks grün: VERIFY-03 (LIVE_VID + Recipient-Konstanten im Playwright-Spec atomar setzen) · VERIFY-06 (429-Curl-Loop) · VERIFY-05 (credentialed Revoke-Smoke) · visuelle Banner valid/withdrawn/expired + RTL.
- Dann v5.0.0-Milestone-Close: CHANGELOG + git tag + Codeberg-Store-Release (`/gsd:complete-milestone`).

## 7. Offene Punkte (nicht-blockierend)

- **Kurs-Struktur 1 vs 2 Kurse** (bilingual) — final im Implementierungsplan.
- **Cert-Mint echte-Person vs seed** — beim Provisioning-Schritt.
- **python3-cryptography** im Live-Container (INBOX): aktuell präsent (43.0.0, läuft), nur Durabilität offen — (c) ins Image backen empfohlen, kein Blocker.

## 8. Nicht im Scope (YAGNI)

- Keine PBQ-Szenario-Fragen (reines MCQ reicht für Demo + Lernwert).
- Keine weiteren Sprachen außer DE/EN.
- Keine Kurs-Erweiterung auf 40–60 Fragen (Backlog-Idee Option B, geparkt).
- Keine neue Krypto/Issuer-Infrastruktur (bestehender Key).
