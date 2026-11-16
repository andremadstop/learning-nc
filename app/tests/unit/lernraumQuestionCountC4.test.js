/**
 * Fresh-eyes C4: the learning space listed a pool with one question as "1 questions".
 * Both pool lists use questionCountLabel(), which says "1 Frage" for one question.
 */
import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: { name: 'NcEmptyContent', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcModal', () => ({ default: { name: 'NcModal', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div />' } }),
}))

vi.mock('../../src/components/CourseKnowledgeImport.vue', () => stub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => stub('CourseMaterials'))
vi.mock('../../src/components/AuthFlowSimulator.vue', () => stub('AuthFlowSimulator'))
vi.mock('../../src/components/DnsResolver.vue', () => stub('DnsResolver'))
vi.mock('../../src/components/ExamMode.vue', () => stub('ExamMode'))
vi.mock('../../src/components/FirewallBuilder.vue', () => stub('FirewallBuilder'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => stub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/NatTable.vue', () => stub('NatTable'))
vi.mock('../../src/components/PortScanner.vue', () => stub('PortScanner'))
vi.mock('../../src/components/RoutingTable.vue', () => stub('RoutingTable'))
vi.mock('../../src/components/SubnetCalculator.vue', () => stub('SubnetCalculator'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))
vi.mock('../../src/components/WiresharkLite.vue', () => stub('WiresharkLite'))


import CourseTabLernraum from '../../src/components/CourseTabLernraum.vue'

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

describe('CourseTabLernraum — question count (fresh-eyes C4)', () => {
	it('says "1 Frage" for one question and keeps the plural otherwise', () => {
		const label = CourseTabLernraum.methods.questionCountLabel
		expect(label(1)).toBe('1 Frage')
		expect(label(0)).toBe('0 questions')
		expect(label(8)).toBe('8 questions')
	})

	it('no pool list prints the plural string directly', () => {
		const source = readFileSync(resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		const template = source.slice(0, source.indexOf('<script'))
		expect(template).not.toContain("'{n} questions'")
		expect(template.match(/questionCountLabel\(/g)?.length).toBe(2)
	})
})
