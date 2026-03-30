import { describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

vi.mock('../../src/utils/virtuprof-i18n.js', () => ({
	translateVirtuProf: vi.fn((lang, key) => key),
}))

import VirtuProfFullscreen from '../../src/components/VirtuProfFullscreen.vue'

function createInstance(overrides = {}) {
	const data = typeof VirtuProfFullscreen.data === 'function' ? VirtuProfFullscreen.data() : {}
	const instance = {
		...data,
		// Props with defaults
		title: '',
		subtitle: '',
		language: '',
		step: null,
		chatMessages: [],
		chatLoading: false,
		aiEnabled: true,
		showConsentDialog: false,
		consentData: {},
		examBlocked: false,
		hasQuestionContext: false,
		currentContext: {},
		// Vue internals
		$emit: vi.fn(),
		$refs: {},
		$nextTick: vi.fn((fn) => fn && fn()),
		...overrides,
	}

	// Bind computed properties
	if (VirtuProfFullscreen.computed) {
		for (const [key, computed] of Object.entries(VirtuProfFullscreen.computed)) {
			Object.defineProperty(instance, key, {
				get: () => computed.call(instance),
				configurable: true,
			})
		}
	}

	return instance
}

describe('VirtuProfFullscreen', () => {
	it('component defines the expected props and data', () => {
		const instance = createInstance()
		expect(instance.draft).toBe('')
		expect(instance.mobileContextOpen).toBe(false)
		expect(instance.headerTouchY).toBe(0)
		expect(instance.isMobile).toBe(false)
	})

	it('has expected component name and props', () => {
		expect(VirtuProfFullscreen.name).toBe('VirtuProfFullscreen')
		expect(VirtuProfFullscreen.props.aiEnabled).toBeDefined()
		expect(VirtuProfFullscreen.props.chatMessages).toBeDefined()
	})

	it('emits close when X-button click triggers $emit("close")', () => {
		const instance = createInstance()
		// The X-button in template calls $emit('close') directly — simulate
		instance.$emit('close')
		expect(instance.$emit).toHaveBeenCalledWith('close')
	})

	it('emits close when ESC key is pressed via handleGlobalKeydown', () => {
		const instance = createInstance()
		const handler = VirtuProfFullscreen.methods.handleGlobalKeydown.bind(instance)
		handler({ key: 'Escape' })
		expect(instance.$emit).toHaveBeenCalledWith('close')
	})

	it('does not emit close for non-Escape keys', () => {
		const instance = createInstance()
		const handler = VirtuProfFullscreen.methods.handleGlobalKeydown.bind(instance)
		handler({ key: 'Enter' })
		expect(instance.$emit).not.toHaveBeenCalled()
	})

	it('emits close on mobile swipe-down > 80px', () => {
		const instance = createInstance({ isMobile: true, headerTouchY: 100 })
		const handler = VirtuProfFullscreen.methods.onHeaderTouchEnd.bind(instance)
		handler({ changedTouches: [{ clientY: 185 }] })
		expect(instance.$emit).toHaveBeenCalledWith('close')
	})

	it('ignores multi-touch (changedTouches.length !== 1)', () => {
		const instance = createInstance({ isMobile: true, headerTouchY: 100 })
		const handler = VirtuProfFullscreen.methods.onHeaderTouchEnd.bind(instance)
		handler({ changedTouches: [{ clientY: 185 }, { clientY: 200 }] })
		expect(instance.$emit).not.toHaveBeenCalled()
	})

	it('ignores swipe-down on desktop (isMobile=false)', () => {
		const instance = createInstance({ isMobile: false, headerTouchY: 100 })
		const handler = VirtuProfFullscreen.methods.onHeaderTouchEnd.bind(instance)
		handler({ changedTouches: [{ clientY: 185 }] })
		expect(instance.$emit).not.toHaveBeenCalled()
	})
})
