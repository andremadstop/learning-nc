import { describe, it, expect } from 'vitest'
import { isHintRequest, isMetaQuestion } from '../../src/utils/virtuprof-chat-classify.js'

// Charakterisierungs-Tests: fixieren das Verhalten, das vor der Extraktion
// als Methoden in VirtuProf.vue lag (isHintRequest/isMetaQuestion). Zero-Behavior-Change.

describe('isHintRequest', () => {
	it('matcht exaktes Keyword (case-insensitive, getrimmt)', () => {
		expect(isHintRequest('tipp')).toBe(true)
		expect(isHintRequest('Tipp')).toBe(true)
		expect(isHintRequest('  Hilfe  ')).toBe(true)
		expect(isHintRequest('hint')).toBe(true)
		expect(isHintRequest('help me')).toBe(true)
	})

	it('matcht Keyword am Satzanfang (kw + Leerzeichen)', () => {
		expect(isHintRequest('tipp bitte')).toBe(true)
		expect(isHintRequest('hilfe mir mal')).toBe(true)
	})

	it('matcht Keyword am Satzende (Leerzeichen + kw)', () => {
		expect(isHintRequest('ich brauche einen tipp')).toBe(true)
		expect(isHintRequest('gib mir einen tipp')).toBe(true)
	})

	it('matcht NICHT bei bloßem Substring ohne Wortgrenze', () => {
		expect(isHintRequest('stipptastic')).toBe(false)
		expect(isHintRequest('hello world')).toBe(false)
		expect(isHintRequest('')).toBe(false)
	})
})

describe('isMetaQuestion', () => {
	it('kurze Frage (<= 5 Wörter, endet mit ?) gilt als Meta', () => {
		expect(isMetaQuestion('was?')).toBe(true)
		expect(isMetaQuestion('is this correct?')).toBe(true)
		expect(isMetaQuestion('wie geht das genau?')).toBe(true)
	})

	it('lange Frage (> 5 Wörter) ist nur über Pattern Meta', () => {
		expect(isMetaQuestion('warum ist die richtige antwort hier eigentlich diese option?')).toBe(false)
	})

	it('matcht Meta-Pattern unabhängig von der Länge', () => {
		expect(isMetaQuestion('was bedeutet das genau in diesem konkreten fall hier')).toBe(true)
		expect(isMetaQuestion('erklär mir das nochmal in ruhe')).toBe(true)
		expect(isMetaQuestion('hä')).toBe(true)
		expect(isMetaQuestion('what do you mean')).toBe(true)
	})

	it('normale Aussage ohne ? und ohne Pattern ist keine Meta-Frage', () => {
		expect(isMetaQuestion('ok')).toBe(false)
		expect(isMetaQuestion('die antwort ist option b')).toBe(false)
	})
})
