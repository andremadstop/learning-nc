/**
 * GitHub #39: a learner who opens a course-only pool in the pool window (e.g. from the Skill Map)
 * and starts a mode the course switched off gets the server's 403 with gate "mode". Training,
 * Leitner and the exam showed its English error text; they now show a translated sentence.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', async () => (await import('./support/l10nCatalog.js')).l10nMock())

import { setTestLanguage } from './support/l10nCatalog.js'
import { modeClosedMessage } from '../../src/utils/modeGate.js'
import fs from 'node:fs'
import path from 'node:path'

const refusal = { response: { status: 403, data: { error: 'This learning mode is switched off for your course', gate: 'mode' } } }

afterEach(() => setTestLanguage('de'))

describe('modeClosedMessage (GitHub #39)', () => {
	it('turns the refusal into a sentence in the reader language', () => {
		setTestLanguage('fr')
		expect(modeClosedMessage(refusal)).toBe('Ce mode d’apprentissage est désactivé dans ton cours.')
		setTestLanguage('de')
		expect(modeClosedMessage(refusal)).toBe('Dieser Lernmodus ist in deinem Kurs ausgeschaltet.')
	})

	it('leaves every other error alone, the video gate included', () => {
		expect(modeClosedMessage({ response: { status: 403, data: { gate: 'video' } } })).toBeNull()
		expect(modeClosedMessage({ response: { status: 400, data: { error: 'x' } } })).toBeNull()
		expect(modeClosedMessage(new Error('network'))).toBeNull()
	})

	it('is used by the start paths of Training, Leitner and the exam', () => {
		for (const [file, count] of [['TrainingMode.vue', 1], ['LeitnerMode.vue', 2], ['ExamMode.vue', 1]]) {
			const src = fs.readFileSync(path.resolve(__dirname, '../../src/components', file), 'utf8')
			expect(src.match(/modeClosedMessage\(e(rror)?\)/g)?.length ?? 0, file).toBe(count)
		}
	})
})
