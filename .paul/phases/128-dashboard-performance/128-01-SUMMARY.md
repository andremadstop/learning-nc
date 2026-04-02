# Phase 128 - Dashboard and Performance

## Ergebnis

Das Instructor-Dashboard zeigt jetzt pro Student die neuen FSRS-Risikosignale direkt als `critical_cards_count`. Die Aggregation bleibt im bestehenden Course-Service-Pfad, die CSV-Ausgabe fuer At-Risk-Studenten ist dabei gleich mit korrigiert worden, und fuer die neue FSRS-Abfrage liegt jetzt ein zusaetzlicher Leitner-Index vor.

## Aenderungen

- `app/lib/Service/CourseService.php`
  - neuer Batch-Helper `getBatchCriticalCardStats()` fuer reviewed FSRS-Karten pro Student und Kurs-Pools
  - `getCourseProgress()` liefert jetzt `critical_cards_count` pro Student und unterstuetzt Sortierung nach diesem Feld
  - `getAtRiskStudents()` nutzt bei vorhandenen FSRS-Daten kritische Karten als Signal anstelle des alten Box-1-Stall-Heuristikpfads
  - Legacy-Fallback auf Box-1-Stall bleibt fuer nicht-FSRS-Daten erhalten

- `app/lib/Controller/CourseController.php`
  - CSV-Export fuer At-Risk-Studenten repariert: iteriert jetzt korrekt ueber `at_risk`
  - neue CSV-Spalte `Critical Cards`

- `app/src/components/CourseTabTeilnehmer.vue`
  - neue sortierbare Instructor-Spalte `Critical Cards`
  - neue Severity-Pills fuer kritische Karten im Progress-Table
  - At-Risk-Karten zeigen jetzt `Critical cards: {n}` in den Meta-Daten

- `app/lib/Migration/Version007100Date20260402010000.php`
  - neuer Leitner-Index `learn_lt_user_stability` auf `(user_id, stability)`

- `app/tests/unit/CourseTabTeilnehmer.test.js`
  - Unit-Test fuer Severity-Klassen der Critical-Card-Pills
  - Unit-Test fuer `fetchAtRisk()` mit `critical_cards_count`

- `app/l10n/de.json`
- `app/l10n/de.js`
  - DE-Texte fuer `Critical Cards` und `Critical cards: {n}`

## Verifikation

- lokal:
  - `node --check app/tests/unit/CourseTabTeilnehmer.test.js`
  - JSON-Parse fuer `app/l10n/de.json`
  - `git diff --check`
  - `cd app && npx eslint --ext .js,.vue src/`
    - 0 Errors, 13 bestehende Warnings
  - `cd app && npm run test -- --run`
    - `761 passed`

- `learning-dev`:
  - `./scripts/deploy-dev.sh --php-only`
    - PHP deployt, danach PHPStan auf `learning-dev` gruen
  - `./scripts/deploy-dev.sh --js-only`
    - Remote-Build und Bundle-Deploy gruen
  - Remote-Lints im Container:
    - `php -l lib/Service/CourseService.php`
    - `php -l lib/Controller/CourseController.php`
    - `php -l lib/Migration/Version007100Date20260402010000.php`
    - alle drei gruen

## Randbefunde

- Der im Handoff vorgeschlagene Badge-Index wurde bewusst nicht zusaetzlich angelegt: `learning_user_badges` hat bereits einen Unique-Index auf `(user_id, badge_id)`, der den vorgesehenen Lookup-Pfad schon abdeckt. Ein weiterer Index waere redundant gewesen.
- Ein echter browserbasierter API-Smoke gegen die DevCloud war in dieser Session nicht stabil automatisierbar: der Login-Flow blieb per Playwright auf `http://` wieder auf `/login?direct=1`, waehrend `https://` auf dem Dev-Stack mit `ERR_SSL_PROTOCOL_ERROR` scheitert. Die Phase ist deshalb ueber Build/Lint/PHPStan/Remote-Lints abgesichert, nicht ueber einen belastbaren End-to-End-Login.
