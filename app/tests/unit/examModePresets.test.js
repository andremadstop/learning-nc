import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const examModePath = path.resolve(__dirname, '../../src/components/ExamMode.vue')
const trainingServicePath = path.resolve(__dirname, '../../lib/Service/TrainingService.php')

const examModeSource = fs.readFileSync(examModePath, 'utf8')
const trainingServiceSource = fs.readFileSync(trainingServicePath, 'utf8')

function getExamPresetBlock() {
	const match = examModeSource.match(/examPresets:\s*\[([\s\S]*?)\],\s*examPassScore:/)
	return match ? match[1] : ''
}

describe('Exam mode presets smoke verification', () => {
	it('keeps exactly two exam presets in setup: full and light', () => {
		const presetBlock = getExamPresetBlock()
		const presetIds = [...presetBlock.matchAll(/id:\s*'([^']+)'/g)].map(match => match[1])

		expect(presetIds).toEqual(['full', 'light'])
		expect(presetBlock).toContain("title: t('learning', 'Full Exam')")
		expect(presetBlock).toContain("title: t('learning', 'Practice Exam')")
	})

	it('shows pass and fail wording in the result banner', () => {
		expect(examModeSource).toContain("passedExam ? t('learning', 'Passed') : t('learning', 'Not passed')")
		expect(examModeSource).toContain("{{ t('learning', 'Passing score: {score}/{max}', { score: examPassScore, max: examMaxScore }) }}")
	})

	it('uses deterministic answer shuffling when loading exam questions', () => {
		expect(trainingServiceSource).toContain('private function shuffleAnswersForSession')
		expect(trainingServiceSource).toContain('$answers = $this->shuffleAnswersForSession($answers, $sessionId, (int)$q->getId());')
	})
})
