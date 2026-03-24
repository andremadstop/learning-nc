# Learning-NC — Nextcloud Spaced Repetition App

> Native Nextcloud App fuer Karteikarten-Lernen mit Leitner-System.
> PHP 8.1+ Backend, Vue 2.7 Frontend, PostgreSQL 16.

## Infrastruktur

| Was | Wo |
|-----|-----|
| Dev-Server | learning-dev (.65), CT 201 auf Proxmox (LXC, Debian 12) |
| Docker | `learning-app` (nextcloud:30, Port 8080), `learning-db` (postgres:16-alpine) |
| Code auf VM | `/home/andre/learning-nc/app/` |
| Code im Container | `/var/www/html/custom_apps/learning/` |
| Git Remote | github.com/andremadstop/learning-nc (privat, HTTPS via gh CLI) |
| NC-Kompatibilitaet | 29-31 |
| App-ID | `learning`, Namespace: `OCA\Learning` |

## Deploy-Workflow

**WICHTIG**: Der eigentliche Code laeuft im Docker-Container auf learning-dev.
Aenderungen lokal im Git-Repo machen, dann auf learning-dev deployen.

```bash
# Schnell-Deploy (empfohlen):
./scripts/deploy-dev.sh              # PHP + JS komplett
./scripts/deploy-dev.sh --php-only   # nur PHP + l10n (schnell)
./scripts/deploy-dev.sh --js-only    # nur Frontend bauen + deployen

# Einzelne Dateien (manuell):
scp app/lib/Controller/PoolController.php learning-dev:~/learning-nc/app/lib/Controller/
ssh learning-dev 'docker cp ~/learning-nc/app/lib/Controller/PoolController.php learning-app:/var/www/html/custom_apps/learning/lib/Controller/'
ssh learning-dev 'docker exec learning-app touch /var/www/html/custom_apps/learning/lib/Controller/PoolController.php && docker exec learning-app apache2ctl graceful'

# Frontend bauen + deployen:
ssh learning-dev 'cd ~/learning-nc/app && npm run build'
ssh learning-dev 'cd ~/learning-nc/app && tar cf /tmp/js-bundle.tar js/ && docker cp /tmp/js-bundle.tar learning-app:/tmp/ && docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && tar xf /tmp/js-bundle.tar"'

# Komplettes Sync (groessere Aenderungen):
rsync -avz --exclude node_modules --exclude .git app/ learning-dev:~/learning-nc/app/
ssh learning-dev 'docker cp ~/learning-nc/app/. learning-app:/var/www/html/custom_apps/learning/'
ssh learning-dev 'docker exec learning-app apache2ctl graceful'

# 4. Release-Tarball bauen
ssh learning-dev 'cd ~/learning-nc/app && sudo rm -rf build && sudo mkdir -p build/learning'
ssh learning-dev 'cd ~/learning-nc/app && sudo cp -r appinfo css img js l10n lib templates CHANGELOG.md LICENSE README.md build/learning/'
ssh learning-dev 'cd ~/learning-nc/app && sudo rm -f build/learning/js/*.map'
ssh learning-dev 'cd ~/learning-nc/app/build && sudo tar -czf learning-1.2.0.tar.gz learning'
```

## Projektstruktur (alles unter app/)

```
app/
├── appinfo/info.xml (v1.0.0), routes.php (51 routes)
├── lib/
│   ├── AppInfo/Application.php (DI + Dashboard Widget)
│   ├── Controller/ (10: Page, Pool, Question, Training, Leitner, Share, Image, Translation, Import, Course)
│   ├── Dashboard/LearningWidget.php (IAPIWidgetV2)
│   ├── Db/ (10 Entities + 10 Mapper)
│   ├── Service/ (10: Pool, Question, Training, Leitner, Share, Image, Translation, Analytics, Course, Role)
│   └── Migration/ (4 versions)
├── src/
│   ├── App.vue (Router, Pools/Kurse Tabs, Role Detection)
│   ├── main.js
│   └── components/ (11 Vue Components)
├── js/ (Webpack Output: learning.js + Chunks)
├── css/style.css
├── img/app.svg
├── l10n/ (DE Uebersetzungen)
├── docs/ (App Store Listings, Blog)
├── examples/ (Demo CSV/JSON)
└── build/ (Release Tarball)
```

## DB-Tabellen (13)

Alle unter PostgreSQL, Owner `oc_admin`:
- `oc_learning_pools`, `questions`, `answers`, `sessions`, `user_answers`
- `oc_learning_leitner_items`, `pool_shares`, `analytics`
- `oc_learning_question_translations`, `answer_translations`
- `oc_learning_courses`, `course_pools`, `course_members`

## API-Endpoints (51)

- Pools (5), Questions (6), Training (3), Leitner (4)
- Sharing (5), Images (3), Translations (6), Import (2)
- Courses (15), Pages (1)

Alle Routen in `app/appinfo/routes.php`.

## NotebookLM Wissensbasis

Notebook-ID: `94b8defe-9f20-466e-a908-2da154bad67a`

Enthaelt: README, Architektur (alle Controller/Services/Components/DB-Tabellen/APIs), CHANGELOG v1.0-v1.3.4, ROADMAP + Post-Launch Features, App Store Listing, NC Developer Manual, Spaced Repetition Theorie.

### Wann nutzen (via `notebook_query`)

- **Vor Feature-Planung**: "Welche Tabellen/APIs sind betroffen wenn ich X baue?"
- **Architektur-Fragen**: "Wie haengen TrainingService, LeitnerService und ExamMode zusammen?"
- **Security-Kontext**: "Welche Rate-Limits und Access-Controls gibt es bereits?"
- **NC-Framework**: "Welche Nextcloud-APIs koennte ich fuer Feature X nutzen?"
- **Wettbewerb/Theorie**: "Wie unterscheidet sich mein Leitner-Ansatz von SM-2?"

### Wann NICHT nutzen

- Konkreten Code lesen/schreiben → direkt die Dateien oeffnen
- Bugs fixen → Code lesen, nicht NotebookLM fragen
- Build/Deploy/Git → Bash

### Quellen aktuell halten

Nach groesseren Aenderungen (neue Features, API-Aenderungen, DB-Migrationen):
```
# Architektur-Quelle im Notebook updaten
notebook_query(notebook_id="94b8defe-...", query="Zeige aktuelle Quellen")
# Dann source_delete + source_add mit aktualisierten Infos
```

## Wichtige Pfade (Workstation)

| Was | Pfad |
|-----|------|
| Backlog | `~/ObsidianVaults/Personal/Ops/backlog/BACKLOG.md` |
| Learning-NC Vault-Ordner | `~/ObsidianVaults/Personal/Projekte/Learning-NC/` |
| Obsidian Personal Vault | `~/ObsidianVaults/Personal/` (inkl. Ops/) |

> **ACHTUNG**: Der alte Pfad `~/ObsidianVaults/PersonalOpsOS/` existiert NICHT mehr.
> Der Vault wurde am 2026-03-07 nach `~/ObsidianVaults/Personal/Ops/` gemerged.

## Test-Strategie (Quality Gates)

Jede Aenderung durchlaeuft die Gates in aufsteigender Reihenfolge. Fruehe Gates sind schnell und lokal, spaete Gates sind langsam und integrativ.

### Gate 1 — Statische Analyse (vor jedem Commit, ~30s)

| Tool | Befehl | Was |
|------|--------|-----|
| **PHPStan** Level 5 | `ssh learning-dev 'docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && php vendor/bin/phpstan analyse --no-progress"'` | PHP Typen, Null-Safety, NC-API |
| **ESLint** | `cd app && npx eslint --ext .js,.vue src/` | JS/Vue Fehler, kein v-html, kein eval |
| **Vitest** | `cd app && npm run test` | JS Unit-Tests (CLI State Machine, PBQ Scoring, Topology) |
| **Security Regression** | GitHub Actions automatisch | Kein eval(), kein v-html, keine hardcoded Creds |

### Gate 2 — API-Integration (nach Deploy, ~1min)

| Tool | Befehl | Was |
|------|--------|-----|
| **test-api.sh** | `scripts/test-api.sh` | 25+ curl-Tests, alle CRUD-Endpoints |
| **test-gemini.sh** | `scripts/test-gemini.sh` | 9 Tests: VirtuProf Chat, Rate-Limits, Prompt Injection |
| **smoke_test.py** | `python3 .ralph/smoke_test.py` | Alle 51 API-Endpoints |

### Gate 3 — Browser E2E (bei UI-Aenderungen, ~2min)

| Tool | Befehl | Was |
|------|--------|-----|
| **Playwright (app)** | `cd app && npm run test:e2e` | Critical Flows, PBQ, Exam Mode |
| **Playwright (root)** | `npx playwright test` | DevCloud Smoke: Nav, Subnetz, Zeitreise, Kampagnen |

### Gate 4 — Vollpruefung (vor Release)

| Tool | Wo | Was |
|------|-----|-----|
| **PHPUnit** | `scripts/deploy-dev.sh --test` | Service-Layer Unit-Tests |
| **TESTPROTOKOLL.md** | `.planning/TESTPROTOKOLL.md` | 62 manuelle Browser-Checks |
| **QA-VOLLPRUEFUNG.md** | `.planning/QA-VOLLPRUEFUNG.md` | 3-Agenten-Protokoll (Claude API, Codex Audit, Gemini Content) |

### Pre-Push Hook

Der Git Pre-Push Hook (``.git/hooks/pre-push``) blockiert Pushes die Gate 1 nicht bestehen:
- Security Scan (hardcoded Secrets)
- ESLint (0 Errors Pflicht)
- Vitest (alle Tests gruen)
- PHPStan (wenn learning-dev erreichbar)

### Regeln fuer Tests

- **Gate 1 ist Pflicht** — kein Commit ohne PHPStan + ESLint + Vitest
- **Gate 2 nach jedem Deploy** — mindestens `test-api.sh`
- **Gate 3 nur bei UI-Aenderungen** — Playwright ist langsam, gezielt einsetzen
- **Gate 4 nur vor Release** — TESTPROTOKOLL komplett durchgehen
- **Bruteforce-Reset** vor API-Tests: `ssh learning-dev 'docker exec -u www-data learning-app php occ security:bruteforce:reset 172.18.0.1'`
- **Neue Features brauchen Tests** — mindestens einen Vitest oder API-Test pro neuem Endpoint/Utility

## Regeln

1. **Code immer im `app/` Unterverzeichnis** — das ist die eigentliche NC-App
2. **Nach PHP-Aenderungen** OPcache resetten (siehe Deploy-Workflow)
3. **Nach Vue-Aenderungen** npm run build + JS in Container kopieren
4. **Testen**: Quality Gates oben durchlaufen (Gate 1 Pflicht, Gate 2+ nach Bedarf)
5. **NC App Framework**: QBMapper fuer DB, DI via Application.php, CSRF automatisch
6. **Keine Secrets in Code** — nur in .env oder Vaultwarden
7. **Version**: info.xml muss mit CHANGELOG.md und Git-Tag uebereinstimmen
8. **NotebookLM vor Feature-Planung**: Bei neuen Features oder Architektur-Fragen erst `notebook_query` nutzen, dann Code anfassen
9. **ESLint 0 Errors** — Warnings sind OK, Errors muessen vor Commit gefixt werden

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **learning-nc** (3711 symbols, 11280 relationships, 300 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## When Debugging

1. `gitnexus_query({query: "<error or symptom>"})` — find execution flows related to the issue
2. `gitnexus_context({name: "<suspect function>"})` — see all callers, callees, and process participation
3. `READ gitnexus://repo/learning-nc/process/{processName}` — trace the full execution flow step by step
4. For regressions: `gitnexus_detect_changes({scope: "compare", base_ref: "main"})` — see what your branch changed

## When Refactoring

- **Renaming**: MUST use `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` first. Review the preview — graph edits are safe, text_search edits need manual review. Then run with `dry_run: false`.
- **Extracting/Splitting**: MUST run `gitnexus_context({name: "target"})` to see all incoming/outgoing refs, then `gitnexus_impact({target: "target", direction: "upstream"})` to find all external callers before moving code.
- After any refactor: run `gitnexus_detect_changes({scope: "all"})` to verify only expected files changed.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Tools Quick Reference

| Tool | When to use | Command |
|------|-------------|---------|
| `query` | Find code by concept | `gitnexus_query({query: "auth validation"})` |
| `context` | 360-degree view of one symbol | `gitnexus_context({name: "validateUser"})` |
| `impact` | Blast radius before editing | `gitnexus_impact({target: "X", direction: "upstream"})` |
| `detect_changes` | Pre-commit scope check | `gitnexus_detect_changes({scope: "staged"})` |
| `rename` | Safe multi-file rename | `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` |
| `cypher` | Custom graph queries | `gitnexus_cypher({query: "MATCH ..."})` |

## Impact Risk Levels

| Depth | Meaning | Action |
|-------|---------|--------|
| d=1 | WILL BREAK — direct callers/importers | MUST update these |
| d=2 | LIKELY AFFECTED — indirect deps | Should test |
| d=3 | MAY NEED TESTING — transitive | Test if critical path |

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/learning-nc/context` | Codebase overview, check index freshness |
| `gitnexus://repo/learning-nc/clusters` | All functional areas |
| `gitnexus://repo/learning-nc/processes` | All execution flows |
| `gitnexus://repo/learning-nc/process/{name}` | Step-by-step execution trace |

## Self-Check Before Finishing

Before completing any code modification task, verify:
1. `gitnexus_impact` was run for all modified symbols
2. No HIGH/CRITICAL risk warnings were ignored
3. `gitnexus_detect_changes()` confirms changes match expected scope
4. All d=1 (WILL BREAK) dependents were updated

## Keeping the Index Fresh

After committing code changes, the GitNexus index becomes stale. Re-run analyze to update it:

```bash
npx gitnexus analyze
```

If the index previously included embeddings, preserve them by adding `--embeddings`:

```bash
npx gitnexus analyze --embeddings
```

To check whether embeddings exist, inspect `.gitnexus/meta.json` — the `stats.embeddings` field shows the count (0 means no embeddings). **Running analyze without `--embeddings` will delete any previously generated embeddings.**

> Claude Code users: A PostToolUse hook handles this automatically after `git commit` and `git merge`.

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |
| Work in the Js area (866 symbols) | `.claude/skills/generated/js/SKILL.md` |
| Work in the Service area (618 symbols) | `.claude/skills/generated/service/SKILL.md` |
| Work in the Controller area (23 symbols) | `.claude/skills/generated/controller/SKILL.md` |
| Work in the Db area (21 symbols) | `.claude/skills/generated/db/SKILL.md` |
| Work in the Cluster_1 area (6 symbols) | `.claude/skills/generated/cluster-1/SKILL.md` |
| Work in the Cluster_191 area (6 symbols) | `.claude/skills/generated/cluster-191/SKILL.md` |
| Work in the BackgroundJob area (3 symbols) | `.claude/skills/generated/backgroundjob/SKILL.md` |

<!-- gitnexus:end -->
