# Phase 125 — FSRS Engine

## Ergebnis

Der Leitner-Backendkern nutzt jetzt FSRS-kompatible Stabilitaet/Schwierigkeit statt fester 1/3/7/14-Tage-Intervalle. Die bestehende `box`-Logik bleibt als Kompatibilitaetsschicht fuer Stats, Badges und XP erhalten.

## Aenderungen

- `app/lib/Migration/Version007000Date20260402000000.php`
  - neue Spalten in `learning_leitner_items`:
    - `stability`
    - `difficulty`
    - `last_rating`
  - Backfill bestehender Karten aus `box`-Werten
  - `next_review` fuer backfillte Karten an die neue Stabilitaet angenaehert

- `app/lib/Service/FsrsService.php`
  - neuer isolierter FSRS-Service
  - `initializeFromRating()` fuer Karten ohne bestehende FSRS-Daten
  - `review()` fuer Stabilitaets-/Schwierigkeits-Update pro Review
  - Retrievability-Berechnung und Intervallableitung

- `app/lib/Service/LeitnerService.php`
  - `answerQuestion()` akzeptiert jetzt optional `rating`
  - Rueckwaertskompatibilitaet:
    - ohne `rating` => `3` bei korrekter Antwort
    - ohne `rating` oder bei falscher Antwort => `1`
  - neue Persistenz pro Review:
    - `stability`
    - `difficulty`
    - `last_rating`
  - Smart Queue und Due Questions sortieren jetzt nach `retrievability ASC`
  - `box` bleibt fuer bestehende Mastery-/Badge-/Stats-Pfade erhalten:
    - `1` => Box 1
    - `2` => Box bleibt
    - `3`/`4` => Box +1

- `app/lib/Controller/LeitnerController.php`
  - API-Parameter `rating` an den Service durchgereicht

- `app/tests/Unit/Service/FsrsServiceTest.php`
- `app/tests/Unit/Service/LeitnerServiceTest.php`
  - neue Unit-Tests fuer FSRS-Kernlogik und Retrievability-Sortierung

## Verifikation

- lokal:
  - `git diff --check`
  - `cd app && npm run test -- --run`
  - `cd app && npx eslint --ext .js,.vue src/`

- `learning-dev`:
  - `./scripts/deploy-dev.sh --php-only`
  - volles PHPStan im Container nach Cache-Clear:
    - `php vendor/bin/phpstan clear-result-cache`
    - `php vendor/bin/phpstan analyse --no-progress`
  - gezielte PHP-Unit-Tests:
    - `FsrsServiceTest`
    - `LeitnerServiceTest`

## Randbefunde

- Auf `learning-dev` lag ein verwaistes Legacy-Deploy-Artefakt `~/learning-nc/app/lib/LeitnerService.php`, das denselben Klassennamen mit alter Signatur enthielt. Das hat den vollen PHPStan-Lauf verfaelscht, obwohl die aktuellen Dateien sauber waren. Das Artefakt wurde nur auf `learning-dev` entfernt.
- Der bestehende Deploy-Script-Check `require AppInfo/Application.php` meldet weiterhin erwartbar einen Bootstrap-Warnpfad, ist aber kein Syntaxfehler.
