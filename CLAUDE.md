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
# 1. PHP-Dateien auf learning-dev kopieren
scp app/lib/Controller/PoolController.php learning-dev:~/learning-nc/app/lib/Controller/
ssh learning-dev 'docker cp ~/learning-nc/app/lib/Controller/PoolController.php learning-app:/var/www/html/custom_apps/learning/lib/Controller/'
ssh learning-dev 'docker exec learning-app php -r "opcache_reset();"'

# 2. Frontend bauen + deployen
ssh learning-dev 'cd ~/learning-nc/app && npm run build'
ssh learning-dev 'cd ~/learning-nc/app && tar cf /tmp/js-bundle.tar js/'
ssh learning-dev 'docker cp /tmp/js-bundle.tar learning-app:/tmp/'
ssh learning-dev 'docker exec learning-app bash -c "cd /var/www/html/custom_apps/learning && tar xf /tmp/js-bundle.tar"'

# 3. Komplettes Sync (alternativ fuer groessere Aenderungen)
rsync -avz --exclude node_modules --exclude .git app/ learning-dev:~/learning-nc/app/
ssh learning-dev 'docker cp ~/learning-nc/app/. learning-app:/var/www/html/custom_apps/learning/'
ssh learning-dev 'docker exec learning-app php -r "opcache_reset();"'

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

## Regeln

1. **Code immer im `app/` Unterverzeichnis** — das ist die eigentliche NC-App
2. **Nach PHP-Aenderungen** OPcache resetten (siehe Deploy-Workflow)
3. **Nach Vue-Aenderungen** npm run build + JS in Container kopieren
4. **Testen**: curl gegen `http://learning-dev:8080` (admin/admin, testuser/T3stUs3r!2026Secure)
5. **NC App Framework**: QBMapper fuer DB, DI via Application.php, CSRF automatisch
6. **Keine Secrets in Code** — nur in .env oder Vaultwarden
7. **Version**: info.xml muss mit CHANGELOG.md und Git-Tag uebereinstimmen
