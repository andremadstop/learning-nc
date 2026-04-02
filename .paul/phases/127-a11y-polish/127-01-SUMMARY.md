# Phase 127 - A11Y and UX Polish

## Ergebnis

Die neue FSRS-UI und die bestehenden Dialoge haben jetzt die geplanten Accessibility-Nachbesserungen: klare Timer-Phasenlabels, Focus-Trapping fuer `NcDialog`-Modale und zugaenglichere FSRS-Rating-Buttons mit Radiogroup-/Aria-Hinweisen.

## Aenderungen

- `app/src/components/GameTimer.vue`
  - sichtbare Timer-Phasen auf `Safe`, `Warning`, `Critical`, `Expired` vereinheitlicht
  - `aria-label` enthaelt jetzt Zeit plus Phase in einem konsistenten Satz

- `app/src/utils/dialogFocus.js`
  - neuer, kleiner Focus-Helper fuer sichtbare Focusables, Initialfokus und Tab-Cycle

- `app/src/components/AccessibleDialog.vue`
  - neuer Wrapper um `NcDialog`
  - setzt Initialfokus beim Oeffnen in den Dialoginhalt
  - kapselt Tab-/Shift-Tab-Zyklus innerhalb des Dialogroots

- auf `AccessibleDialog` umgestellt:
  - `app/src/components/ImportDialog.vue`
  - `app/src/components/PoolList.vue`
  - `app/src/components/QuestionForm.vue`
  - `app/src/components/QuestionList.vue`
  - `app/src/components/ShareDialog.vue`
  - `app/src/components/TranslationDialog.vue`

- `app/src/components/LeitnerMode.vue`
  - FSRS-Button-Gruppen jetzt mit `role="radiogroup"`
  - Buttons mit `aria-label` und `aria-keyshortcuts`

- `app/l10n/de.json`
- `app/l10n/de.js`
  - DE-Texte fuer neue Timer-/A11Y-Strings

- `app/tests/unit/dialogFocus.test.js`
- `app/tests/unit/GameTimer.test.js`
  - neue Unit-Tests fuer Focus-Trap-Utility und Timer-Phasenlabels

## Verifikation

- lokal:
  - `node --check` fuer neue/geaenderte JS-Test-/Utility-Dateien
  - JSON-Parse fuer `app/l10n/de.json`
  - `git diff --check`
  - `cd app && npx eslint --ext .js,.vue src/`
    - 0 Errors, 13 bestehende Warnings
  - `cd app && npm run test -- --run`
    - `759 passed`

- `learning-dev`:
  - `./scripts/deploy-dev.sh --js-only`
    - Remote-Build und Bundle-Deploy gruen

## Randbefunde

- Die Timer-Phase war bereits teilweise verbalisiert, aber nicht in der gewuenschten klaren Form (`Safe/Warning/Critical`). Phase 127 reduziert diese Inkonsistenz auf eine einzige, vorhersagbare Darstellung.
- Fuer die Dialog-A11Y wurde bewusst kein grosser Modal-Refactor gemacht; der neue Wrapper haelt den Blast Radius klein und laesst die bestehenden `NcDialog`-Aufrufe weitgehend unveraendert.
