# Learning - Nextcloud Learning App

**Spaced Repetition Learning with Leitner System for Nextcloud**

---

## 🎯 Project Goals

Build a **Best-in-Class Enterprise Learning App** for Nextcloud:
- 🧠 Leitner 5-Box Spaced Repetition System
- 👥 Team-Learning via Nextcloud Groups
- 📊 Admin Analytics & Compliance Reports
- 📁 CSV/JSON Import from Nextcloud Files
- 📱 Mobile-Optimized UI

**Target**: Launch in Nextcloud App Store after 12 weeks

---

## 📁 Project Structure

```
learning-nc/
├── DEVELOPMENT.md          # This file - Dev guide
├── ROADMAP.md             # 12-week roadmap
├── docker-compose.yml     # Local Nextcloud dev environment
├── .env                   # Environment variables
└── app/                   # Learning app source code
    ├── appinfo/
    ├── lib/
    ├── src/
    └── ...
```

---

## 🚀 Quick Start

### 1. Start Local Nextcloud
```bash
cd /home/andre/AIWorkspace/learning-nc
docker-compose up -d
```

### 2. Wait for Nextcloud to be ready (~60s)
```bash
docker-compose logs -f app
# Wait for: "Nextcloud is already installed"
```

### 3. Access Nextcloud
- URL: http://localhost:8080
- Admin: admin / admin
- Database: PostgreSQL 16

### 4. Create App Skeleton
```bash
docker-compose exec app php occ app:create learning \
  --author="Andre" \
  --email="dev@quizdojo.com" \
  --license="agpl" \
  --namespace="Learning"
```

### 5. Enable App
```bash
docker-compose exec app php occ app:enable learning
```

---

## 🛠️ Development Workflow

### Daily Development
```bash
# 1. Start containers
cd /home/andre/AIWorkspace/learning-nc
docker-compose up -d

# 2. Edit code in app/
nano app/lib/Controller/PoolController.php

# 3. Run migrations (if needed)
docker-compose exec app php occ migrations:execute learning 000100

# 4. Clear cache
docker-compose exec app php occ app:disable learning
docker-compose exec app php occ app:enable learning

# 5. Test in browser
# http://localhost:8080
```

### Frontend Development
```bash
cd app/
npm install
npm run dev  # Webpack watch mode

# In browser: http://localhost:8080/apps/learning
# Ctrl+Shift+R to reload
```

### Database Access
```bash
# PostgreSQL CLI
docker-compose exec db psql -U nextcloud -d nextcloud

# Example queries:
\dt learning_*              # List tables
SELECT * FROM learning_pools;
```

---

## 📦 Tech Stack

**Backend**:
- PHP 8.3
- Nextcloud App Framework 30
- PostgreSQL 16
- QueryBuilder ORM (Doctrine DBAL)

**Frontend**:
- Vue 2.7
- Vuex 3
- @nextcloud/vue 8.x
- Webpack 5

**Development**:
- Docker Compose
- Git
- VSCode/Cursor

---

## 📅 Current Status

**Week**: 1 of 12
**Phase**: Foundation - Pool Management
**Progress**: Setup complete, starting implementation

### Completed
- [x] Project setup
- [x] Docker environment
- [ ] Pool CRUD backend
- [ ] Pool Management UI
- [ ] Question/Answer system
- [ ] Leitner system
- [ ] Nextcloud integration
- [ ] App Store submission

---

## 🔧 Useful Commands

### Docker
```bash
# Start
docker-compose up -d

# Stop
docker-compose down

# Logs
docker-compose logs -f app

# Shell access
docker-compose exec app bash

# Restart
docker-compose restart app
```

### Nextcloud OCC
```bash
# App management
docker-compose exec app php occ app:list
docker-compose exec app php occ app:enable learning
docker-compose exec app php occ app:disable learning

# Migrations
docker-compose exec app php occ migrations:status learning
docker-compose exec app php occ migrations:execute learning 000100

# Maintenance
docker-compose exec app php occ maintenance:mode --on
docker-compose exec app php occ maintenance:mode --off

# User management
docker-compose exec app php occ user:list
docker-compose exec app php occ user:add testuser --group=users
```

### Git
```bash
cd app/
git add .
git commit -m "Feature: Pool CRUD"
git push origin main
```

---

## 📊 Deployment to Production

**Production**: Nextcloud on Proxmox (ptapi server)

### Deployment Steps
```bash
# 1. Build frontend
cd /home/andre/AIWorkspace/learning-nc/app
npm run build

# 2. Run tests (later)
npm test

# 3. Create release tarball
tar -czf learning-0.1.0.tar.gz app/ --exclude=node_modules --exclude=.git

# 4. Deploy to production
scp learning-0.1.0.tar.gz ptapi:/tmp/
ssh ptapi "cd /path/to/nextcloud/custom_apps && tar -xzf /tmp/learning-0.1.0.tar.gz"

# 5. Enable on production
ssh ptapi "sudo -u www-data php /path/to/nextcloud/occ app:enable learning"
```

**NOTE**: Production paths need to be verified!

---

## 🐛 Troubleshooting

### App not showing in menu
```bash
docker-compose exec app php occ app:enable learning
docker-compose exec app php occ maintenance:mode --off
```

### Database migration failed
```bash
docker-compose exec app php occ migrations:status learning
docker-compose exec app php occ migrations:execute learning --force
```

### Frontend not updating
```bash
cd app/
rm -rf js/
npm run build
# Hard reload browser: Ctrl+Shift+R
```

### Permission errors
```bash
docker-compose exec app chown -R www-data:www-data /var/www/html/custom_apps/learning
```

---

## 📚 Resources

**Nextcloud Docs**:
- [App Development Tutorial](https://docs.nextcloud.com/server/latest/developer_manual/app_development/tutorial.html)
- [Database Access](https://docs.nextcloud.com/server/stable/developer_manual/basics/storage/database.html)
- [Controllers](https://docs.nextcloud.com/server/stable/developer_manual/basics/controllers.html)

**Our Docs**:
- `ROADMAP.md` - 12-week plan
- `docs/NEXTCLOUD_NATIVE_PHP_APP_GUIDE.md` - Complete reference
- `docs/NEXTCLOUD_LEARNING_APP_MVP_PLAN.md` - Feature priorities

**Related Projects**:
- QuizDojo Standalone: `/home/andre/AIWorkspace/gitfiles/pruefungstrainer/`
- GitHub: https://github.com/andremadstop/quizdojo

---

## 👥 Team

- **Developer**: Andre
- **AI Assistant**: Claude Opus 4.6
- **License**: AGPL-3.0
- **Repository**: TBD

---

Last updated: 2026-02-10
