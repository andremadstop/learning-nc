# Learning - Nextcloud Learning App

**Enterprise Spaced Repetition Learning with Leitner System**

> 🎯 Goal: Launch in Nextcloud App Store in 12 weeks

---

## Quick Start

```bash
# 1. Start local Nextcloud
cd /home/andre/AIWorkspace/learning-nc
docker-compose up -d

# 2. Wait ~60 seconds, then open browser
# http://localhost:8080
# Login: admin / admin

# 3. Create app skeleton
docker-compose exec app php occ app:create learning \
  --author="Andre" \
  --email="dev@quizdojo.com" \
  --license="agpl" \
  --namespace="Learning"

# 4. Enable app
docker-compose exec app php occ app:enable learning
```

---

## Features (Planned)

- ✅ Multiple Choice Quizzes
- ✅ Leitner 5-Box Spaced Repetition
- ✅ Team-Learning (Nextcloud Groups)
- ✅ Admin Analytics & Reports
- ✅ CSV/JSON Import
- ✅ Mobile-Optimized
- ✅ DSGVO-Compliant (Self-Hosted)

---

## Documentation

- **[DEVELOPMENT.md](DEVELOPMENT.md)** - Developer guide (setup, workflow, commands)
- **[ROADMAP.md](ROADMAP.md)** - 12-week implementation plan
- **[docs/](docs/)** - Architecture, decisions, references

---

## Tech Stack

**Backend**: PHP 8.3, Nextcloud Framework 30, PostgreSQL 16
**Frontend**: Vue 2.7, Vuex 3, @nextcloud/vue, Webpack 5
**Dev**: Docker Compose, Git

---

## Status

**Week 1 of 12** - Foundation Phase
**Current Task**: Pool Management CRUD

---

## Related Projects

- **QuizDojo Standalone**: `/home/andre/AIWorkspace/gitfiles/pruefungstrainer/`
- **GitHub**: https://github.com/andremadstop/quizdojo

---

## License

AGPL-3.0
