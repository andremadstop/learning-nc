# 152-02 Summary - Scholar Archetype Registry Entries

Date: 2026-04-25
Owner: Codex
Status: DONE

## Scope Completed

- Added `theoretiker`, `kosmologe`, and `popularisierer` to `app/src/data/characters.js`.
- Each entry follows the Phase 151 character schema: id, name, role, personality, palette, silhouette, states, campaignAppearances, user_selectable, category, preview_thumbnail_svg.
- All three scholars are selectable with `category: 'scholar'`, `preview_thumbnail_svg: null`, and states covering `idle`, `wave`, and `celebrate`.
- Updated the stale Phase 151 picker assertion in `app/tests/unit/characters.test.js` so the full suite expects the shipped selectable skins after Phase 152.

## Verification

- `npx vitest run tests/unit/scholarArchetypes.test.js tests/unit/SkinRenderer.scholars.test.js --reporter=default` - PASS, 8/8 tests.
- `npx vitest run tests/unit/characters.test.js tests/unit/scholarArchetypes.test.js tests/unit/SkinRenderer.scholars.test.js --reporter=default` - PASS, 14/14 tests.
- `npx eslint --ext .js src/data/characters.js tests/unit/characters.test.js tests/unit/scholarArchetypes.test.js tests/unit/SkinRenderer.scholars.test.js` - PASS.
- `bash scripts/check-forbidden-names.sh` - PASS.
- `npm run build` - PASS, build checks passed.
- `npm run test -- --run` - PASS, 75 files / 1044 tests.

## Notes

- No forbidden real-person or media-brand names were added to registry strings.
- Palette tokens used by the new entries already exist in `app/css/style.css` or were already used by existing character entries.
- `CharacterAvatar.vue`, SkinRenderer, settings views, deploy scripts, SIGNOFF, and Character Bible were intentionally not changed.
