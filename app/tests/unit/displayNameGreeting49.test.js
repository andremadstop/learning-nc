/**
 * GitHub #49: the first word of the Nextcloud display name was taken as the first name, so a
 * display name "Doe Jane" was greeted as "Doe". The whole display name is used now.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import OnbSplash from '../../src/components/onboarding/OnbSplash.vue'

const here = path.dirname(fileURLToPath(import.meta.url))

describe('display name in greetings (GitHub #49)', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('the onboarding splash greets with the whole display name', () => {
		vi.stubGlobal('OC', { getCurrentUser: () => ({ displayName: 'Doe Jane' }) })
		const name = OnbSplash.computed.greetingName.call({})
		expect(name).toBe('Doe Jane')
	})

	it('the onboarding splash puts that name into its greeting', () => {
		const source = readFileSync(path.join(here, '../../src/components/onboarding/OnbSplash.vue'), 'utf8')
		expect(source).toContain('{ name: greetingName }')
		expect(source).not.toMatch(/split\(' '\)\[0\]/)
	})

	it('VirtuProf greets with the whole display name, not its first word', () => {
		const source = readFileSync(path.join(here, '../../src/components/VirtuProf.vue'), 'utf8')
		expect(source).toContain('this.userFirstName = String(user.displayName).trim()')
		expect(source).not.toMatch(/displayName\)\.split\(/)
	})
})
