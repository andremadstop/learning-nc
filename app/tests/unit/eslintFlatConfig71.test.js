// @vitest-environment node
// GitHub #71: ESLint 10 flat config (app/eslint.config.mjs) replaced .eslintrc.json.
// These tests lint snippets with the real config, so a project rule that silently drops out
// of the migration (or a later edit) turns red here, not in a review months later.
import { describe, expect, it } from 'vitest'
import { ESLint } from 'eslint'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const appDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const eslint = new ESLint({ cwd: appDir })

async function lint(code, file) {
	const [result] = await eslint.lintText(code, { filePath: path.join(appDir, file) })
	return result.messages.map(m => `${m.ruleId}:${m.severity}`)
}

describe('eslint flat config (#71)', () => {
	it('keeps the project security rules as errors', async () => {
		expect(await lint('eval("1")\n', 'src/x.js')).toContain('no-eval:2')
		expect(await lint('setTimeout("alert(1)", 1)\n', 'src/x.js')).toContain('no-implied-eval:2')
		expect(await lint('debugger\n', 'src/x.js')).toContain('no-debugger:2')
		expect(await lint('<template><div v-html="x" /></template>\n<script>\nexport default { data() { return { x: 1 } } }\n</script>\n', 'src/components/X.vue'))
			.toContain('vue/no-v-html:2')
	})

	it('still forbids importing l10n JSON directly', async () => {
		expect(await lint("import de from '../l10n/de.json'\nconsole.log(de)\n", 'src/x.js')).toContain('no-restricted-imports:2')
	})

	it('forbids the @nextcloud/vue package entry, which bundles the whole library', async () => {
		expect(await lint("import { NcButton } from '@nextcloud/vue'\nconsole.log(NcButton)\n", 'src/x.js')).toContain('no-restricted-imports:2')
		expect(await lint("import NcButton from '@nextcloud/vue/components/NcButton'\nconsole.log(NcButton)\n", 'src/x.js')).toEqual([])
	})

	it('keeps the ESLint 8 settings that ESLint 9/10 loosened', async () => {
		expect(await lint('while (true) { break }\n', 'src/x.js')).toContain('no-constant-condition:2')
		expect(await lint('if (window.x) { function f() {} f() }\n', 'src/x.js')).toContain('no-inner-declarations:2')
		expect(await lint('const a = 1;;\nconsole.log(a)\n', 'src/x.js')).toContain('no-extra-semi:2')
	})

	it('keeps the project relaxations', async () => {
		// unused catch parameter: ESLint 9 default "all" would warn on ~200 blocks
		expect(await lint('try { window.x() } catch (e) { window.y() }\n', 'src/x.js')).toEqual([])
		// unused argument with a leading underscore
		expect(await lint('export function f(_a, b) { return b }\n', 'src/x.js')).toEqual([])
		// single-word component names
		expect(await lint('<template><div /></template>\n<script>\nexport default { name: \'Card\' }\n</script>\n', 'src/components/Card.vue')).toEqual([])
		// project globals
		expect(await lint('console.log(t, n, OC, OCA, OCP)\n', 'src/x.js')).toEqual([])
	})

	it('lints .vue and .ts sources and skips the built bundles', async () => {
		expect(await eslint.isPathIgnored(path.join(appDir, 'js/learning-main.js'))).toBe(true)
		expect(await eslint.isPathIgnored(path.join(appDir, 'src/App.vue'))).toBe(false)
		expect(await lint('export const a: number = 1\nconst unused = 2\n', 'src/utils/x.ts'))
			.toEqual(['@typescript-eslint/no-unused-vars:2']) // an error since none were left (27.11.2026)
		// .cjs is parsed as CommonJS, everything else as an ES module
		expect((await eslint.calculateConfigForFile(path.join(appDir, 'tests/print/x.cjs'))).languageOptions.sourceType).toBe('commonjs')
		expect((await eslint.calculateConfigForFile(path.join(appDir, 'src/x.js'))).languageOptions.sourceType).toBe('module')
	})
})
