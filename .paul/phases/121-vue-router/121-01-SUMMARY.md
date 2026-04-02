# Phase 121 Summary — Vue Router

## Outcome
- Added `vue-router@3` and registered the router in `app/src/main.js`.
- Added `app/src/router/index.js` with top-level routes for dashboard, courses, course detail, pools, tools, skill map, settings, and VirtuProf.
- Synced `App.vue` navigation state with `$route` so top-level navigation pushes real URLs instead of only mutating component data.
- Synced `CourseDetail.vue` mega-tab and leaf-tab navigation with route params while keeping Phase-120 Pinia tab sync intact.

## Verification
- `npm run lint` → 0 errors, 19 existing warnings
- `npm run test -- --run` → 748 passed
- `npm run build` → success, postbuild checks passed
- Manual remote build + JS deploy on `learning-dev` → successful

## Notes
- This phase uses a pragmatic route-sync approach inside the existing `App.vue` shell instead of splitting new top-level view components.
- Browser-level deep-link smoke (`/apps/learning/courses/:id/teilnehmer` and back/forward) was attempted, but the DevCloud login/storage-state flow was too brittle in this session for a clean automated confirmation.
