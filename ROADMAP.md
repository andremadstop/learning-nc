# Learning NC - 12 Week Roadmap

**From Zero to App Store Launch**

---

## Overview

| Phase | Weeks | Focus | Deliverable |
|-------|-------|-------|-------------|
| **Foundation** | 1-2 | Pool & Question CRUD | Core functionality works |
| **Core Features** | 3-5 | Training & Leitner | Learning system complete |
| **NC Integration** | 6-8 | Groups, Files, Dashboard | Nextcloud features |
| **Polish & Launch** | 9-12 | Exam, Analytics, App Store | Production ready |

**Total Time**: 12 weeks × 30-40h = 360-480 hours

---

## Phase 1: Foundation (Weeks 1-2)

### Week 1: Pool Management ✅ DONE
**Goal**: Pool CRUD Backend + Frontend

**Tasks**:
- [x] Project setup (Docker, Git, Docs)
- [x] Database: `learning_pools` table
- [x] Backend: Pool Entity, Mapper, Service, Controller
- [x] Frontend: Pool List UI (Create, Edit, Delete)
- [x] Vuex: Pools store module

**Deliverable**: User can create/edit/delete pools

**Time**: 30-40 hours

---

### Week 2: Questions & Answers ✅ DONE
**Goal**: Question CRUD with 4 Answers each

**Tasks**:
- [x] Database: `learning_questions`, `learning_answers` tables
- [x] Backend: Question/Answer Entities, Mappers, Services
- [x] API: Question CRUD endpoints
- [x] Frontend: Question List, Question Form (4 answers, mark correct)
- [x] UI: Pool → Questions drill-down

**Deliverable**: User can add questions to pools

**Time**: 30-40 hours

---

## Phase 2: Core Features (Weeks 3-5)

### Week 3: Training Mode ✅ DONE
**Goal**: Basic Multiple Choice Quiz

**Tasks**:
- [x] Training Session backend (track progress)
- [x] Frontend: Quiz UI (show question, 4 answers, next button)
- [x] Answer validation (correct/incorrect feedback)
- [x] Score tracking (X correct of Y total)
- [x] Session persistence

**Deliverable**: User can start training session, answer questions

**Time**: 25-30 hours

---

### Week 4: Leitner System (Part 1) ✅ DONE
**Goal**: 5-Box Spaced Repetition Backend

**Tasks**:
- [x] Database: `learning_box_sets`, `learning_box_items` tables
- [x] Leitner Logic: Box 1-5, intervals (1d, 3d, 7d, 14d, 30d)
- [x] Move Logic: Correct → Box+1, Wrong → Box 1
- [x] Due Date calculation
- [x] API: Leitner endpoints (get due cards, move card)

**Deliverable**: Leitner backend works

**Time**: 30-35 hours

---

### Week 5: Leitner System (Part 2)
**Goal**: Leitner UI & Progress Tracking

**Tasks**:
- Frontend: Leitner Box View (5 boxes, card counts)
- Daily Review UI (show due cards)
- Progress Stats (total cards, mastered, due today)
- Streak tracking (days in a row)
- Confetti animation (box 5 reached)

**Deliverable**: Full Leitner system functional

**Time**: 25-30 hours

---

## Phase 3: Nextcloud Integration (Weeks 6-8)

### Week 6: Group Sharing
**Goal**: Share Pools with NC Groups

**Tasks**:
- Database: `learning_pool_shares` table
- Backend: Group API integration
- Share UI: Select NC group, set permissions (view/edit)
- Shared pools discovery ("Shared with me" tab)
- Permissions enforcement

**Deliverable**: Teams can share pools

**Time**: 30-35 hours

---

### Week 7: File Import
**Goal**: Import Pools from CSV/JSON

**Tasks**:
- File Picker integration (@nextcloud/files)
- CSV Parser (Frage, A, B, C, D, Korrekt, Erklärung)
- JSON Import (QuizDojo format)
- Import Wizard UI (preview, validate, confirm)
- Error handling (duplicate questions, invalid format)

**Deliverable**: User uploads CSV → Pool created

**Time**: 25-30 hours

---

### Week 8: Dashboard & Notifications
**Goal**: NC Dashboard Widget + Notifications

**Tasks**:
- Dashboard Widget: "X cards due today", Streak display
- NC Notifications: Daily reminder, pool shared, streak warning
- Activity Stream: "User X completed Pool Y"
- Settings page: Notification preferences

**Deliverable**: Native NC experience

**Time**: 25-30 hours

---

## Phase 4: Polish & Launch (Weeks 9-12)

### Week 9: Exam Mode
**Goal**: Timed Exams with Pass/Fail

**Tasks**:
- Exam Session backend (timer, no hints)
- Frontend: Exam UI (timer overlay, navigation grid)
- Score calculation (% correct, pass/fail threshold)
- Exam history (past attempts)
- Certificate PDF (optional)

**Deliverable**: User can take timed exams

**Time**: 25-30 hours

---

### Week 10: Admin Analytics
**Goal**: Admin Dashboard & Reports

**Tasks**:
- Admin Settings page
- Analytics: Pool completion rates, user stats
- User Reports: Per-user progress, CSV export
- Compliance Reports: DSGVO-audit-ready
- System health: Active users, total questions

**Deliverable**: Admins see usage stats

**Time**: 30-35 hours

---

### Week 11: Multilang & Mobile
**Goal**: DE/EN Translation + Mobile Optimization

**Tasks**:
- i18n: German + English translations
- Mobile CSS: Touch targets (44px), responsive breakpoints
- Mobile gestures: Swipe to answer (optional)
- Language toggle: Switch pool language
- Testing: iOS Safari, Android Chrome

**Deliverable**: Works on mobile, 2 languages

**Time**: 25-30 hours

---

### Week 12: App Store Launch
**Goal**: Submit to Nextcloud App Store

**Tasks**:
- Code cleanup & comments
- PHPUnit tests (critical paths)
- Screenshots (min 3, max 6)
- info.xml complete (description, screenshots, links)
- Code signing (OpenSSL cert from GitHub)
- App Store submission
- Documentation: User manual, admin guide

**Deliverable**: App live in App Store! 🚀

**Time**: 30-40 hours

---

## Total Effort

**Minimum**: 12 weeks × 25h = 300 hours
**Average**: 12 weeks × 32h = 384 hours
**Maximum**: 12 weeks × 40h = 480 hours

**Part-Time (20h/week)**: 15-24 weeks
**Full-Time (40h/week)**: 8-12 weeks

---

## Key Milestones

| Week | Milestone | Demo |
|------|-----------|------|
| 2 | ✅ Pool + Questions | "I can add questions to pools" |
| 5 | ✅ Leitner System | "I can learn with spaced repetition" |
| 8 | ✅ NC Integration | "I can share pools with my team" |
| 12 | ✅ App Store | "Anyone can install my app" |

---

## Success Metrics (Month 1-3 after launch)

**Downloads**:
- 100+ Installations
- 50+ Active Instances
- 10+ Reviews (≥4.0★)

**Enterprise**:
- 5+ Companies (>100 users)
- 2+ Universities
- 1+ Government/NGO

**Community**:
- 10+ GitHub Stars
- 5+ Contributors
- 20+ Issues/PRs

---

## Risk Management

**Biggest Risks**:
1. **Vue 2.7 EOL** → Mitigation: Plan migration to Vue 3 (2027)
2. **Scope Creep** → Mitigation: Stick to MVP, reject feature requests
3. **NC API Changes** → Mitigation: Target NC 29-31 (2-year support)
4. **App Store Rejection** → Mitigation: Follow guidelines, test thoroughly

---

## Optional Features (Post-Launch)

**Nice-to-Have (not MVP)**:
- Swipe Mode (Tinder-style binary quiz)
- Gamification (XP, levels, badges)
- Duels (1v1 challenges)
- AI Question Generator (LLM integration)
- Collaborative Learning (real-time study groups)
- SCORM Export (LMS compatibility)

**Add only if demand exists!**

---

## Current Status

**Week**: 5 of 12
**Phase**: Core Features - Leitner UI
**Completed**: Weeks 1-4 (Pool CRUD, Questions, Training, Leitner Backend)
**Next**: Leitner Box View, Daily Review UI, Progress Stats

**Progress**:
- [x] Week 1: Pool Management CRUD
- [x] Week 2: Questions & Answers
- [x] Week 3: Training Mode
- [x] Week 4: Leitner System Backend
- [ ] Week 5: Leitner UI & Progress Tracking (next)

---

Last updated: 2026-02-11
