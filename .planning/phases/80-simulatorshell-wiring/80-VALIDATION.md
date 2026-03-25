# Phase 80: Validation Architecture

**Source:** 80-RESEARCH.md (Validation Architecture section)
**Phase:** 80-simulatorshell-wiring
**Created:** 2026-03-25

## Test Framework

| Property | Value |
|----------|-------|
| Framework | Vitest (bereits konfiguriert) |
| Config file | `app/vitest.config.js` |
| Quick run command | `cd /home/andre/Workspace/Code/learning-nc/app && npm run test -- --run` |
| Full suite command | `cd /home/andre/Workspace/Code/learning-nc/app && npm run test` |
| ESLint command | `cd /home/andre/Workspace/Code/learning-nc/app && npm run lint` |

## Requirement -> Test Map

| Req ID | Behavior | Test Type | Automated Command | Test File |
|--------|----------|-----------|-------------------|-----------|
| SIM-01 | SimulatorShell rendert richtige Komponente per type | unit | `npm run test -- --run SimulatorShell` | `app/tests/unit/SimulatorShell.test.js` |
| SIM-02 | onResult normiert alle 7 @result Shapes korrekt; pass_flag via graph-traverse gesetzt | unit | `npm run test -- --run SimulatorShell` | `app/tests/unit/SimulatorShell.test.js` |
| SIM-03 | beforeDestroy auf allen 7 Simulatoren vorhanden | unit | `npm run test -- --run simulatorLifecycle` | `app/tests/unit/simulatorLifecycle.test.js` |
| SIM-04 | SIMULATOR_MAP enthaelt alle 7 Keys | unit | `npm run test -- --run SimulatorShell` | `app/tests/unit/SimulatorShell.test.js` |
| SIM-05 | resolvedScenario gibt scenarioOverride wenn vorhanden | unit | `npm run test -- --run SimulatorShell` | `app/tests/unit/SimulatorShell.test.js` |

## Wave 0 Test Scaffold (erstellt in Plans 80-01 + 80-02)

- [ ] `app/tests/unit/SimulatorShell.test.js` — deckt SIM-01, SIM-02, SIM-04, SIM-05 ab
- [ ] `app/tests/unit/simulatorLifecycle.test.js` — deckt SIM-03 ab (beforeDestroy pro Komponente)

## Test-Sampling-Rate

| Zeitpunkt | Befehl |
|-----------|--------|
| Nach jedem Task-Commit | `npm run test -- --run` |
| Nach Wave-Merge | `npm run test` (vollstaendig) |
| Phase-Gate (vor Abschluss) | `npm run test && npm run lint && PHPStan Level 5` |

## Erwartete Test-Counts nach Phase 80

| Test-Datei | Tests | Anmerkung |
|-----------|-------|-----------|
| `simulatorLifecycle.test.js` | 7 | 1 pro Simulator-Komponente |
| `SimulatorShell.test.js` | 9+ | SIMULATOR_MAP, onResult-Shapes, resolvedScenario |
| Bestehende Suite | 162+ | Darf nicht regressieren |

## Phase-Gate Verifikation (vor 80-03 Abschluss)

```bash
# Gate 1: Unit-Tests + ESLint
cd /home/andre/Workspace/Code/learning-nc/app && npm run test -- --run && npm run lint

# Gate 2: PHPStan (kein PHP veraendert, trotzdem pruefen)
ssh learning-dev 'docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && php vendor/bin/phpstan analyse --no-progress"'

# Gate 3: Browser E2E (nach Deploy)
./scripts/deploy-dev.sh --js-only
# Dann manuelle Verifikation: Kampagne "Test Graph Kampagne" -> Simulator-Node -> loesen -> naechste Szene
```

## Kritische Verhaltenspruefungen (manuell, Plan 80-03 Checkpoint)

- [ ] Simulator-Komponente erscheint eingebettet (kein separater Tab)
- [ ] Nach Loesen: naechste Graph-Szene laedt automatisch (graph-traverse aufgerufen)
- [ ] Nach Nicht-Loesen: "Erneut versuchen"-Button sichtbar
- [ ] stateBag aktualisiert sich nach graph-traverse (Vue 2 Reaktivitaet: neue Referenz)
- [ ] Linearer Kampagnen-Pfad unveraendert funktional (kein Regression)
