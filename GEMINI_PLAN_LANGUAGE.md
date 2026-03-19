# Implementierungsplan: Mehrsprachigkeit & UI-Konsistenz

Dieses Dokument beschreibt den Plan zur Einführung von Content-Übersetzungen und zur Bereinigung der UI-Strings in der Learning-NC App.

## 1. IST-Zustand Analyse

### Content-Sprache (Fragen & Antworten)
*   **Infrastruktur**: `TranslationService`, `TranslationController`, `QuestionTranslationMapper` und entsprechende DB-Tabellen (`learning_qst_translations`, `learning_ans_translations`) sind vorhanden und funktionsfähig.
*   **Lücke 1 (Präferenz)**: Es gibt keine Einstellung für die bevorzugte Inhalts-Sprache (`content_language`) in den persönlichen Einstellungen. Aktuell existiert nur `ui_language`.
*   **Lücke 2 (Auslieferung)**: Die Lernmodi (`TrainingService`, `LeitnerService`, `DuelService`) laden Fragen und Antworten direkt aus den Haupttabellen ohne Berücksichtigung von Übersetzungen.
*   **Lücke 3 (Authoring)**: In der UI (`QuestionList`, `QuestionForm`) fehlt jegliche Möglichkeit, Übersetzungen anzulegen oder zu bearbeiten.
*   **Lücke 4 (Import)**: Der CSV/JSON Import unterstützt keine Übersetzungen.

### UI-Übersetzung (l10n)
*   Die Datei `app/l10n/de.json` ist die einzige Übersetzung.
*   **Gaps identifiziert**:
    *   `ImportDialog.vue`: Viele Strings wie "JSON Format: Array of question objects", "Drop CSV or JSON file here", "Clear", "{imported} of {total} questions imported" fehlen in `de.json`.
    *   `QuestionForm.vue`: "PBQ Type (optional)", "PBQ Config Builder", "Instructor Note" etc. fehlen.
    *   `DuelMode.vue`: "Duell", "Rematch", "Warte auf Gegner..." sind vorhanden, aber teilweise inkonsistent.
*   **Inkonsistenz**: `TranslationService` erlaubt `de`, `en`, `ru`, aber die Einstellungen bieten nur `de`, `en` an.

---

## 2. Implementierungsplan

### Phase 1: Benutzer-Einstellungen & Datenbank
1.  **SettingsController.php**: `getPersonal` und `savePersonal` um das Feld `content_language` erweitern. Erlaubte Werte: `['de', 'en', 'ru', '']`.
2.  **PersonalSettings.vue**: Neues Dropdown-Feld "Inhaltssprache" hinzufügen.
3.  **Migration**: (Optional, falls Default-Werte für bestehende User gesetzt werden sollen, ansonsten reicht `oc_preferences` via `IConfig`).

### Phase 2: Backend-Logik (Fragen-Auslieferung)
1.  **TranslationService.php**: Methode `getTranslatedQuestion(Question $q, string $lang)` hinzufügen:
    *   Prüft, ob `$q->getPbqSubtype() !== null` (PBQ Ausschluss). Wenn ja, Original zurückgeben.
    *   Sucht Übersetzung für `$lang`. Wenn gefunden, Text und Explanation im Objekt temporär ersetzen.
    *   Gleiches für zugehörige Antworten via `getTranslatedAnswer(Answer $a, string $lang)`.
2.  **TrainingService.php / LeitnerService.php / DuelService.php**:
    *   Die `lang` Präferenz des Users laden oder als Parameter in den API-Calls (`/start`, `/status`, `/due`) entgegennehmen.
    *   Vor der Rückgabe der Fragen/Antworten an den Controller die `TranslationService` nutzen, um die Texte zu ersetzen.

### Phase 3: Frontend-UI (Übersetzungs-Editor)
1.  **QuestionList.vue**: Neben dem "Edit" Button ein neues Icon/Button für "Übersetzen" hinzufügen (nur wenn `!readonly`).
2.  **TranslationDialog.vue (Neu)**:
    *   Ein Modal, das die Originalsprache anzeigt und Eingabefelder für die Ziel-Sprachen (`de`, `en`, `ru`) bietet.
    *   Speichert via `TranslationController` (setQuestionTranslation / setAnswerTranslation).
3.  **ImportDialog.vue**: Hilfe-Texte aktualisieren (Gaps schließen).

### Phase 4: UI-Konsistenz (l10n)
1.  **de.json**: Alle fehlenden Keys aus `grep`-Analyse nachtragen.
2.  **Inkonsistente Begriffe bereinigen**: "Prüfung" vs "Exam", "Pool" vs "Fragensammlung" vereinheitlichen.
3.  **Russian support**: `ru.json` und `ru.js` als Skelett anlegen, falls `ru` in `ALLOWED_LANGS` bleiben soll.

---

## 3. Präzise Anweisungen für den Codex (Ausführung)

### Backend
*   **Datei**: `app/lib/Controller/SettingsController.php`
    *   `savePersonal`: Parameter `string $content_language` hinzufügen und validieren.
*   **Datei**: `app/lib/Service/TranslationService.php`
    *   Methode `translateQuestion(array &$questionData, string $lang)` implementieren, die ein assoziatives Array (wie von `jsonSerialize` geliefert) mutiert.
*   **Datei**: `app/lib/Controller/TrainingController.php` (und andere)
    *   `$lang = $this->config->getUserValue($this->userId, 'learning', 'content_language', 'de')` abrufen und an Service übergeben.

### Frontend
*   **Datei**: `app/src/components/PersonalSettings.vue`
    *   `form.contentLanguage` hinzufügen.
    *   Template um `<select v-model="form.contentLanguage">` erweitern.
*   **Datei**: `app/l10n/de.json`
    *   Fehlende Keys (siehe Liste in Sektion 1) hinzufügen.

---

## 4. Korrekturen & Ergänzungen (von Claude Code Review)

### 4.1 Reaktives Umschalten — fehlt im Plan, muss ergänzt werden

Die Anforderung ist "zu jedem Zeitpunkt flüssig umschalten" — nicht nur beim nächsten Login.

**Was Codex implementieren muss:**
- `content_language` als reaktives Property in `App.vue` `data()` halten (wird beim Start aus `/api/settings/personal` geladen)
- Beim Speichern in PersonalSettings: `this.$root.contentLanguage = newLang` oder via `$emit` nach oben — App.vue aktualisiert sofort
- Alle Lernmodi bekommen `:contentLanguage="contentLanguage"` als Prop von App.vue
- Wenn ein Modus gerade läuft und Sprache wechselt: die **nächste** Frage kommt in der neuen Sprache (kein Reload, kein Abbruch der Session). Die aktuelle Frage bleibt in der alten Sprache — das ist akzeptables Verhalten.
- Technisch: `lang` wird bei jedem einzelnen API-Call mitgesendet (Query-Parameter `?lang=en`) — nicht als globaler Session-State im Backend

**API-Konvention**: `lang` als optionaler Query-Parameter auf allen fragen-liefernden Endpoints:
```
GET /api/training/session/{sessionId}?lang=en
GET /api/leitner/due?lang=en
GET /api/duels/{code}/state?lang=en
```
Wenn `lang` fehlt → Fallback auf User-Setting → Fallback auf Original.

### 4.2 `translateQuestion` — kein by-reference

**Korrektur zu Abschnitt 3 Backend:**

```php
// FALSCH (by-reference, fehleranfällig):
public function translateQuestion(array &$questionData, string $lang): void

// RICHTIG (gibt neues Array zurück):
public function translateQuestion(array $questionData, string $lang): array
```

Codex soll die Return-Variante implementieren. Aufrufer: `$question = $this->translationService->translateQuestion($question, $lang);`

### 4.3 Fehlende Modi — SwipeMode und SmartQueue

**Ergänzung zu Phase 2** — diese Modi fehlen im Plan, müssen ebenfalls berücksichtigt werden:

- `app/src/components/SwipeMode.vue` — lädt Fragen via Training-API, erbt `lang`-Prop von App.vue
- `app/src/components/SmartQueue.vue` — lädt Fragen via eigener Queue-Logik, Batch-Fetch nötig
- `app/lib/Service/SmartQueueService.php` (falls vorhanden) oder der entsprechende Controller — `lang`-Parameter ergänzen

Für SmartQueue gilt die **Batch-Strategie**: alle Question-IDs der Queue in einem JOIN mit der Translations-Tabelle laden, nicht N+1 Einzelabfragen.

---

## 5. Fallstricke & Validierung

*   **PBQ**: `pbq_subtype IS NOT NULL` prüfen — diese Fragen nie übersetzen, Original zurückgeben
*   **Fallback-Kette**: Gewählte Sprache → Original-Text der Frage → niemals Fehler werfen
*   **Kein Breaking Change**: `lang`-Parameter ist immer optional — bestehende Clients ohne `lang` funktionieren weiter
*   **"Exam" und "Prüfung"**: Das sind Markennamen/Modi-Namen — NICHT vereinheitlichen/übersetzen, so lassen
*   **ru.json**: Anlegen als leeres Skelett damit ALLOWED_LANGS=['de','en','ru'] konsistent ist — keine Inhalte erzwingen
