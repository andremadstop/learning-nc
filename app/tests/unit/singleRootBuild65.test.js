/**
 * GitHub #65 (after #75): the production bundle kept template comments, so every component with a
 * comment beside its root element was a fragment there. A fragment root has no element `$el` and
 * drops class, style and other attributes a parent passes, without a warning (the dev runtime
 * Vitest normally uses hides it). Nine components were affected, CertPoolResult also had two
 * root spans.
 *
 * This gate compiles every .vue file with the template compiler options the build itself uses
 * (read from vite.config.mjs, not copied) and requires exactly one element or component at the
 * root; a v-if / v-else chain counts as one when every branch is one. Exceptions must be listed
 * below with a reason.
 */
import { readFileSync, readdirSync } from 'fs'
import { join, relative } from 'path'
import { afterEach, describe, expect, it } from 'vitest'
import { parse, compileTemplate } from '@vue/compiler-sfc'
import { compile } from '@vue/compiler-dom'
import * as ProdVue from 'vue/dist/vue.runtime.esm-browser.prod.js'
import { buildEntries, createLearningViteConfig } from '../../vite.config.mjs'

const SRC = join(__dirname, '../../src')

/**
 * Components allowed to render more than one root node in the bundle, path relative to src/ → reason.
 * Empty on purpose; add an entry only with a reason why no parent can pass attributes.
 */
const ALLOWED_FRAGMENTS = {}

// compiler-core node types / element tag types
const ELEMENT = 1
const IF = 9
const PLAIN_ELEMENT = 0
const COMPONENT = 1

function vueFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return vueFiles(path)
		}
		return entry.name.endsWith('.vue') ? [path] : []
	})
}

/** The @vitejs/plugin-vue options of one build entry. */
function vuePluginOptions(entryName) {
	const plugin = createLearningViteConfig(entryName).plugins.flat().find((p) => p?.name === 'vite:vue')
	expect(plugin, `vue plugin in the ${entryName} build`).toBeTruthy()
	return plugin.api.options
}

/** Template compiler options of the production build. Unset `comments` = kept, as the bundle showed. */
function buildCompilerOptions() {
	const compilerOptions = vuePluginOptions('learning').template?.compilerOptions || {}
	return { ...compilerOptions, comments: compilerOptions.comments ?? true }
}

function isSingleElement(node) {
	return node.type === ELEMENT && (node.tagType === PLAIN_ELEMENT || node.tagType === COMPONENT)
}

function rootProblem(file, compilerOptions) {
	const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
	if (!descriptor.template) {
		return null
	}
	const { ast } = compile(descriptor.template.content, { ...compilerOptions, whitespace: 'condense', hoistStatic: false })
	const roots = ast.children
	if (roots.length === 1 && isSingleElement(roots[0])) {
		return null
	}
	if (roots.length === 1 && roots[0].type === IF
		&& roots[0].branches.every((branch) => branch.children.length === 1 && isSingleElement(branch.children[0]))) {
		return null
	}
	return roots.map((node) => (node.type === ELEMENT ? `<${node.tag}>` : `node type ${node.type}`)).join(' + ')
}

describe('the production build drops template comments (GitHub #65)', () => {
	it.each(buildEntries.map((entry) => entry.name))('%s: comments: false', (entryName) => {
		expect(vuePluginOptions(entryName).template?.compilerOptions?.comments).toBe(false)
	})
})

describe('every component has one root as the build compiles it (GitHub #65)', () => {
	it('no fragment root outside the documented exceptions', () => {
		const compilerOptions = buildCompilerOptions()
		const offenders = {}
		for (const file of vueFiles(SRC)) {
			const name = relative(SRC, file)
			const problem = rootProblem(file, compilerOptions)
			if (problem && !ALLOWED_FRAGMENTS[name]) {
				offenders[name] = problem
			}
		}
		expect(offenders).toEqual({})
	})

	it('every exception still exists and is still a fragment', () => {
		const compilerOptions = buildCompilerOptions()
		for (const name of Object.keys(ALLOWED_FRAGMENTS)) {
			expect(rootProblem(join(SRC, name), compilerOptions), name).not.toBeNull()
		}
	})
})

describe('CertPoolResult in the production runtime (GitHub #65)', () => {
	let host, app

	afterEach(() => {
		app?.unmount()
		host?.remove()
	})

	it('a class from the parent reaches the component and both result cells render', async () => {
		const file = join(SRC, 'components/CertPoolResult.vue')
		const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file })
		const { code, errors } = compileTemplate({
			source: descriptor.template.content,
			filename: file,
			id: 'cert-pool-result',
			isProd: true,
			compilerOptions: { mode: 'function', ...buildCompilerOptions() },
		})
		expect(errors).toEqual([])
		// eslint-disable-next-line no-new-func
		const render = new Function('Vue', code)(ProdVue)
		const component = { ...(await import('../../src/components/CertPoolResult.vue')).default, render }

		host = document.createElement('div')
		document.body.appendChild(host)
		app = ProdVue.createApp({ render: () => ProdVue.h(component, { bestScore: 85, passed: true, class: 'parent-class' }) })
		app.config.globalProperties.t = (_app, text, vars) => text.replace(/\{(\w+)\}/g, (_m, key) => vars?.[key] ?? '')
		app.mount(host)

		const root = host.querySelector('.cert-pool-result')
		expect(root).not.toBeNull()
		expect(root.classList.contains('parent-class')).toBe(true)
		expect(root.querySelector('.cert-pool-result__score').textContent.trim()).toBe('Bestes Ergebnis: 85 %')
		expect(root.querySelector('.cert-pool-result__state--passed').textContent.trim()).toBe('✓ Bestanden')
	})

	it('the wrapper is display: contents, so both spans stay cells of the parent grid / flex row', () => {
		const source = readFileSync(join(SRC, 'components/CertPoolResult.vue'), 'utf8')
		expect(source).toMatch(/\.cert-pool-result\s*\{\s*display:\s*contents;\s*\}/)
	})
})
