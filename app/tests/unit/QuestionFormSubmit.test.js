/**
 * QuestionForm submit behaviour -- the defect reported in Codeberg #7.
 *
 * Reported: clicking "Bild hochladen" saved the question before a file was picked.
 * Cause: the button carried the @nextcloud/vue 8 spelling `type="secondary"`. In v9 `type`
 * is the NATIVE button type, and HTML's invalid-value-default turns an unknown type into
 * `submit` -- so the button submitted the surrounding <form>.
 *
 * These tests mount the real component with the real NcButton and click the real buttons,
 * asserting on submit events at the <form>. That is the only level at which this class of
 * bug is visible: it is valid Vue, valid JS and valid HTML, and all four project gates
 * stayed green for 5.5 months while it was live.
 *
 * (happy-dom implements implicit submission faithfully, invalid-value-default included --
 * verified before this file was written.)
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createApp } from 'vue'

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

let host, app, submits, submitSpy

/** Mount QuestionForm and start counting submit events on its <form>. */
function mountForm(props = {}) {
	host = document.createElement('div')
	document.body.appendChild(host)
	app = createApp(QuestionForm, props)
	app.config.globalProperties.t = (_app, str) => str
	app.config.globalProperties.n = (_app, s) => s
	app.config.warnHandler = () => {}
	app.mount(host)

	submits = []
	// Listen on the document in the capture phase: submit bubbles, so this counts the
	// event no matter which form node Vue re-created, and runs before @submit.prevent.
	submitSpy = (e) => { submits.push(e); e.preventDefault() }
	document.addEventListener('submit', submitSpy, true)
	return host.querySelector('form')
}

/** Find a rendered button by its visible label. */
function buttonByText(label) {
	const match = [...host.querySelectorAll('button')].find((b) => b.textContent.trim() === label)
	if (!match) {
		const seen = [...host.querySelectorAll('button')].map((b) => b.textContent.trim())
		throw new Error(`no button labelled "${label}"; found: ${JSON.stringify(seen)}`)
	}
	return match
}

/** Fill every required field, as a user would before saving. */
function fillRequiredFields() {
	for (const el of host.querySelectorAll('input[required], textarea[required]')) {
		el.value = el.tagName === 'TEXTAREA' ? 'Was ist 2 + 2?' : 'Antwort'
		el.dispatchEvent(new Event('input', { bubbles: true }))
	}
}

beforeEach(() => { document.body.innerHTML = '' })
afterEach(() => {
	if (submitSpy) document.removeEventListener('submit', submitSpy, true)
	app?.unmount()
})

describe('QuestionForm: only the save button submits', () => {
	it('renders the form and its buttons', () => {
		const form = mountForm()
		expect(form).toBeTruthy()
		expect(buttonByText('Bild hochladen')).toBeTruthy()
	})

	it('"Bild hochladen" opens the file picker WITHOUT submitting (the reported bug)', () => {
		mountForm()
		// The report says "open an existing question for editing" -- i.e. a form whose
		// required fields are already filled. That detail matters: while they are empty,
		// constraint validation blocks submission on its own and hides the defect.
		fillRequiredFields()
		const upload = buttonByText('Bild hochladen')
		const picker = host.querySelector('input[type="file"]')
		const pickerClicked = vi.fn()
		picker.addEventListener('click', pickerClicked)

		upload.click()

		expect(submits).toHaveLength(0)      // regression: was 1
		expect(pickerClicked).toHaveBeenCalled()
		expect(upload.getAttribute('type')).toBe('button')
	})

	it('"+ Antwort hinzufügen" does not submit', () => {
		mountForm()
		fillRequiredFields()
		buttonByText('+ Antwort hinzufügen').click()
		expect(submits).toHaveLength(0)
	})

	it('"Abbrechen" discards instead of saving (was submitting too, unreported)', () => {
		mountForm()
		fillRequiredFields()
		buttonByText('Abbrechen').click()
		expect(submits).toHaveLength(0)
	})

	it('an empty required field blocks submission (HTML constraint validation)', () => {
		// Documents why the test below fills the form first: the question text and all four
		// answer inputs are `required`, so a click on save never reaches submit while they
		// are empty. happy-dom implements constraint validation, so this is not a shortcut.
		mountForm()
		buttonByText('Speichern').click()
		expect(submits).toHaveLength(0)
	})

	it('"Speichern" still submits -- it used to do so only by accident', () => {
		// Before the fix this button read type="primary" native-type="submit": v9 ignores
		// native-type, so it submitted purely because "primary" is an invalid native type.
		// A naive type->variant rename would have left it at the default "button" and
		// silently broken saving -- which is exactly what this asserts against.
		mountForm()
		fillRequiredFields()

		const save = buttonByText('Speichern')
		expect(save.getAttribute('type')).toBe('submit')

		save.click()

		expect(submits).toHaveLength(1)
	})
})
