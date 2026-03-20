import { describe, it, expect } from 'vitest'
import { scoringSummary } from '../../src/utils/pbqScoringMode.js'

const positions = [
  { id: 'n1', correct: 'Router' },
  { id: 'n2', correct: 'Switch' },
  { id: 'n3', correct: 'Firewall' },
]

describe('scoringSummary', () => {
  it('strict: all correct returns Alle korrekt', () => {
    const value = { n1: 'Router', n2: 'Switch', n3: 'Firewall' }
    expect(scoringSummary(positions, value, 'strict')).toBe('Alle korrekt')
  })

  it('strict: partial correct returns X / Y korrekt', () => {
    const value = { n1: 'Router', n2: 'Switch', n3: 'Hub' }
    expect(scoringSummary(positions, value, 'strict')).toBe('2 / 3 korrekt')
  })

  it('partial: shows percentage', () => {
    const value = { n1: 'Router', n2: 'Switch', n3: 'Hub' }
    expect(scoringSummary(positions, value, 'partial')).toBe('2 / 3 korrekt (67%)')
  })

  it('partial: 0 correct returns 0 / N korrekt (0%)', () => {
    const value = { n1: 'Hub', n2: 'Hub', n3: 'Hub' }
    expect(scoringSummary(positions, value, 'partial')).toBe('0 / 3 korrekt (0%)')
  })

  it('empty positions returns empty string', () => {
    expect(scoringSummary([], {}, 'strict')).toBe('')
  })

  it('missing correct field excluded from count', () => {
    const posWithMissing = [
      { id: 'n1', correct: 'Router' },
      { id: 'n2' }, // no correct field
      { id: 'n3', correct: 'Firewall' },
    ]
    // n2 has no correct field — value n2='Switch' should NOT count as correct
    const value = { n1: 'Router', n2: 'Switch', n3: 'Firewall' }
    // positions total = 3, but only n1 and n3 can be correct (n2 has no correct field)
    // n1 correct, n3 correct => 2 correct of 3 positions
    expect(scoringSummary(posWithMissing, value, 'strict')).toBe('2 / 3 korrekt')
  })

  it('default mode behaves as strict', () => {
    const value = { n1: 'Router', n2: 'Switch', n3: 'Firewall' }
    expect(scoringSummary(positions, value)).toBe('Alle korrekt')
  })
})
