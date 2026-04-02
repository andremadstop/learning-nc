---
name: db
description: "Skill for the Db area of learning-nc. 21 symbols across 13 files."
---

# Db

21 symbols | 13 files | Cohesion: 67%

## When to Use

- Working with code in `app/`
- Understanding how CurriculumScope, Analytics, findByPoolPaged work
- Modifying db-related functionality

## Key Files

| File | Symbols |
|------|---------|
| `app/lib/Service/CourseService.php` | saveCurriculumScope, findAll, getDashboard |
| `app/lib/Controller/CourseController.php` | updateCurriculumScope, index, dashboard |
| `app/lib/Db/CourseMemberMapper.php` | findByUser, countStudentsByCourse, countUniqueStudentsByInstructor |
| `app/lib/Db/QuestionMapper.php` | findByPoolIdPaged, countByPoolId |
| `app/lib/Db/CurriculumScope.php` | CurriculumScope, setChapterKeys |
| `app/lib/Service/QuestionService.php` | findByPoolPaged |
| `app/lib/Db/AnswerMapper.php` | findByQuestions |
| `app/lib/Controller/QuestionController.php` | index |
| `app/lib/Controller/VirtuProfController.php` | setEnabled |
| `app/lib/Db/CourseMapper.php` | findByInstructor |

## Entry Points

Start here when exploring this area:

- **`CurriculumScope`** (Class) — `app/lib/Db/CurriculumScope.php:22`
- **`Analytics`** (Class) — `app/lib/Db/Analytics.php:7`
- **`findByPoolPaged`** (Method) — `app/lib/Service/QuestionService.php:268`
- **`findByPoolIdPaged`** (Method) — `app/lib/Db/QuestionMapper.php:33`
- **`countByPoolId`** (Method) — `app/lib/Db/QuestionMapper.php:71`

## Key Symbols

| Symbol | Type | File | Line |
|--------|------|------|------|
| `CurriculumScope` | Class | `app/lib/Db/CurriculumScope.php` | 22 |
| `Analytics` | Class | `app/lib/Db/Analytics.php` | 7 |
| `findByPoolPaged` | Method | `app/lib/Service/QuestionService.php` | 268 |
| `findByPoolIdPaged` | Method | `app/lib/Db/QuestionMapper.php` | 33 |
| `countByPoolId` | Method | `app/lib/Db/QuestionMapper.php` | 71 |
| `findByQuestions` | Method | `app/lib/Db/AnswerMapper.php` | 33 |
| `index` | Method | `app/lib/Controller/QuestionController.php` | 24 |
| `saveCurriculumScope` | Method | `app/lib/Service/CourseService.php` | 1890 |
| `setChapterKeys` | Method | `app/lib/Db/CurriculumScope.php` | 45 |
| `setEnabled` | Method | `app/lib/Controller/VirtuProfController.php` | 96 |
| `updateCurriculumScope` | Method | `app/lib/Controller/CourseController.php` | 598 |
| `findAll` | Method | `app/lib/Service/CourseService.php` | 504 |
| `findByUser` | Method | `app/lib/Db/CourseMemberMapper.php` | 20 |
| `findByInstructor` | Method | `app/lib/Db/CourseMapper.php` | 19 |
| `index` | Method | `app/lib/Controller/CourseController.php` | 48 |
| `getDashboard` | Method | `app/lib/Service/CourseService.php` | 1302 |
| `countStudentsByCourse` | Method | `app/lib/Db/CourseMemberMapper.php` | 66 |
| `countUniqueStudentsByInstructor` | Method | `app/lib/Db/CourseMemberMapper.php` | 78 |
| `dashboard` | Method | `app/lib/Controller/CourseController.php` | 277 |
| `record` | Method | `app/lib/Service/AnalyticsService.php` | 14 |

## Execution Flows

| Flow | Type | Steps |
|------|------|-------|
| `Index → FindByPoolAndUser` | cross_community | 4 |
| `Index → HasCoursePoolAccess` | cross_community | 4 |
| `Index → FindByPoolId` | cross_community | 3 |
| `Index → GetId` | cross_community | 3 |
| `Index → FindByQuestions` | cross_community | 3 |
| `Index → FindByPoolIdPaged` | intra_community | 3 |
| `Index → CountByPoolId` | intra_community | 3 |
| `ExportCsv → FindByQuestions` | cross_community | 3 |
| `SaveCurriculumScope → FindByCourseAndUser` | cross_community | 3 |
| `SaveCurriculumScope → GetId` | cross_community | 3 |

## Connected Areas

| Area | Connections |
|------|-------------|
| Service | 15 calls |

## How to Explore

1. `gitnexus_context({name: "CurriculumScope"})` — see callers and callees
2. `gitnexus_query({query: "db"})` — find related execution flows
3. Read key files listed above for implementation details
