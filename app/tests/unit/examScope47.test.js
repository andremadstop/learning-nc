/**
 * GitHub #47: instructors set the question count and time limit of a course exam per pool in the
 * pool rules, with a course default under Administration → Course rules → Certification. The
 * server enforces them; the exam card shows them exactly.
 *
 * Component definitions are exercised directly, every computed as a live getter.
 */
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url, params = {}) => Object.entries(params).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), url)),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebratePerfectSession: vi.fn() }))
vi.mock('../../src/countUp.js', () => ({ countUp: vi.fn() }))
vi.mock('../../src/stores/virtuProfStore.js', () => ({ useOptionalVirtuProfStore: () => null }))

const { stub } = vi.hoisted(() => ({ stub: (name) => ({ default: { name, template: '<div />' } }) }))
for (const name of ['NcButton', 'NcProgressBar', 'NcLoadingIcon', 'NcNoteCard', 'NcModal', 'NcCheckboxRadioSwitch', 'NcEmptyContent']) {
	vi.doMock(`@nextcloud/vue/components/${name}`, () => stub(name))
}
vi.mock('../../src/components/Certificate.vue', () => stub('Certificate'))
vi.mock('../../src/components/BadgeUnlock.vue', () => stub('BadgeUnlock'))
vi.mock('../../src/components/PbqRenderer.vue', () => stub('PbqRenderer'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => stub('QuestionLanguageSwitcher'))
vi.mock('../../src/components/CourseKnowledgeImport.vue', () => stub('CourseKnowledgeImport'))
vi.mock('../../src/components/CourseMaterials.vue', () => stub('CourseMaterials'))
vi.mock('../../src/components/AuthFlowSimulator.vue', () => stub('AuthFlowSimulator'))
vi.mock('../../src/components/DnsResolver.vue', () => stub('DnsResolver'))
vi.mock('../../src/components/FirewallBuilder.vue', () => stub('FirewallBuilder'))
vi.mock('../../src/components/KnowledgeModeration.vue', () => stub('KnowledgeModeration'))
vi.mock('../../src/components/LeitnerMode.vue', () => stub('LeitnerMode'))
vi.mock('../../src/components/NatTable.vue', () => stub('NatTable'))
vi.mock('../../src/components/PortScanner.vue', () => stub('PortScanner'))
vi.mock('../../src/components/RoutingTable.vue', () => stub('RoutingTable'))
vi.mock('../../src/components/SubnetCalculator.vue', () => stub('SubnetCalculator'))
vi.mock('../../src/components/TrainingMode.vue', () => stub('TrainingMode'))
vi.mock('../../src/components/WiresharkLite.vue', () => stub('WiresharkLite'))

globalThis.t = (app, text, vars = {}) => Object.entries(vars).reduce((acc, [k, v]) => acc.replace(`{${k}}`, String(v)), text)

const axios = (await import('@nextcloud/axios')).default
const ExamMode = (await import('../../src/components/ExamMode.vue')).default
const CourseTabLernraum = (await import('../../src/components/CourseTabLernraum.vue')).default
const CourseTabVerwaltung = (await import('../../src/components/CourseTabVerwaltung.vue')).default

function instanceOf(component, props = {}) {
	const instance = { $emit: vi.fn(), $refs: {}, $nextTick: (fn) => fn && fn(), ...props }
	Object.assign(instance, component.data.call(instance), props)
	for (const [name, def] of Object.entries(component.computed || {})) {
		const getter = typeof def === 'function' ? def : def.get
		Object.defineProperty(instance, name, { get: () => getter.call(instance), configurable: true })
	}
	for (const [name, fn] of Object.entries(component.methods || {})) {
		instance[name] = fn.bind(instance)
	}
	return instance
}

const examProps = { poolId: 42, courseId: 7, practice: false, practiceConfig: null, totalQuestions: 30, contentLanguage: '', courseCertification: null, poolPractice: null, examScope: null }

describe('ExamMode exam card (#47)', () => {
	it('names the configured count and minutes exactly', () => {
		const vm = instanceOf(ExamMode, { ...examProps, examScope: { questions: 12, minutes: 25 }, courseCertification: { passPercent: 80 } })
		expect(vm.examPresets[0].meta).toBe('Questions: 12 · time limit: 25 min · pass mark: 80%')
		expect(vm.examPresets[0].questionCount).toBe(12)
		expect(vm.examPresets[0].timeLimitSeconds).toBe(1500)
	})

	it('never promises more questions than the pool has', () => {
		const vm = instanceOf(ExamMode, { ...examProps, examScope: { questions: 50, minutes: null } })
		expect(vm.examQuestionCount).toBe(30)
		expect(vm.examPresets[0].meta).toBe('Fragen: 30 · Zeitlimit: 45 Min.')
		expect(vm.examPresets[0].meta).not.toContain('900')
	})

	it('keeps the pool-sized card when nothing is configured', () => {
		const vm = instanceOf(ExamMode, { ...examProps, courseCertification: { passPercent: 80 } })
		expect(vm.examPresets[0].meta).toBe('Questions: up to 30 · time limit: 45 min · pass mark: 80%')
	})

	it('ignores a scope outside a course, where the server does not apply it', () => {
		const vm = instanceOf(ExamMode, { ...examProps, courseId: null, examScope: { questions: 5, minutes: 5 } })
		expect(vm.examQuestionCount).toBe(30)
		expect(vm.examTimeLimitSeconds).toBe(2700)
	})
})

describe('Pool rules dialog (#47)', () => {
	beforeEach(() => {
		axios.put.mockReset()
		axios.put.mockResolvedValue({ data: {} })
	})

	function dialog(pool, course = { exam_questions: 40, exam_minutes: null }) {
		const vm = instanceOf(CourseTabLernraum, { courseId: 7, course, userRole: 'instructor', actsAsInstructor: true, coursePools: [pool], allPools: [] })
		vm.openPoolRulesModal(pool)
		return vm
	}

	const pool = { pool_id: 42, pool_name: 'Pool', required: 1, question_count: 30, total_question_count: 30, exam_questions: 15, exam_minutes: 20 }

	it('loads the saved values and sends both keys', async () => {
		const vm = dialog(pool)
		expect(vm.poolRulesForm.examQuestions).toBe('15')
		vm.poolRulesForm.examMinutes = 35
		await vm.savePoolRules()
		const [url, body] = axios.put.mock.calls[0]
		expect(url).toBe('/apps/learning/api/courses/7/pools/42')
		expect(body).toMatchObject({ examQuestions: 15, examMinutes: 35 })
	})

	it('sends null for empty fields, so the course default applies', async () => {
		const vm = dialog({ ...pool, exam_questions: null, exam_minutes: null })
		expect(vm.examQuestionsPlaceholder).toBe('Kurs-Vorgabe: 40')
		expect(vm.examMinutesPlaceholder).toBe('Nach Fragenzahl')
		await vm.savePoolRules()
		const body = axios.put.mock.calls[0][1]
		expect(body).toHaveProperty('examQuestions', null)
		expect(body).toHaveProperty('examMinutes', null)
	})

	it('refuses a count above the pool size without a request', async () => {
		const vm = dialog(pool)
		vm.poolRulesForm.examQuestions = 31
		await vm.savePoolRules()
		expect(axios.put).not.toHaveBeenCalled()
		expect(vm.poolRulesError).toBe('Fragen pro Prüfung: eine ganze Zahl von 1 bis 30.')
	})

	it('refuses minutes outside 1–600 without a request', async () => {
		const vm = dialog(pool)
		vm.poolRulesForm.examMinutes = 0
		await vm.savePoolRules()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('passes the server-computed scope of the selected pool to the exam card', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/components/CourseTabLernraum.vue'), 'utf8')
		expect(source).toContain(':examScope="selectedLearningPool.exam_scope || null"')
	})
})

describe('Course default in Administration (#47)', () => {
	beforeEach(() => {
		axios.patch.mockReset()
	})

	it('sends the default and 0 for an empty field, and reads the saved values back', async () => {
		axios.patch.mockResolvedValue({ data: { certEnabled: false, certPassPercent: 80, certRequiredPoolIds: [], certValidityDays: 0, examQuestions: 25, examMinutes: null } })
		const vm = instanceOf(CourseTabVerwaltung, { courseId: 7, course: null, userRole: 'instructor', activeTab: 'mode-config' })
		vm.examScopeQuestions = 25
		vm.examScopeMinutes = ''
		await vm.saveCertConfig()
		const [url, body] = axios.patch.mock.calls[0]
		expect(url).toBe('/apps/learning/api/courses/7/cert-config')
		expect(body).toMatchObject({ examQuestions: 25, examMinutes: 0 })
		expect(vm.examScopeQuestions).toBe('25')
		expect(vm.examScopeMinutes).toBe('')
	})

	it('refuses an invalid default without a request', async () => {
		const vm = instanceOf(CourseTabVerwaltung, { courseId: 7, course: null, userRole: 'instructor', activeTab: 'mode-config' })
		vm.examScopeMinutes = 601
		await vm.saveCertConfig()
		expect(axios.patch).not.toHaveBeenCalled()
		expect(vm.$emit).toHaveBeenCalledWith('error', 'Zeitlimit: eine ganze Zahl von 1 bis 600 Minuten.')
	})

	it('renders the default outside the certification switch', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/components/CourseTabVerwaltung.vue'), 'utf8')
		const certBlock = source.slice(source.indexOf('<template v-if="certEnabled">'), source.indexOf('data-test="exam-scope-default"'))
		expect(certBlock).toContain('</template>')
	})
})
