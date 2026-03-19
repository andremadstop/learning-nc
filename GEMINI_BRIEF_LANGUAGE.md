# Gemini Analyse-Auftrag: Mehrsprachigkeit & UI-Konsistenz

> **Deine Aufgabe**: Analysiere den Ist-Zustand, identifiziere Lücken, erstelle einen präzisen Implementierungsplan.
> **Kein Code schreiben. Keine Dateien ändern. Keine Daten anfassen.**
> **Output**: Lege `GEMINI_PLAN_LANGUAGE.md` im Repo-Root ab.

---

## Hintergrund

Learning-NC hat bereits eine teilweise Translation-Infrastruktur:
- DB-Tabellen `oc_learning_question_translations` und `oc_learning_answer_translations` existieren
- `TranslationService.php` und `TranslationController.php` können Übersetzungen speichern/lesen
- `ALLOWED_LANGS = ['de', 'en', 'ru']` in TranslationService
- User-Setting `ui_language` (de/en) existiert in PersonalSettings
- **Problem**: Kein einziger Lernmodus (Training, Leitner, Swipe, Exam, Duel) wendet Übersetzungen an — Fragen werden immer im Original-Sprachtext geliefert
- **Problem**: UI-Übersetzung (`app/l10n/de.json`) hat 454 Keys — EN-Pendant fehlt oder ist lückenhaft

---

## Analyse-Aufgabe 1: Content-Sprache (Fragen & Antworten)

### Was du analysieren sollst

**1a. Bestehende Translation-Infrastruktur**
Lies diese Dateien vollständig:
- `app/lib/Service/TranslationService.php`
- `app/lib/Controller/TranslationController.php`
- `app/lib/Db/QuestionTranslation.php` + `QuestionTranslationMapper.php`
- `app/lib/Db/AnswerTranslation.php` + `AnswerTranslationMapper.php`
- `app/appinfo/routes.php` (Translation-Routen)

Dokumentiere: Welche Methoden existieren? Was gibt die API zurück? Wie sind Translations gespeichert (Felder, Schema)?

**1b. Fragen-Serving in allen Lernmodi**
Lies diese Dateien:
- `app/lib/Service/TrainingService.php`
- `app/lib/Service/LeitnerService.php` (oder LeitnerController)
- `app/lib/Controller/TrainingController.php`
- `app/src/components/TrainingMode.vue`
- `app/src/components/LeitnerMode.vue`
- `app/src/components/SwipeMode.vue`
- `app/src/components/ExamMode.vue`
- `app/src/components/DuelMode.vue` + `app/lib/Service/DuelService.php`

Dokumentiere pro Modus: Wo wird Fragetext/Antworttext geladen? Welche API-Endpunkte? Wo im Code müsste die Sprach-Logik rein?

**1c. User-Sprach-Präferenz**
Lies:
- `app/lib/Controller/SettingsController.php`
- `app/src/components/PersonalSettings.vue`
- `app/lib/Settings/PersonalSettings.php`

Dokumentiere: Wie wird `ui_language` gespeichert und gelesen? Gibt es bereits eine `content_language`-Einstellung?

### Was du planen sollst

Erstelle einen **stufenweisen Implementierungsplan** für:

1. **Content-Language-Präferenz pro User** (getrennt von UI-Sprache):
   - Wo speichern (NC `IConfig` User-Value, neue DB-Spalte, oder in bestehendem Settings-System)?
   - Welche API-Änderung nötig (`GET /api/v1/user/state` bereits vorhanden)?
   - Wie im Frontend global verfügbar machen (App.vue data, provide/inject, oder LocalStorage)?

2. **Sprach-Switcher UI**:
   - Wo platzieren (PersonalSettings, globale Toolbar, oder pro-Pool)?
   - Welche Komponente, welche Props?
   - Wie reagiert die App sofort ohne Reload (reaktiv)?

3. **Backend: Translation-Lookup in allen Modi**:
   - Für jeden Modus: Welche Methode/Funktion muss `$lang`-Parameter bekommen?
   - Strategie für Translation-Fallback: wenn keine Übersetzung → Original zeigen
   - Wie `$lang` vom Controller in den Service transportieren (HTTP-Header? Query-Parameter? User-Config-Lookup im Service?)
   - Performance: JOIN-Abfrage oder separater Lookup? Bei Batch-Fragen (Training lädt mehrere)?

4. **Erweiterbarkeit auf beliebige Sprachen**:
   - `ALLOWED_LANGS` aktuell hardcoded — Plan für dynamische Sprach-Liste (Admin-konfigurierbar?)
   - Wie neue Sprache hinzufügen ohne Code-Änderung?

**Format des Plans**: Für jede Änderung: Dateiname, Methodenname, was genau geändert wird, in welcher Reihenfolge. Kein Pseudo-Code — echte Methoden- und Variablennamen aus dem bestehenden Code.

---

## Analyse-Aufgabe 2: UI-Übersetzungs-Konsistenz

### Was du analysieren sollst

**2a. Bestandsaufnahme**
Lies:
- `app/l10n/de.json` — alle 454 Keys
- Durchsuche alle Vue-Komponenten in `app/src/components/` nach `t('learning', '...')` Aufrufen

Erstelle eine vollständige Liste aller verwendeten `t('learning', '...')`-Strings im Frontend.

**2b. Lücken identifizieren**
- Welche Strings im Code haben KEINE Entsprechung in `de.json`? (fehlende Übersetzung → fällt auf EN-Fallback zurück)
- Welche Keys in `de.json` existieren aber werden nirgendwo im Code verwendet? (tote Keys)
- Gibt es inkonsistente Schreibweisen (z.B. "Zurück" vs "← Zurück" vs "Back")?
- Gibt es englische Strings die eigentlich übersetzt sein sollten (z.B. Tab-Labels, Button-Texte)?

**2c. Prioritäten**
Klassifiziere die Lücken:
- **Kritisch**: Sichtbare englische Strings in der deutschen UI (Buttons, Tabs, Fehlermeldungen)
- **Mittel**: Inkonsistente Formulierungen (Gleiche Aktion, verschiedene Texte)
- **Niedrig**: Tote Keys, marginale Texte

### Was du planen sollst

Erstelle eine **Korrekturliste** (kein Code, nur präzise Anweisungen für Codex):

Format pro Eintrag:
```
Datei: app/l10n/de.json
Aktion: ADD key "Schlüssel" → Wert "Deutsche Übersetzung"
Grund: Wird in ComponentX.vue Zeile N verwendet, fehlender DE-Text

Datei: app/src/components/ComponentX.vue
Aktion: REPLACE t('learning', 'English Text') → t('learning', 'Neuer Key')
Zeile: N
```

**Wichtig**: Keine bestehenden Übersetzungen löschen oder inhaltlich ändern — nur ergänzen und vereinheitlichen. Keine Datenbankdaten anfassen.

---

## Output-Format

Lege `GEMINI_PLAN_LANGUAGE.md` im Repo-Root ab mit folgenden Abschnitten:

```markdown
# Language & Translation Plan

## 1. Ist-Zustand
### 1.1 Translation-Infrastruktur (was existiert)
### 1.2 Lücken im Content-Serving (was fehlt pro Modus)
### 1.3 UI-Übersetzungs-Lücken (kritisch/mittel/niedrig)

## 2. Implementierungsplan: Content-Sprache
### Phase A: User-Präferenz speichern + API
### Phase B: Sprach-Switcher Frontend
### Phase C: Backend Translation-Lookup (pro Modus)
### Phase D: Erweiterbarkeit

## 3. Korrekturliste: UI-Konsistenz
(Pro Eintrag: Datei + Aktion + Zeile + Grund)

## 4. Reihenfolge für Codex
(Welche Phase zuerst, Abhängigkeiten)
```

---

## Rahmenbedingungen (nicht verhandelbar)

- Vue **2.7** — kein Vue 3, kein Composition API
- Sprach-Präferenz muss reaktiv sein (Switcher → sofortige Wirkung ohne Reload)
- Fallback-Kette: Gewählte Sprache → Original-Sprache des Pools → kein Fehler
- PBQ-Fragen (`pbq_subtype IS NOT NULL`) aus Translation-Logik ausschließen
- Kein Breaking Change an bestehenden API-Endpunkten — Sprache als optionaler Parameter
- `ALLOWED_LANGS` muss erweiterbar bleiben ohne Code-Deploy
