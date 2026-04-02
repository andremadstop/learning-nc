---
name: controller
description: "Skill for the Controller area of learning-nc. 23 symbols across 5 files."
---

# Controller

23 symbols | 5 files | Cohesion: 90%

## When to Use

- Working with code in `app/`
- Understanding how Question, createOrUpdate, createOrUpdate work
- Modifying controller-related functionality

## Key Files

| File | Symbols |
|------|---------|
| `app/lib/Controller/ImportController.php` | getMaxImportBytes, canEditPool, normalizeText, detectDelimiter, normalizeJsonItem (+6) |
| `app/lib/Controller/ExportController.php` | exportIcs, getCalendarToken, regenerateCalendarToken, exportIcsPublic, buildIcsBody (+4) |
| `app/lib/Db/QuestionMapper.php` | createOrUpdate |
| `app/lib/Db/Question.php` | Question |
| `app/lib/Db/AnswerMapper.php` | createOrUpdate |

## Entry Points

Start here when exploring this area:

- **`Question`** (Class) — `app/lib/Db/Question.php:7`
- **`createOrUpdate`** (Method) — `app/lib/Db/QuestionMapper.php:99`
- **`createOrUpdate`** (Method) — `app/lib/Db/AnswerMapper.php:52`
- **`getMaxImportBytes`** (Method) — `app/lib/Controller/ImportController.php:49`
- **`canEditPool`** (Method) — `app/lib/Controller/ImportController.php:55`

## Key Symbols

| Symbol | Type | File | Line |
|--------|------|------|------|
| `Question` | Class | `app/lib/Db/Question.php` | 7 |
| `createOrUpdate` | Method | `app/lib/Db/QuestionMapper.php` | 99 |
| `createOrUpdate` | Method | `app/lib/Db/AnswerMapper.php` | 52 |
| `getMaxImportBytes` | Method | `app/lib/Controller/ImportController.php` | 49 |
| `canEditPool` | Method | `app/lib/Controller/ImportController.php` | 55 |
| `normalizeText` | Method | `app/lib/Controller/ImportController.php` | 68 |
| `detectDelimiter` | Method | `app/lib/Controller/ImportController.php` | 86 |
| `normalizeJsonItem` | Method | `app/lib/Controller/ImportController.php` | 96 |
| `applyQuestionMetadata` | Method | `app/lib/Controller/ImportController.php` | 158 |
| `extractImportMeta` | Method | `app/lib/Controller/ImportController.php` | 170 |
| `importCsv` | Method | `app/lib/Controller/ImportController.php` | 193 |
| `importJson` | Method | `app/lib/Controller/ImportController.php` | 386 |
| `importPbqItem` | Method | `app/lib/Controller/ImportController.php` | 571 |
| `importFile` | Method | `app/lib/Controller/ImportController.php` | 619 |
| `exportIcs` | Method | `app/lib/Controller/ExportController.php` | 120 |
| `getCalendarToken` | Method | `app/lib/Controller/ExportController.php` | 132 |
| `regenerateCalendarToken` | Method | `app/lib/Controller/ExportController.php` | 150 |
| `exportIcsPublic` | Method | `app/lib/Controller/ExportController.php` | 168 |
| `buildIcsBody` | Method | `app/lib/Controller/ExportController.php` | 177 |
| `getOrCreateAppSecret` | Method | `app/lib/Controller/ExportController.php` | 244 |

## Execution Flows

| Flow | Type | Steps |
|------|------|-------|
| `ImportFile → FindByPoolAndUser` | cross_community | 4 |
| `ImportFile → NormalizeText` | intra_community | 3 |
| `ImportFile → GetMaxImportBytes` | intra_community | 3 |
| `ImportFile → ExtractImportMeta` | intra_community | 3 |
| `ImportCsv → FindByPoolAndUser` | cross_community | 3 |
| `ImportPbqItem → GetId` | cross_community | 3 |
| `ExportIcsPublic → GetOrCreateAppSecret` | intra_community | 3 |
| `ExportIcsPublic → IcsEscape` | intra_community | 3 |
| `Create → Question` | cross_community | 3 |

## Connected Areas

| Area | Connections |
|------|-------------|
| Service | 9 calls |

## How to Explore

1. `gitnexus_context({name: "Question"})` — see callers and callees
2. `gitnexus_query({query: "controller"})` — find related execution flows
3. Read key files listed above for implementation details
