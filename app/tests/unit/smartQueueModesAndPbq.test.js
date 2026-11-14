/**
 * SmartQueue (audit #40, #41).
 *
 * #40: a due PBQ card had no controls (no PbqRenderer, no skip), so the session hung on it and
 * every later due card was unreachable.
 * #41: the dashboard's maintenance widget opened the ordinary queue; mode 'maintenance' now loads
 * /api/maintenance/queue.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.hoisted(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/\{(\w+)\}/g, (_m, k) => (vars && k in vars ? vars[k] : `{${k}}`))
	globalThis.n = (_app, s, p, c) => (c === 1 ? s : p)
})

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: vi.fn((url) => url) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/confetti.js', () => ({ celebrateMastery: vi.fn() }))

const { plain } = vi.hoisted(() => ({
	plain: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" class="nc-btn" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => plain('NcNoteCard'))
vi.mock('@nextcloud/vue/components/NcProgressBar', () => plain('NcProgressBar'))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => plain('NcLoadingIcon'))
vi.mock('../../src/components/BadgeUnlock.vue', () => plain('BadgeUnlock'))
vi.mock('../../src/components/QuestionScenario.vue', () => plain('QuestionScenario'))
vi.mock('../../src/components/LevelUpOverlay.vue', () => plain('LevelUpOverlay'))
vi.mock('../../src/components/QuestionLanguageSwitcher.vue', () => plain('QuestionLanguageSwitcher'))
vi.mock('../../src/components/PbqRenderer.vue', () => ({ default: {
	name: 'PbqRenderer',
	props: ['question', 'disabled'],
	emits: ['submit', 'skip'],
	template: '<div class="pbq-stub"><button class="pbq-submit" @click="$emit(\'submit\', { q1: \'b\' })">s</button><button class="pbq-skip" @click="$emit(\'skip\')">k</button></div>',
} }))

import axios from '@nextcloud/axios'
import SmartQueue from '../../src/components/SmartQueue.vue'

let host, app

async function settle() {
	for (let i = 0; i < 4; i++) {
		await new Promise((r) => setTimeout(r, 0))
		await nextTick()
	}
}

async function mount(props, items) {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.get.mockResolvedValue({ data: items })
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(SmartQueue, props)
	app.config.globalProperties.t = globalThis.t
	app.config.globalProperties.n = globalThis.n
	const vm = app.mount(host)
	await settle()
	return vm
}

afterEach(() => {
	app?.unmount()
	host?.remove()
})

const pbqItem = { id: 7, question_id: 70, pool_id: 1, box: 1, text: 'Order the steps', question_type: 'pbq', pbq_subtype: 'dropdown', pbq_config: {}, answers: [] }
const mcItem = { id: 8, question_id: 80, pool_id: 1, box: 1, text: 'MC', question_type: 'single', answers: [{ id: 1, text: 'A' }] }

describe('SmartQueue modes', () => {
	it('maintenance mode loads the maintenance queue with the short limit', async () => {
		await mount({ mode: 'maintenance' }, [])
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/maintenance/queue', { params: { limit: 10 } })
	})

	it('the normal queue keeps its endpoint and limit', async () => {
		await mount({ mode: 'queue' }, [])
		expect(axios.get).toHaveBeenCalledWith('/apps/learning/api/leitner/queue', { params: { limit: 30 } })
	})
})

describe('SmartQueue PBQ cards', () => {
	it('renders a due PBQ and submits its answers to the Leitner endpoint', async () => {
		const vm = await mount({}, [pbqItem, mcItem])
		expect(host.querySelector('.pbq-stub')).not.toBeNull()

		axios.post.mockResolvedValue({ data: { correct: true, new_box: 2, pbq_points: 1, pbq_max_points: 1 } })
		host.querySelector('.pbq-submit').click()
		await settle()

		expect(axios.post).toHaveBeenCalledWith('/apps/learning/api/leitner/answer', { itemId: 7, pbqAnswers: { q1: 'b' } })
		expect(vm.answered).toBe(true)
		expect(host.textContent).toContain('1/1')
	})

	it('skipping a PBQ moves on to the next due card without answering', async () => {
		const vm = await mount({}, [pbqItem, mcItem])
		host.querySelector('.pbq-skip').click()
		await settle()

		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.currentIndex).toBe(1)
		expect(host.textContent).toContain('MC')
	})

	it('a card without answer options offers Skip instead of hanging', async () => {
		const vm = await mount({}, [{ ...mcItem, id: 9, answers: [] }, mcItem])
		const skip = [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Skip')
		expect(skip).toBeDefined()
		skip.click()
		await settle()
		expect(vm.currentIndex).toBe(1)
	})
})
