# Quick Task 05: Dozenten-UI Wissens-Import — Summary

**Completed:** 2026-03-28
**Commits:** dccd42b, 7cd9c61, 206646d

## What was built

Instructors can now import knowledge (text/markdown or .md/.txt files) directly from the CourseDetail UI into the RAG system for VirtuProf context — no server/OCC access needed.

### Backend (dccd42b)
- **RagImportService.php** — cleanMarkdown + splitIntoChunks pipeline, idempotent upsert (delete-by-title before insert), document_id=-1 convention for web imports
- **RagImportController.php** — 4 endpoints with instructor-only guards: importText, importFile, listImported, deleteImported
- **RagChunkMapper.php** — 2 new methods: deleteBySourceFileAndDocumentIdAndCourseId, findWebImportsByCourseId (grouped aggregation)
- **routes.php** — 4 new routes under /api/courses/{courseId}/knowledge/

### Frontend (7cd9c61, 206646d)
- **CourseKnowledgeImport.vue** — Vue 2.7 Options API component with text paste + file upload modes, imported documents list with delete, success/error feedback
- **CourseDetail.vue** — "Wissen" tab in Lernraum group (instructor-only, after Materialien)
- JS bundle rebuilt

## Files changed (917 lines added)

| File | Change |
|------|--------|
| app/lib/Service/RagImportService.php | New (276 lines) |
| app/lib/Controller/RagImportController.php | New (146 lines) |
| app/src/components/CourseKnowledgeImport.vue | New (430 lines) |
| app/lib/Db/RagChunkMapper.php | +49 lines (2 new methods) |
| app/appinfo/routes.php | +6 lines (4 routes) |
| app/src/components/CourseDetail.vue | +8 lines (tab + import) |
| app/js/learning.js | Rebuilt |
| app/js/learning-admin-settings.js | Rebuilt |

## Quality gates

- PHPStan Level 5: passed
- ESLint: passed
- Deploy to learning-dev: completed
