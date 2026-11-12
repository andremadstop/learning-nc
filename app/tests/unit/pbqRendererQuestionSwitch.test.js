/**
 * Audit 2026-11 (S09): ExamMode renders one PbqRenderer for every PBQ question and only swaps the
 * `question` prop, so Vue reuses the instance. PbqRenderer copied `initialValue` into localAnswer
 * once in data() and its subtype children kept their own state, so a second PBQ opened pre-filled
 * with the first one's answers (and submitted them), and jumping back showed the wrong answers.
 *
 * Mounts the real PbqRenderer + PbqDropdown and swaps the question the way ExamMode does.
 */
import { describe, it, expect, vi, afterEach } from 'vitest'
import { createApp, h, nextTick, reactive } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		emits: ['click'],
		props: { disabled: Boolean },
		render() { return h('button', { class: 'nc-btn', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) },
	},
}))

globalThis.t = (app, text) => text

import PbqRenderer from '../../src/components/PbqRenderer.vue'

function dropdownQuestion(id) {
	return {
		id,
		question_type: 'pbq',
		pbq_subtype: 'dropdown',
		pbq_config: { questions: [{ id: 'q1', label: 'Port ' + id, options: ['22', '443'] }] },
	}
}

let app = null
let host = null

afterEach(() => {
	app?.unmount()
	host?.remove()
	app = null
	host = null
})

function mountLikeExamMode(questions) {
	const state = reactive({ index: 0, pbqAnswers: {}, submitted: [] })
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp({
		render() {
			const q = questions[state.index]
			return h(PbqRenderer, {
				question: q,
				initialValue: state.pbqAnswers[q.id] || null,
				onChange: (ans) => { state.pbqAnswers[q.id] = ans },
				onSubmit: (ans) => { state.submitted.push({ id: q.id, ans }) },
			})
		},
	})
	app.config.globalProperties.t = globalThis.t
	app.mount(host)
	return state
}

const select = () => host.querySelector('select.pbq-dd-select')
const progress = () => host.querySelector('.pbq-progress').textContent.trim()

describe('PbqRenderer reused for the next PBQ question', () => {
	it('starts the next PBQ empty and submits only its own answers', async () => {
		const state = mountLikeExamMode([dropdownQuestion(1), dropdownQuestion(2)])
		select().value = '443'
		select().dispatchEvent(new Event('change'))
		await nextTick()
		expect(state.pbqAnswers[1]).toEqual({ q1: '443' })
		expect(progress()).toBe('1 / 1 beantwortet')

		state.index = 1
		await nextTick()
		expect(progress()).toBe('0 / 1 beantwortet')
		expect(select().value).toBe('')

		host.querySelector('.nc-btn').click()
		await nextTick()
		expect(state.submitted).toEqual([{ id: 2, ans: {} }])
	})

	it('shows the saved answers again when navigating back to an earlier PBQ', async () => {
		const state = mountLikeExamMode([dropdownQuestion(1), dropdownQuestion(2)])
		select().value = '22'
		select().dispatchEvent(new Event('change'))
		await nextTick()

		state.index = 1
		await nextTick()
		select().value = '443'
		select().dispatchEvent(new Event('change'))
		await nextTick()
		expect(state.pbqAnswers[2]).toEqual({ q1: '443' })

		state.index = 0
		await nextTick()
		expect(select().value).toBe('22')
		expect(state.pbqAnswers[1]).toEqual({ q1: '22' })
	})
})
