import { describe, it, expect } from 'vitest'
import {
	formatDate,
	barWidth,
	buildStandingsCommentary,
	buildAnswerMessage,
} from '../../src/utils/gameshowDisplay.js'

describe('formatDate', () => {
	it('leerer/fehlender Timestamp → leerer String', () => {
		expect(formatDate(0)).toBe('')
		expect(formatDate(null)).toBe('')
		expect(formatDate(undefined)).toBe('')
	})

	it('gültiger Timestamp → nicht-leerer String mit Uhrzeit', () => {
		const out = formatDate(1700000000)
		expect(typeof out).toBe('string')
		expect(out.length).toBeGreaterThan(0)
		expect(out).toContain(':')
	})
})

describe('barWidth', () => {
	it('proportionale Breite relativ zum Maximum', () => {
		expect(barWidth(50, 100)).toBe('50%')
		expect(barWidth(100, 100)).toBe('100%')
	})

	it('Mindestbreite 5% bei sehr kleinem Score', () => {
		expect(barWidth(0, 100)).toBe('5%')
		expect(barWidth(1, 1000)).toBe('5%')
	})
})

describe('buildStandingsCommentary', () => {
	it('keine Spieler → leerer String', () => {
		expect(buildStandingsCommentary({ sortedPlayers: [] })).toBe('')
		expect(buildStandingsCommentary({})).toBe('')
	})

	describe('Score-Modus', () => {
		it('knapper Vorsprung (<=50): Runner ist dicht dran', () => {
			const out = buildStandingsCommentary({
				sortedPlayers: [{ display_name: 'Ann', score: 120 }, { display_name: 'Ben', score: 100 }],
			})
			expect(out).toBe('Ben ist dicht dran an Ann!')
		})

		it('grosser Vorsprung (>200): Leader dominiert', () => {
			const out = buildStandingsCommentary({
				sortedPlayers: [{ display_name: 'Ann', score: 400 }, { display_name: 'Ben', score: 100 }],
			})
			expect(out).toBe('Ann dominiert!')
		})

		it('mittlerer Vorsprung: Punkte-Vorsprung genannt', () => {
			const out = buildStandingsCommentary({
				sortedPlayers: [{ display_name: 'Ann', score: 300 }, { display_name: 'Ben', score: 100 }],
			})
			expect(out).toBe('Ann fuehrt mit 200 Punkten Vorsprung!')
		})

		it('einzelner Spieler: fuehrt', () => {
			expect(buildStandingsCommentary({ sortedPlayers: [{ display_name: 'Ann', score: 10 }] })).toBe('Ann fuehrt!')
		})

		it('Fallback auf user_id ohne display_name', () => {
			const out = buildStandingsCommentary({ sortedPlayers: [{ user_id: 'u1', score: 10 }] })
			expect(out).toBe('u1 fuehrt!')
		})
	})

	describe('Elimination-Modus', () => {
		it('letzter Überlebender', () => {
			const out = buildStandingsCommentary({
				isEliminationMode: true,
				remainingPlayerCount: 1,
				sortedPlayers: [{ display_name: 'Ann', lives: 3 }],
			})
			expect(out).toBe('Ann ist der letzte Überlebende!')
		})

		it('Sudden Death bei 2 verbleibenden', () => {
			const out = buildStandingsCommentary({
				isEliminationMode: true,
				remainingPlayerCount: 2,
				sortedPlayers: [{ display_name: 'Ann' }, { display_name: 'Ben' }],
			})
			expect(out).toBe('Sudden Death zwischen Ann und Ben!')
		})

		it('kürzlich eliminierter Spieler wird genannt', () => {
			const out = buildStandingsCommentary({
				isEliminationMode: true,
				remainingPlayerCount: 3,
				sortedPlayers: [{ display_name: 'Ann' }],
				recentlyEliminatedPlayers: [{ display_name: 'Cid' }],
			})
			expect(out).toBe('Cid ist raus. Noch 3 Spieler im Spiel.')
		})

		it('sonst: Leader fuehrt mit Leben', () => {
			const out = buildStandingsCommentary({
				isEliminationMode: true,
				remainingPlayerCount: 4,
				sortedPlayers: [{ display_name: 'Ann', lives: 2 }],
				recentlyEliminatedPlayers: [],
			})
			expect(out).toBe('Ann fuehrt mit 2 Leben.')
		})
	})
})

describe('buildAnswerMessage', () => {
	it('correct=true → positive Nachricht (injizierter rand)', () => {
		expect(buildAnswerMessage(true, () => 0)).toBe('Richtig! Weiter so!')
		expect(buildAnswerMessage(true, () => 0.99)).toBe('Stark!')
	})

	it('correct=false → negative Nachricht (injizierter rand)', () => {
		expect(buildAnswerMessage(false, () => 0)).toBe('Knapp daneben!')
		expect(buildAnswerMessage(false, () => 0.99)).toBe('Nicht ganz!')
	})
})
