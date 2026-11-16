/**
 * GitHub #75, Gate 4 after C5: TrainingPrivacyNotice observed `this.$el`, but its template began
 * with an HTML comment before <section>. The shipped bundle keeps that comment, so the root is a
 * fragment and `$el` is a node that is not an element: `IntersectionObserver.observe()` threw a
 * TypeError on every course page of a mandatory training, and the notice never folded.
 *
 * The dev runtime Vitest normally uses hides this (it treats "comments + one element" as a single
 * root), so the first part renders the component the way the bundle does: template compiled for
 * production with comments kept, Vue's production runtime. The second part is the class gate:
 * every component that touches `this.$el` must have exactly one element at the template root.
 */
import { readFileSync, readdirSync } from 'fs'
import { join, relative } from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { parse, compileTemplate } from '@vue/compiler-sfc'
import * as ProdVue from 'vue/dist/vue.runtime.esm-browser.prod.js'
import TrainingPrivacyNotice from '../../src/components/TrainingPrivacyNotice.vue'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'anna' }) }))

const SRC = join(__dirname, '../../src')
const NOTICE = join(SRC, 'components/TrainingPrivacyNotice.vue')

/** The component as the production bundle builds it: comments kept, production render function. */
function productionNotice() {
	const { descriptor } = parse(readFileSync(NOTICE, 'utf8'), { filename: NOTICE })
	const { code, errors } = compileTemplate({
		source: descriptor.template.content,
		filename: NOTICE,
		id: 'notice',
		isProd: true,
		compilerOptions: { mode: 'function', comments: true },
	})
	expect(errors).toEqual([])
	// eslint-disable-next-line no-new-func
	const render = new Function('Vue', code)(ProdVue)
	return { ...TrainingPrivacyNotice, render }
}

function stubObserver() {
	const observers = []
	vi.stubGlobal('IntersectionObserver', class {
		constructor(callback, options) {
			this.callback = callback
			this.options = options
			observers.push(this)
		}

		observe(el) {
			// Like the browser: anything but an Element is a TypeError.
			if (!(el instanceof Element)) {
				throw new TypeError("Failed to execute 'observe' on 'IntersectionObserver': parameter 1 is not of type 'Element'.")
			}
			this.el = el
		}

		disconnect() {}
	})
	return observers
}

describe('TrainingPrivacyNotice in the production runtime (GitHub #75)', () => {
	let host, app

	function mount(component, props) {
		const errors = []
		host = document.createElement('div')
		document.body.appendChild(host)
		app = ProdVue.createApp({ render: () => ProdVue.h(component, props) })
		app.config.globalProperties.t = (_a, s) => s
		app.config.errorHandler = (err) => { errors.push(err) }
		app.mount(host)
		return errors
	}

	afterEach(() => {
		app?.unmount()
		host?.remove()
		window.localStorage.clear()
		vi.unstubAllGlobals()
	})

	it('observes the notice element without an error and folds after it was on screen', () => {
		const component = productionNotice()
		const observers = stubObserver()
		const errors = mount(component, { collapsible: true, class: 'materials-privacy' })
		expect(errors).toEqual([])
		expect(observers).toHaveLength(1)
		expect(observers[0].el).toBe(host.querySelector('section.training-privacy'))
		// The course page's class reaches the notice (a fragment root drops it silently).
		expect(host.querySelector('section.training-privacy').classList.contains('materials-privacy')).toBe(true)

		observers[0].callback([{ isIntersecting: true, intersectionRatio: 0.6 }])
		app.unmount()
		host.remove()

		mount(component, { collapsible: true })
		expect(host.querySelector('[data-test="training-privacy-toggle"]').getAttribute('aria-expanded')).toBe('false')
		expect(host.querySelector('#training-privacy-details').style.display).toBe('none')
	})
})

function vueFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return vueFiles(path)
		}
		return entry.name.endsWith('.vue') ? [path] : []
	})
}

describe('components that use this.$el have one element at the template root (GitHub #75)', () => {
	it('no comment, text or second element beside the root', () => {
		const offenders = []
		for (const file of vueFiles(SRC)) {
			const source = readFileSync(file, 'utf8')
			if (!/this\.\$el\b/.test(source)) {
				continue
			}
			const { descriptor } = parse(source, { filename: file })
			const roots = (descriptor.template?.ast.children || [])
				.filter((node) => !(node.type === 2 && !node.content.trim()))
			// A v-if / v-else chain is one root.
			const heads = roots.filter((node) => !(node.type === 1
				&& node.props.some((p) => p.type === 7 && (p.name === 'else' || p.name === 'else-if'))))
			const single = heads.length === 1 && heads[0].type === 1
				&& !['template', 'slot', 'Teleport', 'teleport'].includes(heads[0].tag)
			if (!single) {
				offenders.push(relative(SRC, file))
			}
		}
		expect(offenders).toEqual([])
	})
})
