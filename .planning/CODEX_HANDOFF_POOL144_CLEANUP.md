# Codex Handoff — Pool 144 Content Cleanup

## Standard-Prompt
Siehe `~/.claude/projects/-home-andre-Workspace-Code-learning-nc/memory/feedback_codex_collaboration.md` — autonom durcharbeiten, nur bei Risiko/fehlenden Rechten/Widersprüchen fragen.

---

## Ziel

Pool 144 (**Security+ SY0-701 Musterfragen (EN)**) hat 595 Fragen aus einem ODT-Import. Beim Import sind in mindestens ~30 Fällen die **Dozenten-Erklärungen und Kommentare in den Answer-Text (`oc_learning_answers.text`) gerutscht**, statt ins `oc_learning_questions.explanation`-Feld zu gehen.

**Symptom beim User:** Wenn die richtige Antwort an Position 3 oder 4 liegt, sieht der User die komplette Erklärung schon **bevor** er klickt, weil sie Teil des Antwort-Buttons ist.

**Beispiel (Answer ID 60165, Position 4, is_correct=TRUE):**
```
BIA (Business Impact Analysis)  Business Impact Analysis (BIA) is the process of
identifying and evaluating the potential effects of disruptions to critical business
operations. In this scenario, the …
```
→ Richtige Antwort: `"BIA (Business Impact Analysis)"`, der Rest ist Erklärung.

**Erwartetes Ergebnis:**
- `oc_learning_answers.text` enthält nur die **reine Antwortoption** (üblicherweise 5–80 Zeichen)
- `oc_learning_questions.explanation` enthält die Erklärung (der abgeschnittene Teil)
- Wenn `questions.explanation` bereits Inhalt hat und Konflikt entsteht: append oder **manual review** flaggen

---

## Scope

### Schreibbereich
- **Neues Script:** `scripts/cleanup_pool144_answers.php` (PHP, läuft im Container wie `fill_pool_explanations.php`)
- **Report-Datei:** `scripts/cleanup_pool144_report.json` (Dry-Run-Output + Ergebnis)
- **Manual-Review-Liste:** `scripts/cleanup_pool144_manual_review.md`
- **Dieser Handoff:** Fortschrittsblock am Ende ergänzen
- **Commit-Erlaubnis:** Ja, separate Commits auf Branch `fix/learning-engine-answer-bugs` (aktueller Branch) oder neuen Branch `fix/pool144-content-cleanup`

### Read-Only
- `app/lib/` — nicht ändern, nur lesen für Context
- Alle anderen Pools in DB — **nicht anfassen**
- `.planning/` außer diesem File — nicht ändern

### DB-Scope
- **Nur** `oc_learning_questions.explanation` und `oc_learning_answers.text` **im Pool 144** (`pool_id = 144`)
- Andere Pools (138, 139, 135, 124, …) haben auch lange Antwort-Texte — möglicherweise selbes Problem, aber **erst in separater Session**

---

## Tool-Freigabe

- `ssh relais 'docker exec devcloud-db psql -U oc_admin -d nextcloud -c "..."'` für DB-Zugriff
- `ssh relais 'docker exec devcloud-app php /var/www/html/custom_apps/learning/scripts/cleanup_pool144_answers.php --dry-run 144'`
- Gemini CLI: `scripts/test-gemini.sh` zeigt das Pattern (liest GEMINI_API_KEY aus `.env`) — für semantisches Splitting
- Direkt über `GeminiService` im App-Context (siehe `fill_pool_explanations.php` als Vorlage)
- Git commit + push auf feature branch erlaubt, **nicht auf main pushen**

---

## Freiheitsgrad

- Du darfst das Script iterativ entwickeln (dry-run → stichprobe → full run)
- Du darfst einen eigenen Gemini-Prompt formulieren (Vorschlag siehe unten)
- Du darfst zwischen Heuristik (Regex) und LLM wählen, wo sinnvoll
- Du darfst die ODT-Quelldatei suchen (vermutlich in `.planning/` oder NotebookLM) falls nötig
- PHPStan/ESLint sind freigegeben für selbst erstellten Code

---

## Abbruchregeln

1. **Mehrdeutig** — Wenn Gemini nicht klar zwischen Antwort und Erklärung trennt (z. B. Antwort IST ein Halbsatz wie "Because the user didn't authenticate"), → als `needs_manual_review` im Report markieren, **DB nicht schreiben**
2. **>50% Schnitt** — Wenn der vorgeschlagene neue Answer-Text weniger als 50% des Original-Texts ist, → manual review (Sanity-Check gegen Halluzinationen)
3. **Explanation-Konflikt** — Wenn `questions.explanation` schon Inhalt hat UND wir zusätzliche Erklärung haben, → append mit Separator `\n\n---\n\n` und flagge im Report
4. **DB-Error** — Stopp + User fragen
5. **Antwort-ID in anderen Pool** — Wenn aus Versehen Pool != 144, stopp
6. **User nicht erreichbar** — Wenn User nicht verfügbar, lieber konservativ (mehr manual_review) als aggressiv auto-fix

---

## Vorschlag Arbeitsweise

### Phase 1 — Diagnose (30 min)
1. **Backup-Dump:** `ssh relais 'docker exec devcloud-db pg_dump -U oc_admin -d nextcloud -t oc_learning_answers -t oc_learning_questions --data-only --column-inserts' > scripts/pool144_backup_20260424.sql` (nur zum Sichern)
2. **Kandidaten identifizieren:** Alle Answers in Pool 144 mit `LENGTH(text) > 80` (101 Kandidaten) — SQL-Export als JSON oder CSV
3. **Stichprobe:** 10 zufällige Kandidaten manuell inspizieren — erkennen, welche Patterns auftauchen (einzelner Term + Erklärung; einzelner Term + Dozenten-Kommentar; Mehrzeiler; …)

### Phase 2 — Trennlogik (1h)
Pro Kandidat:
- **Heuristik zuerst:** Wenn Text mit kurzer "Phrase" anfängt und dann in Fließtext übergeht (z. B. "BIA (Business Impact Analysis) Business Impact Analysis (BIA) is …"), → trennen beim ersten Punkt/Großschreibbeginn mit ≥4 Wörtern Erklärung
- **Gemini-Fallback:** Wenn Heuristik unsicher, Gemini-Prompt:
  ```
  You are cleaning up exam quiz answers. The following text was imported as a single
  answer option but likely contains the actual answer PLUS an instructor explanation.
  Extract just the clean answer (usually 1–10 words, often a term or short phrase).
  Return JSON: {"answer": "...", "explanation": "...", "confidence": "high|medium|low"}.
  If unsure or the text is purely a single answer, set answer to the original text
  and explanation to empty string with confidence="high".

  Question context: {question.text}
  Original text: {answer.text}
  ```
- `confidence == "low"` → manual review

### Phase 3 — Dry Run (15 min)
- Script mit `--dry-run`: schreibt `cleanup_pool144_report.json` mit:
  ```json
  {
    "pool_id": 144,
    "candidates": 101,
    "auto_fix": 60,
    "manual_review": 41,
    "changes": [
      {"answer_id": 60165, "question_id": 15858, "old_text": "...", "new_text": "...", "new_explanation_append": "...", "confidence": "high"}
    ]
  }
  ```
- Stichprobe 10 aus `auto_fix` und 10 aus `manual_review` in Markdown reviewen

### Phase 4 — Apply (30 min)
- Nach Sichtung: Script ohne `--dry-run` laufen lassen → schreibt DB
- Verify: `SELECT COUNT(*) FROM oc_learning_answers a JOIN oc_learning_questions q ON q.id=a.question_id WHERE q.pool_id=144 AND LENGTH(a.text) > 120;` — sollte deutlich sinken
- Manual Review in `scripts/cleanup_pool144_manual_review.md` für mich zur späteren Durchsicht

### Phase 5 — Commit + Handoff
- Commit 1: `scripts/cleanup_pool144_answers.php` + Report-JSON
- Commit 2: `scripts/cleanup_pool144_manual_review.md`
- Summary hier im Handoff-File unter "## Ergebnis" ergänzen
- `.planning/INBOX.md` Eintrag "Bug 2 Content-Cleanup" auf erledigt setzen

---

## Kontext-Dateien

- `scripts/fill_pool_explanations.php` — **Vorlage** für Gemini-basiertes Batch-Update (Bootstrapping Container-Context, GeminiService-Init)
- `app/lib/Service/GeminiService.php` — API-Client
- `app/lib/Db/AnswerMapper.php` / `QuestionMapper.php` — Mapper-Interfaces
- `.env` auf Relay: `ssh relais 'cat /opt/devcloud/.env | grep GEMINI'` — API-Key-Quelle
- Frühere ähnliche Arbeit: HANDOFF.md erwähnt "125 Antworten korrigiert in Pools 138+139 (Gemini-Batch)" vor v4.2.0 — Pattern hat funktioniert

---

## Priorität

1. **Korrekt funktionierend** — keine halluzinierten Antwort-Texte
2. **Konservativ** — lieber mehr manual_review als falsche Auto-Fixes
3. **Idempotent** — Script darf mehrfach laufen ohne Schaden
4. **Nachvollziehbar** — Every change in Report mit before/after

---

## Output am Ende

Am Ende des Handoffs unter "## Ergebnis" ergänzen:
- **Anzahl geändert / manual_review / unchanged**
- **Commits** (Hashes)
- **Was verifiziert wurde** (welche Queries, welche Stichproben)
- **Offene Risiken** (z. B. Pool 138/139 hätten das gleiche Problem, 101 Kandidaten in anderen Pools)

---

## Ergebnis
<!-- von Codex ausfüllen -->

- Status: abgeschlossen als konservativer Safe-Apply
- Geändert: 2 Answers automatisch bereinigt (`58657`, `60547`)
- Manual review: 41
- Unchanged: 58
- Backup: `scripts/pool144_backup_20260424.sql`
- Artefakte:
  - `scripts/cleanup_pool144_answers.php`
  - `scripts/cleanup_pool144_report.json`
  - `scripts/cleanup_pool144_manual_review.md`

### Verifiziert
- Dry-run vor Apply: `101` Kandidaten, davon `2 auto_fix`, `41 manual_review`, `58 unchanged`
- Apply auf Devcloud-DB ausgefuehrt mit identischer Logik
- Query nach Apply: `SELECT COUNT(*) ... LENGTH(a.text) > 80` fuer Pool `144` ergibt `99` (vorher `101`)
- Stichprobe der Writes per SQL:
  - `58657` -> `Using a cloud provider to create additional VPN concentrators`
  - `60547` -> `Retraining requirements for individuals who fail phishing simulations`
- Beide betroffenen `questions.explanation`-Felder enthalten den Append-Separator `---` plus den ausgelagerten Kommentar

### Commits
- Noch offen: Branch/Commit lokal nicht ausgefuehrt, da `.git` in dieser Session schreibgeschuetzt war

### Offene Risiken
- Der konservative 50%-Guard laesst viele fachlich plausible Splits bewusst in `manual_review`; das ist Absicht
- Mehrere Kandidaten sind semantisch klar, aber fuer Auto-Fix zu stark verkuerzt oder zusammengezogen (`SOWSOW`, `Physicaldefensive`, `Approval procedure...`)
- Pools ausserhalb `144` wurden nicht angetastet
- `.planning/INBOX.md` wurde in dieser Session nicht angepasst
