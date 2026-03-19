# Gemini Übersetzungs-Auftrag: Network+ N10-009 (Pool 81)

> **Deine Aufgabe**: Übersetze 275 Fragen aus `translations/pool81_source_de.json` in drei Sprachen.
> **Keine bestehenden Dateien ändern. Keine Datenbankdaten anfassen.**
> **Output**: 3 Dateien in `translations/network-plus/`

---

## Kontext

Learning-NC ist eine Nextcloud-App für Lernkarteien (CompTIA Network+ Prüfungsvorbereitung).
Pool 81 enthält 275 Multiple-Choice-Fragen auf Deutsch (Quelle: CompTIA N10-009 Prüfungsstoff).
Die Quelldatei ist `translations/pool81_source_de.json` — diese ist die **einzige Source of Truth**.

---

## Aufgabe

Lies `translations/pool81_source_de.json` vollständig.

Erstelle drei Übersetzungsdateien:

| Datei | Sprache | Hinweise |
|-------|---------|----------|
| `translations/network-plus/pool81_en.json` | Englisch | Fachbegriffe bleiben auf Englisch (sie sind schon EN), Fragetext flüssig übersetzen |
| `translations/network-plus/pool81_ru.json` | Russisch | Technische Abkürzungen (VLAN, TCP, OSI...) NICHT übersetzen, nur transkribieren wenn nötig |
| `translations/network-plus/pool81_ar.json` | Arabisch | RTL-Text, technische Abkürzungen auf Englisch behalten |

---

## Output-Format

Jede Datei hat **exakt** diese Struktur (keine Abweichungen):

```json
{
  "meta": {
    "pool_id": 81,
    "lang": "en",
    "source_lang": "de",
    "question_count": 275,
    "generated_at": "2026-03-18",
    "generator": "Gemini"
  },
  "questions": [
    {
      "question_id": 12184,
      "translated_text": "Which of the following troubleshooting methodology steps would most likely involve checking each layer of the OSI model after identifying the problem?",
      "translated_explanation": "Correct answer: Establish a theory. After problem identification, each OSI layer is systematically analyzed to narrow down possible causes — this is the 'establish a theory' step. Other options like implementing a solution or creating an action plan come after theory confirmation.",
      "answers": [
        {
          "answer_id": 45360,
          "translated_text": "Establish a theory."
        },
        {
          "answer_id": 45361,
          "translated_text": "Implement the solution."
        },
        {
          "answer_id": 45362,
          "translated_text": "Create an action plan."
        },
        {
          "answer_id": 45363,
          "translated_text": "Verify functionality."
        }
      ]
    }
  ]
}
```

**Wichtige Regeln:**
- `question_id` und `answer_id` exakt aus der Quelldatei übernehmen — niemals ändern
- Reihenfolge der `answers` exakt beibehalten (gleiche Reihenfolge wie in der Quelldatei)
- `is_correct` und `position` werden **nicht** in die Übersetzungsdatei übernommen (nicht nötig)
- `translated_explanation` darf leer sein (`""`), wenn keine Erklärung in der Quelle vorhanden
- Technische Abkürzungen: VLAN, TCP/IP, UDP, OSI, MAC, DNS, DHCP, NAT, VPN, SSH, TLS, IPv4, IPv6, etc. **niemals übersetzen** — im Original belassen
- Fachterminologie präzise und konsistent verwenden (CompTIA-Standard-Vokabular)

---

## Qualitätsanforderungen

1. **Vollständigkeit**: Alle 275 Fragen müssen übersetzt sein. Keine Frage auslassen.
2. **Konsistenz**: Gleiche Begriffe immer gleich übersetzen (z.B. "Subnetzmaske" immer "subnet mask" auf EN, nicht mal "subnet mask" mal "network mask")
3. **Fachlichkeit**: CompTIA-Prüfungsvokabular korrekt verwenden
4. **Natürliche Sprache**: Kein maschinenhafter Stil — Fragen sollen sich natürlich lesen
5. **Arabisch RTL**: Satzstruktur korrekt für RTL, keine LTR-Zeichen mischen außer bei Abkürzungen

---

## Nicht tun

- Keine `pool81_source_de.json` verändern
- Keine anderen Dateien im Repo anfassen
- `question_id` / `answer_id` niemals erfinden oder ändern
- Kein `is_correct` oder `position` in den Output aufnehmen
- Keine Dateien außerhalb von `translations/network-plus/` erstellen

---

## Verzeichnis anlegen

Lege `translations/network-plus/` an (falls nicht vorhanden) und schreibe direkt:
- `translations/network-plus/pool81_en.json`
- `translations/network-plus/pool81_ru.json`
- `translations/network-plus/pool81_ar.json`

Alle drei Dateien in einem Durchgang erstellen. Wenn du wegen Länge pausieren musst, schreibe einen validen JSON-Checkpoint (z.B. die ersten 100 Fragen) und setze in der nächsten Runde fort — wichtig: `question_count` am Ende auf die tatsächliche Anzahl korrigieren.
