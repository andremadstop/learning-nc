import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createApp, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

import CharacterAvatar from '../../src/components/CharacterAvatar.vue'

const SCHOLAR_IDS = ['theoretiker', 'kosmologe', 'popularisierer']
const FORBIDDEN_NAME_PATTERN = /einstein|hawking|tyson|neil\s*degrasse|cosmos|startalk/i

const TEST_DIR = dirname(fileURLToPath(import.meta.url))
const APP_ROOT = resolve(TEST_DIR, '../..')
const SOURCE_FILES = [
	'src/components/CharacterAvatar.vue',
	'src/data/characters.js',
]

let mountedApp = null

beforeEach(() => {
	if (mountedApp) {
		mountedApp.unmount()
		mountedApp = null
	}
	document.body.innerHTML = '<div id="app"></div>'
	setActivePinia(createPinia())
})

afterEach(() => {
	if (mountedApp) {
		mountedApp.unmount()
		mountedApp = null
	}
})

function mountScholar(characterId) {
	const app = createApp({
		render: () => h(CharacterAvatar, {
			characterId,
			state: 'idle',
			size: 96,
		}),
	})
	app.use(createPinia())
	app.config.globalProperties.t = (appName, str) => str
	app.mount('#app')
	mountedApp = app

	return document.getElementById('app').innerHTML
}

function sourceWithoutSfcScript(source) {
	return source.replace(/<script[\s\S]*?<\/script>/gi, '')
}

describe('Phase 152 scholar rendered SVG security', () => {
	it.each(SCHOLAR_IDS)('renders "%s" without unsafe SVG constructs', (characterId) => {
		const html = mountScholar(characterId)

		expect(html).not.toMatch(/<title[\s>]/i)
		expect(html).not.toMatch(/<script[\s>]/i)
		expect(html).not.toMatch(/<foreignObject[\s>]/i)
		expect(html).not.toMatch(/xlink:href/i)
		expect(html).not.toMatch(/\son[a-z]+=/i)
		expect(html).not.toMatch(FORBIDDEN_NAME_PATTERN)
	})
})

describe('Phase 152 scholar source SVG security', () => {
	it.each(SOURCE_FILES)('%s contains no forbidden inline SVG patterns', (relativePath) => {
		const source = sourceWithoutSfcScript(readFileSync(resolve(APP_ROOT, relativePath), 'utf8'))

		expect(source).not.toMatch(/<script[\s>]/i)
		expect(source).not.toMatch(/<foreignObject[\s>]/i)
		expect(source).not.toMatch(/xlink:href/i)
		expect(source).not.toMatch(/\son[a-z]+=/i)
		expect(source).not.toMatch(FORBIDDEN_NAME_PATTERN)
	})
})
