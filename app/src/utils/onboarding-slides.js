/**
 * Onboarding Slideshow Configuration
 *
 * WORKFLOW: To add/update slides:
 * 1. Add a new entry to STUDENT_SLIDES or INSTRUCTOR_SLIDES below
 * 2. Each slide needs: id, icon (emoji or SVG path), titleKey, textKey
 * 3. Add German translations to l10n/de.json (keys = titleKey/textKey values)
 * 4. English fallback is the key itself (keep keys readable)
 * 5. Deploy: ./scripts/deploy-dev.sh --js-only
 *
 * Slide properties:
 *   id         — unique identifier (used as v-for key)
 *   icon       — emoji string OR svg path data for a 24x24 viewBox
 *   titleKey   — i18n key for the slide title
 *   textKey    — i18n key for the slide body text
 *   highlight  — optional CSS class name to highlight a UI element
 */

/* ── Full slide arrays (backward compat) ──────────────── */

export const STUDENT_SLIDES_FULL = [
  {
    id: 'welcome',
    icon: '\uD83D\uDC4B',
    titleKey: 'slide:student:welcome:title',
    textKey: 'slide:student:welcome:text',
  },
  {
    id: 'courses',
    icon: '\uD83D\uDCDA',
    titleKey: 'slide:student:courses:title',
    textKey: 'slide:student:courses:text',
  },
  {
    id: 'training',
    icon: '\uD83C\uDFAF',
    titleKey: 'slide:student:training:title',
    textKey: 'slide:student:training:text',
  },
  {
    id: 'leitner',
    icon: '\uD83D\uDCE6',
    titleKey: 'slide:student:leitner:title',
    textKey: 'slide:student:leitner:text',
  },
  {
    id: 'fsrs',
    icon: '\uD83E\uDDE0',
    titleKey: 'slide:student:fsrs:title',
    textKey: 'slide:student:fsrs:text',
  },
  {
    id: 'arena',
    icon: '\u2694\uFE0F',
    titleKey: 'slide:student:arena:title',
    textKey: 'slide:student:arena:text',
  },
  {
    id: 'tools',
    icon: '\uD83D\uDD27',
    titleKey: 'slide:student:tools:title',
    textKey: 'slide:student:tools:text',
  },
  {
    id: 'virtuprof',
    icon: '\uD83E\uDD16',
    titleKey: 'slide:student:virtuprof:title',
    textKey: 'slide:student:virtuprof:text',
  },
  {
    id: 'progress',
    icon: '\uD83D\uDCCA',
    titleKey: 'slide:student:progress:title',
    textKey: 'slide:student:progress:text',
  },
]

export const INSTRUCTOR_SLIDES_FULL = [
  {
    id: 'welcome',
    icon: '\uD83D\uDC4B',
    titleKey: 'slide:instructor:welcome:title',
    textKey: 'slide:instructor:welcome:text',
  },
  {
    id: 'dashboard',
    icon: '\uD83D\uDCCB',
    titleKey: 'slide:instructor:dashboard:title',
    textKey: 'slide:instructor:dashboard:text',
  },
  {
    id: 'courses',
    icon: '\uD83D\uDCDA',
    titleKey: 'slide:instructor:courses:title',
    textKey: 'slide:instructor:courses:text',
  },
  {
    id: 'pools',
    icon: '\uD83D\uDDC2\uFE0F',
    titleKey: 'slide:instructor:pools:title',
    textKey: 'slide:instructor:pools:text',
  },
  {
    id: 'members',
    icon: '\uD83D\uDC65',
    titleKey: 'slide:instructor:members:title',
    textKey: 'slide:instructor:members:text',
  },
  {
    id: 'analytics',
    icon: '\uD83D\uDCCA',
    titleKey: 'slide:instructor:analytics:title',
    textKey: 'slide:instructor:analytics:text',
  },
  {
    id: 'exams',
    icon: '\uD83D\uDCDD',
    titleKey: 'slide:instructor:exams:title',
    textKey: 'slide:instructor:exams:text',
  },
  {
    id: 'tools',
    icon: '\uD83D\uDD27',
    titleKey: 'slide:instructor:tools:title',
    textKey: 'slide:instructor:tools:text',
  },
  {
    id: 'settings',
    icon: '\u2699\uFE0F',
    titleKey: 'slide:instructor:settings:title',
    textKey: 'slide:instructor:settings:text',
  },
]

/* ── Trimmed 3-slide arrays (for onboarding wizard tour) ── */

export const STUDENT_SLIDES = [
  {
    id: 'courses',
    icon: '\uD83D\uDCDA',
    titleKey: 'slide:student:courses:title',
    textKey: 'slide:student:courses:text',
  },
  {
    id: 'training',
    icon: '\uD83C\uDFAF',
    titleKey: 'slide:student:training:title',
    textKey: 'slide:student:training:text',
  },
  {
    id: 'progress',
    icon: '\uD83D\uDCCA',
    titleKey: 'slide:student:progress:title',
    textKey: 'slide:student:progress:text',
  },
]

export const INSTRUCTOR_SLIDES = [
  {
    id: 'dashboard',
    icon: '\uD83D\uDCCB',
    titleKey: 'slide:instructor:dashboard:title',
    textKey: 'slide:instructor:dashboard:text',
  },
  {
    id: 'pools',
    icon: '\uD83D\uDDC2\uFE0F',
    titleKey: 'slide:instructor:pools:title',
    textKey: 'slide:instructor:pools:text',
  },
  {
    id: 'analytics',
    icon: '\uD83D\uDCCA',
    titleKey: 'slide:instructor:analytics:title',
    textKey: 'slide:instructor:analytics:text',
  },
]

export const ADMIN_SLIDES = [
  {
    id: 'users',
    icon: '\uD83D\uDC65',
    titleKey: 'slide:admin:users:title',
    textKey: 'slide:admin:users:text',
  },
  {
    id: 'settings',
    icon: '\u2699\uFE0F',
    titleKey: 'slide:admin:settings:title',
    textKey: 'slide:admin:settings:text',
  },
  {
    id: 'analytics',
    icon: '\uD83D\uDCCA',
    titleKey: 'slide:admin:analytics:title',
    textKey: 'slide:admin:analytics:text',
  },
]

/* ── Tile data for OnbProfileTiles ────────────────────── */

export const GOAL_TILES = [
  { id: 'certification', icon: '\uD83C\uDFC6', labelKey: 'Zertifizierung' },
  { id: 'career', icon: '\uD83D\uDE80', labelKey: 'Karriere' },
  { id: 'hobby', icon: '\uD83D\uDCA1', labelKey: 'Hobby / Interesse' },
]

export const INTENSITY_TILES = [
  { id: 'casual', icon: '\uD83C\uDF31', labelKey: '1-2h / Woche', hours: 1.5 },
  { id: 'regular', icon: '\uD83D\uDCDA', labelKey: '3-5h / Woche', hours: 4 },
  { id: 'intensive', icon: '\uD83D\uDD25', labelKey: '6+ Stunden / Woche', hours: 7 },
]

export const AI_CONSENT_TILES = [
  { id: 'yes', icon: '\uD83E\uDD16', labelKey: 'KI-Erklaerungen aktivieren' },
  { id: 'no', icon: '\uD83D\uDD12', labelKey: 'Ohne KI lernen' },
]

/* ── Getters ──────────────────────────────────────────── */

/**
 * Returns the trimmed 3-slide array for a given role (used in onboarding wizard).
 * @param {'student'|'instructor'|'dozent'|'admin'} role
 * @returns {Array}
 */
export function getSlidesForRole(role) {
  if (role === 'admin') return ADMIN_SLIDES
  if (role === 'instructor' || role === 'dozent') return INSTRUCTOR_SLIDES
  return STUDENT_SLIDES
}

/**
 * Returns the full slide array for a given role (backward compat / extended tour).
 * @param {'student'|'instructor'|'dozent'|'admin'} role
 * @returns {Array}
 */
export function getSlidesForRoleFull(role) {
  if (role === 'admin') return ADMIN_SLIDES
  if (role === 'instructor' || role === 'dozent') return INSTRUCTOR_SLIDES_FULL
  return STUDENT_SLIDES_FULL
}
