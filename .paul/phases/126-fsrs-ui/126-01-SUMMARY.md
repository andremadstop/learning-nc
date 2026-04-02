# Phase 126 - FSRS UI

## Ergebnis

Der Leitner-Reviewflow nutzt jetzt einen zweistufigen FSRS-Ablauf: erst Antwort-Preview mit Feedback, danach Confidence-Rating. Standardmaessig sehen Lernende 3 Buttons (`Again`, `That was hard`, `That was easy`); ueber die persoenliche Option `fsrs_detailed_stats` gibt es eine detaillierte 4-Button-Ansicht mit Intervallvorschau und Stabilitaetsanzeige.

## Aenderungen

- `app/lib/Controller/SettingsController.php`
  - neues Personal-Setting `fsrs_detailed_stats` in `getPersonal()` und `savePersonal()`

- `app/lib/Controller/LeitnerController.php`
  - `POST /api/leitner/answer` akzeptiert jetzt zusaetzlich `preview`

- `app/lib/Service/LeitnerService.php`
  - Preview-Pfad in `answerQuestion()`:
    - bewertet Antworten und liefert Feedback ohne Persistenz, XP oder Badge-Side-Effects
    - gibt `awaiting_rating`, `preview` und `first_fsrs_review` zurueck
  - finaler Commit-Pfad nutzt das gewaehlte Rating fuer FSRS und XP:
    - `Again=0`
    - `Hard=2`
    - `Good=5`
    - `Easy=7`

- `app/src/utils/fsrsScheduler.js`
  - neuer Frontend-Helper fuer FSRS-Preview und Rating-Optionen
  - Default-Ansicht:
    - `Again`
    - `Hard`
    - `Easy`
  - Detailansicht:
    - `Again`
    - `Hard`
    - `Good`
    - `Easy`

- `app/src/components/LeitnerMode.vue`
  - Antworten laufen jetzt ueber Preview und anschliessendes Rating
  - neue Intervall-Notice nach dem Rating
  - opt-in Detailstatistiken mit Stabilitaetsbar
  - KI-Erklaerung und `Next Question` erst nach abgeschlossenem Rating
  - Dashboard/Init-Texte auf FSRS-Logik angepasst

- `app/src/components/PersonalSettings.vue`
  - neuer Toggle `Extended learning statistics`

- `app/src/App.vue`
- `app/src/components/CourseDetail.vue`
- `app/src/components/CourseTabLernraum.vue`
  - neues Setting wird vom App-Shell-Level bis in `LeitnerMode` durchgereicht

- `app/src/utils/onboarding-slides.js`
  - neue Student-Slide `fsrs` direkt nach `leitner`

- `app/src/utils/virtuprof-scripts.js`
  - `leitner-first-start` auf FSRS/Confidence umgestellt
  - neuer One-time-Trigger `fsrs-first-use`

- `app/l10n/de.json`
- `app/l10n/de.js`
- `app/l10n/en.json`
- `app/src/l10n/virtuprof-strings.js`
  - neue FSRS-UI-/Slide-/VirtuProf-Texte

- `app/tests/unit/fsrsScheduler.test.js`
- `app/tests/unit/onboardingSlides.test.js`
  - neue Unit-Tests fuer Rating-Optionen und Student-Slide-Reihenfolge

## Verifikation

- lokal:
  - `node --check` fuer neue/geaenderte JS-Dateien
  - JSON-Parse fuer `app/l10n/de.json` und `app/l10n/en.json`
  - `git diff --check`
  - `cd app && npx eslint --ext .js,.vue src/`
    - 0 Errors, 13 bestehende Warnings
  - `cd app && npm run test -- --run`
    - `753 passed`

- `learning-dev`:
  - `./scripts/deploy-dev.sh --php-only`
  - volles PHPStan im Container gruen
  - gezielte PHP-Unit-Tests:
    - `FsrsServiceTest`
    - `LeitnerServiceTest`
  - `./scripts/deploy-dev.sh --js-only`
    - Remote-Build und Bundle-Deploy gruen

## Randbefunde

- Der bestehende Deploy-Script-Check `require AppInfo/Application.php` meldet weiterhin erwartbar einen Bootstrap-Warnpfad, ist aber kein Syntaxfehler.
- Beim ersten ad-hoc-PHPUnit-Nachweis war mein Remote-`docker cp`-Loop falsch gequotet und blockierte. Der Job wurde sauber entfernt und der gezielte Testlauf anschliessend robust neu gestartet.
