/**
 * Native form elements must not be bound with Vue component props.
 *
 * Codeberg #7 (third symptom): the tool selectors bound
 *   <input type="checkbox" :model-value="...">
 * A native <input> has no `model-value` prop -- Vue renders it as an inert HTML
 * attribute, so the box never reflects stored state. Every checkbox rendered
 * unchecked, a click on an already-enabled tool changed nothing internally, and
 * saving wrote all eight back. Exactly the report: "selecting just one tool
 * produces a success message, but all tools are subsequently shown again."
 *
 * Same class as the NcButton defect this app shipped for 5.5 months: a prop name
 * the target does not understand is silently ignored. Nothing errors, nothing
 * warns, and the gates stay green -- so the guard has to be a scan.
 */
import { describe, it, expect } from 'vitest'
import { createApp, h } from 'vue'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const NATIVE_FORM_TAGS = ['input', 'select', 'textarea']

describe('binding mechanics on native elements', () => {
	function render(props) {
		const host = document.createElement('div')
		document.body.appendChild(host)
		createApp({ render: () => h('input', { type: 'checkbox', ...props }) }).mount(host)
		return host.querySelector('input')
	}

	it(':model-value does NOT check a native checkbox (the defect)', () => {
		const el = render({ 'model-value': true })
		expect(el.checked).toBe(false)
		// It lands as an inert attribute rather than erroring -- which is why nobody noticed.
		expect(el.getAttribute('model-value')).toBe('true')
	})

	it(':checked does check it (the fix)', () => {
		expect(render({ checked: true }).checked).toBe(true)
		expect(render({ checked: false }).checked).toBe(false)
	})
})

function vueFiles(dir, found = []) {
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) vueFiles(full, found)
		else if (entry.endsWith('.vue')) found.push(full)
	}
	return found
}

/** Opening tags of native form elements, quote-aware so a '>' in a value cannot truncate. */
function nativeFormTags(source) {
	const tags = []
	for (const name of NATIVE_FORM_TAGS) {
		let i = 0
		while ((i = source.indexOf(`<${name}`, i)) !== -1) {
			let k = i + name.length + 1
			if (/[\w-]/.test(source[k] || '')) { i = k; continue }
			let quote = null
			for (; k < source.length; k++) {
				const c = source[k]
				if (quote) { if (c === quote) quote = null }
				else if (c === '"' || c === "'") quote = c
				else if (c === '>') break
			}
			tags.push({ name, tag: source.slice(i, k + 1), index: i })
			i = k + 1
		}
	}
	return tags
}

const SRC = join(process.cwd(), 'src')
const FILES = vueFiles(SRC)

describe('no component props on native form elements across src/', () => {
	it('finds .vue files to scan', () => {
		expect(FILES.length).toBeGreaterThan(50)
	})

	it('never listens for @update:checked, which v9 components no longer emit', () => {
		// NcCheckboxRadioSwitch took the same v8->v9 route as NcButton: `:checked` still
		// renders, but the event is now update:modelValue. A listener on the old name is
		// never called, so the toggle looks alive and silently keeps the old value.
		const offenders = []
		for (const file of FILES) {
			const src = readFileSync(file, 'utf8')
			src.split('\n').forEach((line, i) => {
				if (/@update:checked|v-on:update:checked/.test(line)) {
					offenders.push(`${file.replace(SRC, 'src')}:${i + 1} — use @update:model-value`)
				}
			})
		}
		expect(offenders).toEqual([])
	})

	it('never binds model-value on a native input/select/textarea', () => {
		const offenders = []
		for (const file of FILES) {
			const src = readFileSync(file, 'utf8')
			for (const { name, tag, index } of nativeFormTags(src)) {
				if (/(?::|v-bind:)?model-value\s*=|:modelValue\s*=/.test(tag)) {
					const line = src.slice(0, index).split('\n').length
					offenders.push(`${file.replace(SRC, 'src')}:${line} <${name}> — use :checked / :value`)
				}
			}
		}
		expect(offenders).toEqual([])
	})
})
