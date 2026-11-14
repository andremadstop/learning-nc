/**
 * Audit 2026-11, package p03-campaign: the Abenteuer campaign list, start, choices and skill
 * checks, plus the campaign intro and the routing exercise.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, str) => str,
	t: (_app, str) => str,
	getLanguage: () => 'de',
	isRTL: () => false,
}))

const { stub } = vi.hoisted(() => ({
	stub: (name) => ({ default: { name, template: '<div><slot /></div>' } }),
}))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => stub('NcEmptyContent'))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => stub('NcNoteCard'))

globalThis.t = (_app, str) => str

import axios from '@nextcloud/axios'
import AbenteuerMode from '../../src/components/AbenteuerMode.vue'
import CampaignIntro from '../../src/components/CampaignIntro.vue'
import RoutingTable from '../../src/components/RoutingTable.vue'

const methods = AbenteuerMode.methods

function bind(vm, names) {
	for (const name of names) {
		vm[name] = methods[name].bind(vm)
	}
	return vm
}

function mount(component, props) {
	const el = document.createElement('div')
	document.body.appendChild(el)
	const app = createApp(component, props)
	app.config.globalProperties.t = (_app, str) => str
	const vm = app.mount(el)
	return { vm, el, unmount: () => { app.unmount(); el.remove() } }
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('campaign list progress (audit #88)', () => {
	it('marks new, started and finished graph campaigns from the progress rows', () => {
		const campaigns = [
			{ id: 'a', is_graph: true },
			{ id: 'b', is_graph: true },
			{ id: 'c', is_graph: true },
		]
		const rows = [
			{ campaign_id: 'b', status: 'in_progress', is_graph: true },
			{ campaign_id: 'c', status: 'completed', is_graph: true },
		]
		const result = methods.applyCampaignProgress(campaigns, rows)
		expect(result.map(c => c.progress)).toEqual(['not_started', 'in_progress', 'completed'])
	})

	it('ignores a linear story row that shares the ID of a graph campaign', () => {
		const result = methods.applyCampaignProgress(
			[{ id: 'phishing_friday', is_graph: true }],
			[{ campaign_id: 'phishing_friday', status: 'completed', is_graph: false }],
		)
		expect(result[0].progress).toBe('not_started')
	})

	it('fetchCampaigns loads the progress next to the list', async () => {
		axios.get.mockImplementation((url) => Promise.resolve(url.endsWith('/progress')
			? { data: [{ campaign_id: 'g1', status: 'completed', is_graph: true }] }
			: { data: [{ campaign_id: 'g1', title: 'G', graph_mode: true }] }))
		const vm = bind({ ...AbenteuerMode.data.call({}) }, ['fetchCampaignProgress', 'applyCampaignProgress', 'normalizeCampaignList', 'fetchCampaigns'])
		await vm.fetchCampaigns()
		expect(vm.campaigns).toHaveLength(1)
		expect(vm.campaigns[0].progress).toBe('completed')
		// Campaigns are not course-bound: no courseId on the list call (audit #143)
		const listCall = axios.get.mock.calls.find(([u]) => u.endsWith('/story/campaigns'))
		expect(listCall[1]).toBeUndefined()
	})
})

describe('graph start that fails (audit #32)', () => {
	it('stays on the character selection with an error instead of starting the linear story', async () => {
		axios.post.mockRejectedValue({ response: { status: 400 } })
		const vm = {
			...AbenteuerMode.data.call({}),
			selectedCampaign: { id: 'phishing_friday', is_graph: true },
			selectedCharacter: { id: 'security' },
			beginScene: vi.fn(),
			resetGraphUiState: vi.fn(),
			applyGraphResponse: vi.fn(),
		}
		await methods.startCampaign.call(vm)
		expect(vm.beginScene).not.toHaveBeenCalled()
		expect(vm.phase).toBe('character-select')
		expect(vm.storyError).not.toBe('')
		// Only the character class is sent: the server reads no courseId (audit #143)
		expect(axios.post.mock.calls[0][1]).toEqual({ characterClass: 'security' })
	})
})

describe('a choice the server refuses (audit #28, #175)', () => {
	function makeVm() {
		return {
			...AbenteuerMode.data.call({}),
			phase: 'scene',
			selectedCampaign: { id: 'c1', is_graph: false },
			selectedCharacter: { id: 'security' },
			currentScene: { id: 's1', choices: [] },
			showEpilog: vi.fn(),
			beginScene: vi.fn(),
			startTypewriter: vi.fn(),
			scheduleSimulation: vi.fn(),
			showFreetextNarrative: vi.fn(),
			failChoice: methods.failChoice,
		}
	}

	it('keeps the scene and lets the player choose again instead of showing the epilogue', async () => {
		axios.post.mockRejectedValue({ response: { status: 404 } })
		const vm = makeVm()
		vm.submitDynamicChoice = methods.submitDynamicChoice.bind(vm)
		await methods.makeChoice.call(vm, { id: 'c_static', text: 'Static' })
		expect(vm.showEpilog).not.toHaveBeenCalled()
		expect(vm.choiceMade).toBe(false)
		expect(vm.storyError).not.toBe('')
		expect(vm.sessionStats.scenesCompleted).toBe(0)
	})

	it("sends a 'KI' choice through the free-text route, not /choice", async () => {
		axios.post.mockResolvedValue({ data: { valid: true, next_scene: 's2', narrative: 'Weiter' } })
		const vm = makeVm()
		vm.submitDynamicChoice = methods.submitDynamicChoice.bind(vm)
		await methods.makeChoice.call(vm, { id: 'dyn_0', text: 'Ich isoliere den Host', dynamic: true })
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/learning/api/story/campaigns/c1/freetext')
		expect(body).toEqual({ text: 'Ich isoliere den Host' })
		expect(vm.showFreetextNarrative).toHaveBeenCalledWith('Weiter')
		expect(vm.showEpilog).not.toHaveBeenCalled()
	})

	it("shows the consent notice when the server refuses a 'KI' choice for missing consent", async () => {
		axios.post.mockRejectedValue({ response: { status: 403, data: { consent_required: true } } })
		const vm = makeVm()
		vm.submitDynamicChoice = methods.submitDynamicChoice.bind(vm)
		await methods.makeChoice.call(vm, { id: 'dyn_0', text: 'x', dynamic: true })
		expect(vm.choiceMade).toBe(false)
		expect(vm.aiConsentRequired).toBe(true)
		expect(vm.storyError).toBe('')
	})
})

describe('skill check verdict (audit #90)', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('does not judge an answer the API sent without is_correct', () => {
		vi.useFakeTimers()
		const vm = {
			...AbenteuerMode.data.call({}),
			currentSkillQuestion: { id: 5, answers: [{ id: 50, text: 'A' }] },
			skillCheckTotal: 1,
			submitSkillBatch: vi.fn(),
			fetchNextSkillQuestion: vi.fn(),
		}
		methods.submitSkillAnswer.call(vm, { id: 50, text: 'A' })
		expect(vm.skillResultShown).toBe(false)
		expect(vm.skillCheckNpcState).not.toBe('alert')
		vi.advanceTimersByTime(1000)
		expect(vm.submitSkillBatch).toHaveBeenCalled()
	})

	it('takes right or wrong from the batch verdict', async () => {
		vi.useFakeTimers()
		axios.post.mockResolvedValue({
			data: {
				scene: { id: 's2', narrative: 'n' },
				skill_result: { correct: true, correct_count: 2 },
			},
		})
		const vm = {
			...AbenteuerMode.data.call({}),
			selectedCampaign: { id: 'c1' },
			currentSkillCheck: { id: 'c_skill' },
			skillAnswersBatch: [{ question_id: 1, answer_id: 2 }],
			startTypewriter: vi.fn(),
			scheduleSimulation: vi.fn(),
			showEpilog: vi.fn(),
		}
		bind(vm, ['showSkillVerdict', 'openSceneAfterSkillCheck', 'failSkillBatch'])
		await methods.submitSkillBatch.call(vm)
		expect(vm.lastAnswerCorrect).toBe(true)
		expect(vm.skillResultShown).toBe(true)
		expect(vm.sessionStats.skillChecksPassed).toBe(2)
		vi.advanceTimersByTime(2000)
		expect(vm.currentScene.id).toBe('s2')
		expect(vm.skillResultShown).toBe(false)
	})

	it('keeps the scene when the batch fails instead of showing the epilogue (audit #175)', async () => {
		axios.post.mockRejectedValue(new Error('network'))
		const vm = {
			...AbenteuerMode.data.call({}),
			selectedCampaign: { id: 'c1' },
			currentSkillCheck: { id: 'c_skill' },
			skillAnswersBatch: [{ question_id: 1, answer_id: 2 }],
			showEpilog: vi.fn(),
		}
		bind(vm, ['showSkillVerdict', 'openSceneAfterSkillCheck', 'failSkillBatch'])
		await methods.submitSkillBatch.call(vm)
		expect(vm.showEpilog).not.toHaveBeenCalled()
		expect(vm.phase).toBe('scene')
		expect(vm.storyError).not.toBe('')
	})
})

describe('scene reload (audit #175)', () => {
	it('asks GET /scene without the keys the server ignores', async () => {
		axios.get.mockResolvedValue({ data: { scene: { id: 's1', narrative: 'n' } } })
		const vm = {
			...AbenteuerMode.data.call({}),
			selectedCampaign: { id: 'c1' },
			selectedCharacter: { id: 'security' },
			clearTimers: vi.fn(),
			startTypewriter: vi.fn(),
			scheduleSimulation: vi.fn(),
			showEpilog: vi.fn(),
		}
		await methods.beginScene.call(vm)
		expect(axios.get.mock.calls[0]).toEqual(['/apps/learning/api/story/campaigns/c1/scene'])
	})
})

describe('CampaignIntro difficulty badge (audit #176)', () => {
	it.each([
		['intermediate', 'Fortgeschritten'],
		['advanced', 'Experte'],
		['beginner', 'Einsteiger'],
	])('%s reads %s, as on the campaign card', async (difficulty, label) => {
		const { el, unmount } = mount(CampaignIntro, { campaignId: 'x', title: 'X', difficulty })
		await nextTick()
		const badge = el.querySelector('.campaign-intro__badge')
		expect(badge.textContent.trim()).toBe(label)
		expect(badge.textContent).not.toContain(difficulty)
		expect(methods.difficultyLabel(difficulty)).toBe(label)
		unmount()
	})
})

describe('RoutingTable exercise (audit #39)', () => {
	const scenario = {
		id: 'r1',
		title: 'Route',
		question: 'Welche Route gewinnt?',
		destinationIp: '10.0.2.55',
		answer: 'b',
		options: [{ id: 'a', label: 'Default' }, { id: 'b', label: '10.0.2.0/24' }],
		routes: [
			{ destination: '0.0.0.0', mask: '0.0.0.0', gateway: '10.0.0.1', interface: 'Gi0/0', metric: 1, type: 'static' },
			{ destination: '10.0.2.0', mask: '255.255.255.0', gateway: '10.0.0.2', interface: 'Gi0/1', metric: 1, type: 'static' },
		],
	}

	it('highlights the winning route only after the answer', async () => {
		const { vm, el, unmount } = mount(RoutingTable, { mode: 'embedded', scenario })
		await nextTick()
		expect(el.querySelectorAll('.sim-tool__match')).toHaveLength(2)
		expect(el.querySelector('.sim-tool__match--winner')).toBeNull()
		expect(el.querySelector('.sim-tool__match--active')).toBeNull()
		vm.answerScenario('a')
		await nextTick()
		expect(el.querySelector('.sim-tool__match--winner')).not.toBeNull()
		unmount()
	})
})
