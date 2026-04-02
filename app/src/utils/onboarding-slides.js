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

export const STUDENT_SLIDES = [
  {
    id: 'welcome',
    icon: '👋',
    titleKey: 'slide:student:welcome:title',
    textKey: 'slide:student:welcome:text',
  },
  {
    id: 'courses',
    icon: '📚',
    titleKey: 'slide:student:courses:title',
    textKey: 'slide:student:courses:text',
  },
  {
    id: 'training',
    icon: '🎯',
    titleKey: 'slide:student:training:title',
    textKey: 'slide:student:training:text',
  },
  {
    id: 'leitner',
    icon: '📦',
    titleKey: 'slide:student:leitner:title',
    textKey: 'slide:student:leitner:text',
  },
  {
    id: 'fsrs',
    icon: '🧠',
    titleKey: 'slide:student:fsrs:title',
    textKey: 'slide:student:fsrs:text',
  },
  {
    id: 'arena',
    icon: '⚔️',
    titleKey: 'slide:student:arena:title',
    textKey: 'slide:student:arena:text',
  },
  {
    id: 'tools',
    icon: '🔧',
    titleKey: 'slide:student:tools:title',
    textKey: 'slide:student:tools:text',
  },
  {
    id: 'virtuprof',
    icon: '🤖',
    titleKey: 'slide:student:virtuprof:title',
    textKey: 'slide:student:virtuprof:text',
  },
  {
    id: 'progress',
    icon: '📊',
    titleKey: 'slide:student:progress:title',
    textKey: 'slide:student:progress:text',
  },
]

export const INSTRUCTOR_SLIDES = [
  {
    id: 'welcome',
    icon: '👋',
    titleKey: 'slide:instructor:welcome:title',
    textKey: 'slide:instructor:welcome:text',
  },
  {
    id: 'dashboard',
    icon: '📋',
    titleKey: 'slide:instructor:dashboard:title',
    textKey: 'slide:instructor:dashboard:text',
  },
  {
    id: 'courses',
    icon: '📚',
    titleKey: 'slide:instructor:courses:title',
    textKey: 'slide:instructor:courses:text',
  },
  {
    id: 'pools',
    icon: '🗂️',
    titleKey: 'slide:instructor:pools:title',
    textKey: 'slide:instructor:pools:text',
  },
  {
    id: 'members',
    icon: '👥',
    titleKey: 'slide:instructor:members:title',
    textKey: 'slide:instructor:members:text',
  },
  {
    id: 'analytics',
    icon: '📊',
    titleKey: 'slide:instructor:analytics:title',
    textKey: 'slide:instructor:analytics:text',
  },
  {
    id: 'exams',
    icon: '📝',
    titleKey: 'slide:instructor:exams:title',
    textKey: 'slide:instructor:exams:text',
  },
  {
    id: 'tools',
    icon: '🔧',
    titleKey: 'slide:instructor:tools:title',
    textKey: 'slide:instructor:tools:text',
  },
  {
    id: 'settings',
    icon: '⚙️',
    titleKey: 'slide:instructor:settings:title',
    textKey: 'slide:instructor:settings:text',
  },
]

/**
 * Returns the slides array for a given role.
 * @param {'student'|'instructor'} role
 * @returns {Array}
 */
export function getSlidesForRole(role) {
  return role === 'instructor' ? INSTRUCTOR_SLIDES : STUDENT_SLIDES
}
