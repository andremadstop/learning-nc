# Pool-Import aus PDF via KI

Workflow um Fragepools aus beliebigen Dozenten-PDFs in die Learning-App zu importieren.
Nutzt die KI deiner Wahl (ChatGPT, Claude, Gemini, …) als Parser — keine extra Software.

## TL;DR

1. PDF + den Prompt unten in deine Lieblings-KI laden → JSON erhalten
2. JSON in den Container kopieren
3. `php occ learning:import-pool-json <datei> --user=<dein-uid>` ausführen
4. Pool ist im UI sichtbar

## JSON-Schema

Eine Datei kann beliebig viele Pools enthalten. Minimal-Beispiel:

```json
{
  "_meta": {
    "source": "Quelle in Klartext für später (PDF-Name, Dozent, Kursnummer)",
    "handbook_key": "ccna-200-301",
    "handbook_title": "Cisco CCNA 200-301",
    "default_difficulty": "medium",
    "default_question_type": "single"
  },
  "pools": [
    {
      "name": "CCNA 1 — Netzwerk-Grundlagen",
      "description": "Optionale Beschreibung des Pools.",
      "chapter_key": "1.1",
      "chapter_title": "Optional: feiner Kapitel-Titel",
      "chapter_order": 1,
      "exam_key_prefix": "ccna-1.1",
      "questions": [
        {
          "original_number": 1,
          "text": "Welcher Faktor bestimmt die TCP-Window-Size?",
          "explanation": "Die Window-Size wird vom Empfänger bestimmt — sie sagt dem Sender, wie viele Bytes er ungestört liefern darf, bevor er auf eine Bestätigung warten muss.",
          "question_type": "single",
          "difficulty": "medium",
          "answers": [
            {"text": "Die zu übertragende Datenmenge", "is_correct": false},
            {"text": "Die Anzahl der Dienste im TCP-Segment", "is_correct": false},
            {"text": "Wieviele Daten der Empfänger gleichzeitig verarbeiten kann", "is_correct": true},
            {"text": "Wieviele Daten die Quelle gleichzeitig senden kann", "is_correct": false}
          ]
        }
      ]
    }
  ]
}
```

### Feldreferenz

| Feld | Pflicht | Typ | Bemerkung |
|---|---|---|---|
| `pools[].name` | ✅ | string | Pool-Name wie er in der UI erscheint |
| `pools[].description` | nein | string | Mehrzeilig erlaubt |
| `pools[].chapter_key` | nein | string | z.B. `"1.1"` — gruppiert im UI |
| `pools[].chapter_title` | nein | string | Klartext-Titel des Kapitels |
| `pools[].chapter_order` | nein | int | Sortierung im UI |
| `pools[].exam_key_prefix` | nein | string | wird zu `<prefix>-q<original_number>` für jede Frage |
| `pools[].questions[].text` | ✅ | string | Markdown erlaubt — Code-Blocks `\`\`\``, Bilder via `![](...)` |
| `pools[].questions[].answers` | ✅ | array | Mindestens 2 Einträge |
| `pools[].questions[].answers[].is_correct` | ✅ | bool | Mehrere `true` ergeben Multi-Choice |
| `pools[].questions[].question_type` | nein | string | `single` (Default) oder `multi`. Fällt auf `multi` zurück, wenn mehrere `is_correct=true` |
| `pools[].questions[].explanation` | nein | string | Wird nach dem Beantworten gezeigt |
| `pools[].questions[].difficulty` | nein | string | `easy` \| `medium` \| `hard` |
| `pools[].questions[].original_number` | nein | int/string | Original-Nummer aus dem PDF — zur Nachverfolgung |

## Prompt-Vorlage (KI-agnostisch)

Diesen Prompt zusammen mit dem PDF in ChatGPT/Claude/Gemini laden. Funktioniert ab GPT-4o, Claude Sonnet 3.7+, Gemini 2.0 Flash+.

```
Du sollst eine PDF mit Übungsfragen in das JSON-Schema der Learning-App umwandeln.

REGELN:
1. Lies das gesamte PDF, fange keine Frage aus.
2. Wenn das PDF die Lösungen farblich (z.B. grün) markiert: die markierten Optionen sind is_correct=true.
3. Wenn die Lösungen in einem separaten PDF stehen (Format "1. B. Erklärung..."): den Buchstaben als korrekte Antwort übernehmen, die Erklärung wird das Feld "explanation".
4. Frage-Texte enthalten manchmal Code-Blocks oder Tabellen. Diese als Markdown-Code-Block (```) im "text"-Feld erhalten.
5. Wenn eine Frage ein Bild/Diagramm braucht das nicht reines Layout-Beiwerk ist: beschreibe das Bild kurz in eckigen Klammern, z.B. "[Diagramm zeigt: Client → Internet → Firewall → DMZ]".
6. Frage-Typen:
   - 1 korrekte Antwort → question_type: "single"
   - 2+ korrekte Antworten → question_type: "multi"
   - Drag&Drop/Reihenfolge/Zuordnung: ÜBERSPRINGEN und am Ende als Liste der ausgelassenen Fragen ausgeben.
7. Antworttexte sauber abschreiben (keine "A./B./C./D."-Prefixe — die Letter werden automatisch gerendert).
8. Wenn das PDF in mehrere Lektionen/Kapitel gegliedert ist: einen pool pro Kapitel mit passendem chapter_key.
9. Output: NUR das JSON, keine Erklärung außerhalb. Strict-valides JSON (UTF-8, keine trailing commas).

SCHEMA: siehe docs/import-pool-workflow.md im learning-nc Repo. Kurzfassung:
{
  "_meta": {"source": "...", "handbook_key": "...", "handbook_title": "..."},
  "pools": [
    {
      "name": "...",
      "chapter_key": "1.1",
      "chapter_title": "...",
      "exam_key_prefix": "kursname-1.1",
      "questions": [
        {
          "original_number": 1,
          "text": "...",
          "explanation": "...",
          "answers": [
            {"text": "...", "is_correct": false},
            {"text": "...", "is_correct": true}
          ]
        }
      ]
    }
  ]
}

Am Ende eine kurze Übersicht: Anzahl Pools, Anzahl Fragen pro Pool, übersprungene Fragen mit Begründung.
```

## Import-Schritt

Nach dem JSON-Output:

```bash
# 1. JSON in den Nextcloud-Container kopieren
scp pool.json relais:/tmp/
ssh relais 'docker cp /tmp/pool.json devcloud-app:/tmp/'

# 2. Dry-Run (zählt nur, schreibt nichts)
ssh relais 'docker exec -u www-data devcloud-app php occ \
  learning:import-pool-json /tmp/pool.json --user=<dein-uid> --dry-run'

# 3. Echter Import
ssh relais 'docker exec -u www-data devcloud-app php occ \
  learning:import-pool-json /tmp/pool.json --user=<dein-uid>'
```

`--user` ist der Nextcloud-UID des Pool-Owners. Pools können hinterher im Admin-UI mit Kursen verknüpft oder mit anderen Usern geteilt werden.

## Edge-Cases & Tipps

**KI vergisst Fragen.** Wenn das PDF >50 Fragen hat, bittet die KI in 2-3 Chunks zu liefern. Am Schluss alle Pools manuell in ein JSON zusammen-mergen.

**Bilder im PDF.** Reine Layout-Bilder (Logo, Header) ignorieren. Inhalts-relevante Bilder (Topologie-Diagramme, Wireshark-Captures, Code-Screenshots) sollten kurz textlich beschrieben werden — die App rendert Markdown, also `![](url)` funktioniert auch wenn du Bilder selbst hostest und Links einsetzt.

**Multi-Choice in PDFs.** Bei "(Choose two/three.)" → mehrere Antworten mit `is_correct: true`. Der Command setzt `question_type` automatisch auf `multi`, wenn er >1 korrekte Antwort sieht.

**Drag & Drop / Reihenfolge.** Aktuell nicht unterstützt — die KI soll diese in der Skip-Liste ausgeben. Du kannst sie manuell als Multi-Choice anlegen ("Welche Schritte gehören zur korrekten Reihenfolge?") oder im UI per Hand pflegen.

**Copyright.** Originale Dozenten-PDFs und das daraus erzeugte JSON sind in den meisten Fällen urheberrechtlich geschützt. **Nicht ins öffentliche Repo committen** — `scripts/*.json` und Pool-Exports gehören dort nicht rein. Tausch den Pool nicht öffentlich aus.

**Mehrere Versuche.** Wenn das erste Resultat unvollständig ist: dem Modell zeigen welche Frage fehlt, oder die Lieferung in Tranchen anfordern. Bei Claude den 200k-Kontext nutzen, GPT-4o hat 128k.

## Wiederverwendung

Das gleiche JSON-Format wird auch von `learning:export-pool --format=json` ausgegeben. Du kannst also:
- einen bestehenden Pool exportieren
- in JSON editieren (z.B. Erklärungen ergänzen)
- mit `learning:import-pool-json` wieder importieren (legt einen neuen Pool an)
- den alten manuell löschen

Round-Trip-fähig.
