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

# Komplettes Sync (groessere Aenderungen):
rsync -avz --exclude node_modules --exclude .git app/ learning-dev:~/learning-nc/app/
ssh learning-dev 'docker cp ~/learning-nc/app/. learning-app:/var/www/html/custom_apps/learning/'
ssh learning-dev 'docker exec learning-app apache2ctl graceful'
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

Jede Aenderung durchlaeuft die Gates in aufsteigender Reihenfolge.

### Gate 1 — Statische Analyse (vor jedem Commit, ~30s)

| Tool | Befehl | Was |
|------|--------|-----|
| **PHPStan** Level 5 | `ssh learning-dev 'docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && php vendor/bin/phpstan analyse --no-progress"'` | PHP Typen, Null-Safety, NC-API |
| **ESLint** | `cd app && npx eslint --ext .js,.vue src/` | JS/Vue Fehler |
| **Vitest** | `cd app && npm run test` | JS Unit-Tests |

### Gate 2 — API-Integration (nach Deploy, ~1min)

| Tool | Befehl |
|------|--------|
| **test-api.sh** | `scripts/test-api.sh` |
| **smoke_test.py** | `python3 .ralph/smoke_test.py` |

### Gate 3 — Browser E2E (bei UI-Aenderungen)

| Tool | Befehl |
|------|--------|
| **Playwright** | `cd app && npm run test:e2e` |

### Gate 4 — Vollpruefung (vor Release)

PHPUnit (`scripts/deploy-dev.sh --test`), TESTPROTOKOLL.md (62 manuelle Checks).

### Regeln fuer Tests

- **Gate 1 ist Pflicht** — kein Commit ohne PHPStan + ESLint + Vitest
- **Gate 2 nach jedem Deploy** — mindestens `test-api.sh`
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
8. **ESLint 0 Errors** — Warnings sind OK, Errors muessen vor Commit gefixt werden
