import { describe, expect, it } from 'vitest'

import GameTimer from '../../src/components/GameTimer.vue'

globalThis.t = (app, text, vars = {}) => {
	return Object.entries(vars).reduce((acc, [key, value]) => acc.replace(`{${key}}`, String(value)), text)
}

function createInstance(timerState) {
	return {
		timerState,
		reducedMotion: false,
	}
}

describe('GameTimer', () => {
	it('renders safe/warning/critical labels for active timers', () => {
		const safe = createInstance({ expired: false, phase: 'safe', formattedTime: '05:30' })
		const warning = createInstance({ expired: false, phase: 'warning', formattedTime: '01:20' })
		const critical = createInstance({ expired: false, phase: 'danger', formattedTime: '00:12' })

		safe.timerPhaseLabel = GameTimer.computed.timerPhaseLabel.call(safe)
		warning.timerPhaseLabel = GameTimer.computed.timerPhaseLabel.call(warning)
		critical.timerPhaseLabel = GameTimer.computed.timerPhaseLabel.call(critical)

		expect(GameTimer.computed.timerPhaseLabel.call(safe)).toBe('Safe')
		expect(GameTimer.computed.timerLabel.call(warning)).toBe('Warning')
		expect(GameTimer.computed.timerLabel.call(critical)).toBe('Critical')
	})

	it('includes the phase label in the timer aria text', () => {
		const warning = createInstance({ expired: false, phase: 'warning', formattedTime: '01:20' })
		warning.timerPhaseLabel = GameTimer.computed.timerPhaseLabel.call(warning)

		expect(GameTimer.computed.timerAriaLabel.call(warning)).toBe('Exam time: 01:20 remaining, Warning')
	})

	it('announces expiry explicitly', () => {
		const expired = createInstance({ expired: true, phase: 'danger', formattedTime: '00:00' })

		expect(GameTimer.computed.timerPhaseLabel.call(expired)).toBe('Expired')
		expect(GameTimer.computed.timerAriaLabel.call(expired)).toBe('Exam time expired')
	})
})
