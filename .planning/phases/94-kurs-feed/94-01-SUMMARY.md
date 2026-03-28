---
phase: 94-kurs-feed
plan: 01
subsystem: api
tags: [feed, rest-api, qbmapper, nextcloud, postgresql]

requires:
  - phase: none
    provides: existing CourseService, CourseMemberMapper, routes.php patterns
provides:
  - learning_feed_items table and migration
  - FeedItem entity and FeedItemMapper
  - FeedService with getUserFeed, getCourseFeed, createAutoItem, cleanupOldItems
  - FeedController with GET /api/feed and GET /api/courses/{courseId}/feed
  - Auto-feed hooks in CourseService (createAnnouncement, addPool)
affects: [94-kurs-feed-plan-02, frontend-feed-widget]

tech-stack:
  added: []
  patterns: [feed-item-auto-generation-via-service-hook, qbmapper-in-clause-with-param-int-array]

key-files:
  created:
    - app/lib/Migration/Version005000Date20260328000000.php
    - app/lib/Db/FeedItem.php
    - app/lib/Db/FeedItemMapper.php
    - app/lib/Service/FeedService.php
    - app/lib/Controller/FeedController.php
    - app/tests/unit/Db/FeedItemMapper.test.js
  modified:
    - app/appinfo/routes.php
    - app/lib/Service/CourseService.php

key-decisions:
  - "FeedService injected into CourseService via constructor DI for auto-feed hooks"
  - "Feed items use JSON meta field for type-specific data (pool_id, etc.)"

patterns-established:
  - "Auto-feed pattern: service methods create feed items as side-effect of domain actions"
  - "FeedItemMapper::findByUserCourses uses PARAM_INT_ARRAY for IN clause"

requirements-completed: [FEED-01, FEED-02, FEED-03]

duration: 6min
completed: 2026-03-28
---

# Phase 94 Plan 01: Kurs-Feed Backend Summary

**Feed API with auto-generated items from announcements and pool additions, QBMapper-based aggregation across enrolled courses**

## Performance

- **Duration:** 6 min
- **Started:** 2026-03-28T00:41:12Z
- **Completed:** 2026-03-28T00:47:00Z
- **Tasks:** 2
- **Files modified:** 8

## Accomplishments
- Database migration creating learning_feed_items table with course_id, type, and composite indexes
- FeedItem entity with JSON-decoded meta field and FeedItemMapper with multi-course query, pagination, and cleanup methods
- FeedService aggregating feed across all enrolled courses via CourseMemberMapper
- FeedController exposing GET /api/feed (all courses) and GET /api/courses/{courseId}/feed (single course)
- Auto-feed hooks: creating an announcement or adding a pool to a course automatically generates a feed item

## Task Commits

Each task was committed atomically:

1. **Task 1: Database migration, FeedItem entity, FeedItemMapper** - `8d8256d` (feat)
2. **Task 2: FeedService, FeedController, routes, and CourseService hook** - `0238d2a` (feat)

## Files Created/Modified
- `app/lib/Migration/Version005000Date20260328000000.php` - Creates learning_feed_items table
- `app/lib/Db/FeedItem.php` - Entity with JSON meta decode in jsonSerialize
- `app/lib/Db/FeedItemMapper.php` - QBMapper with findByUserCourses, findByCourse, deleteOlderThan, countByCourse
- `app/lib/Service/FeedService.php` - Feed aggregation and auto-item creation
- `app/lib/Controller/FeedController.php` - REST endpoints for feed
- `app/tests/unit/Db/FeedItemMapper.test.js` - Contract tests for entity serialization and query behavior
- `app/appinfo/routes.php` - Added feed routes
- `app/lib/Service/CourseService.php` - Added FeedService DI and auto-feed hooks

## Decisions Made
- FeedService injected into CourseService via constructor DI -- NC autowiring handles it without Application.php changes
- Feed items use a JSON meta column for type-specific data (e.g., pool_id), decoded in entity jsonSerialize
- PHPStan @var annotation used for insert() return type narrowing (QBMapper returns Entity base type)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed PHPStan return type for FeedService::createAutoItem**
- **Found during:** Task 2 (FeedService PHPStan check)
- **Issue:** QBMapper::insert() returns Entity base type, not FeedItem -- PHPStan level 5 flagged return type mismatch
- **Fix:** Added @var FeedItem annotation to narrow the return type
- **Files modified:** app/lib/Service/FeedService.php
- **Verification:** PHPStan level 5 passes clean
- **Committed in:** 0238d2a (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 bug)
**Impact on plan:** Standard PHPStan type narrowing. No scope creep.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Feed API fully functional, ready for frontend consumption in Plan 02
- Migration needs to be run on dev server before API testing: `docker exec -u www-data learning-app php occ migrations:migrate learning`

---
*Phase: 94-kurs-feed*
*Completed: 2026-03-28*
