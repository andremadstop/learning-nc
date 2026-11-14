import { describe, it, expect, vi, afterEach } from 'vitest'
import { createApp, nextTick } from 'vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))

// The dialog shell only has to expose the slot so the <form> reaches the document.
vi.mock('../../src/components/AccessibleDialog.vue', () => ({
	default: { name: 'AccessibleDialog', template: '<div><slot /></div>' },
}))
vi.mock('../../src/components/PbqAuthorTool.vue', () => ({
	default: { name: 'PbqAuthorTool', template: '<div />' },
}))

import QuestionForm from '../../src/components/QuestionForm.vue'

/**
 * Audit #38: the form saved a new PBQ as 'single', demanded two answer texts for an existing PBQ
 * (and stored them as dummy answers), and "Keine (Standardfrage)" could not clear the subtype.
 */
let host, app, vm, saves

function mountForm(question = null) {
	host = document.createElement('div')
	document.body.appendChild(host)
	saves = []
	app = createApp(QuestionForm, { question, onSave: (payload) => saves.push(payload) })
	app.config.globalProperties.t = (_app, str) => str
	app.config.globalProperties.n = (_app, s) => s
	app.config.warnHandler = () => {}
	vm = app.mount(host)
}

afterEach(() => {
	app?.unmount()
	host?.remove()
})

const storedPbq = {
	id: 9, text: 'Configure the VLAN', question_type: 'pbq', pbq_subtype: 'dropdown',
	pbq_config: { questions: [{ id: 'q1', options: ['a', 'b'], correct: 'a' }] }, answers: [],
}

describe('QuestionForm and PBQs (audit #38)', () => {
	it('an existing PBQ shows no answer fields and saves as a PBQ without answers', async () => {
		mountForm(storedPbq)
		await nextTick()

		expect(host.querySelector('.answers-form')).toBeNull()
		expect(host.querySelector('#question-type').disabled).toBe(true)
		vm.save()
		expect(saves).toHaveLength(1)
		expect(saves[0]).toMatchObject({ questionType: 'pbq', answers: [], pbqSubtype: 'dropdown' })
	})

	it('choosing a PBQ type on a new question makes it a PBQ', async () => {
		mountForm()
		await nextTick()
		vm.form.text = 'New PBQ'
		vm.form.pbqSubtype = 'cli'
		vm.onPbqSubtypeChange()
		vm.form.pbqConfig = '{"terminals":[]}'
		await nextTick()

		expect(host.querySelector('.answers-form')).toBeNull()
		vm.save()
		expect(saves[0]).toMatchObject({ questionType: 'pbq', answers: [], pbqSubtype: 'cli' })
	})

	it('"Keine (Standardfrage)" turns the PBQ back into a choice question and clears the subtype', async () => {
		mountForm(storedPbq)
		await nextTick()
		vm.form.pbqSubtype = ''
		vm.onPbqSubtypeChange()
		await nextTick()

		expect(vm.form.questionType).toBe('single')
		expect(host.querySelector('.answers-form')).not.toBeNull()
		vm.form.answers[0].text = 'Yes'
		vm.form.answers[1].text = 'No'
		vm.save()
		expect(saves[0]).toMatchObject({ questionType: 'single', pbqSubtype: '', pbqConfig: null })
		expect(saves[0].answers).toHaveLength(2)
	})

	it('the builder applying a PBQ also sets the type', async () => {
		mountForm()
		await nextTick()
		vm.applyPbqBuilderConfig({ subtype: 'ranking', json: '{"items":[]}' })

		expect(vm.form.questionType).toBe('pbq')
	})
})
