/**
 * Fresh-eyes C4: the view-only notice of a shared pool ran its two sentences together
 * ("This pool was shared with you (view only) To change questions, ask …"), because the first
 * string has no full stop and both sat in one text node. They are two paragraphs now.
 * App.vue is too large to mount here; the template is checked as source.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

describe('App — view-only notice of a shared pool (fresh-eyes C4)', () => {
	it('puts the notice and the edit hint in separate paragraphs', () => {
		const source = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
		const start = source.indexOf("<NcNoteCard v-if=\"poolPermission === 'read'\"")
		expect(start).toBeGreaterThan(-1)
		const card = source.slice(start, source.indexOf('</NcNoteCard>', start))
		expect(card).toMatch(/<p class="pool-readonly-note">\{\{ t\('learning', 'Dieser Pool wurde mit dir geteilt \(nur Ansicht\)'\) \}\}<\/p>/)
		expect(card).toMatch(/<p v-if="userRole === 'instructor' && !studentViewActive" class="pool-readonly-note">\s*\{\{ t\('learning', 'Um Fragen zu ändern/)
	})
})
