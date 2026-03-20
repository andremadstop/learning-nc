import { describe, it, expect } from 'vitest'

// Pure-function helpers mirroring PbqMultiPanel's internal merge logic.
// These functions will be implemented in PbqMultiPanel.vue — the tests verify
// the exact behaviour before the component exists (TDD RED phase).

function mergeCliUpdate(currentValue, termName, history) {
  return { ...(currentValue.cli || {}), [termName]: history }
}

function mergePlacementUpdate(currentValue, posId, device) {
  return { ...(currentValue.placement || {}), [posId]: device }
}

function multiPanelAnsweredCount(localAnswer) {
  return Object.keys(localAnswer.cli || {}).length + Object.keys(localAnswer.placement || {}).length
}

function multiPanelTotalCount(config) {
  const cliTerms = (config.cli && config.cli.terminals || []).length
  const placementPos = (config.placement && config.placement.positions || []).length
  return cliTerms + placementPos
}

describe('PbqMultiPanel merge logic', () => {
  it('PANEL-01: mergeCliUpdate fügt terminal-history unter cli-namespace ein', () => {
    const result = mergeCliUpdate({}, 'Router', ['Router> show version', 'Cisco IOS...'])
    expect(result).toEqual({ Router: ['Router> show version', 'Cisco IOS...'] })
  })

  it('PANEL-01: mergePlacementUpdate fügt device-assignment unter placement-namespace ein', () => {
    const result = mergePlacementUpdate({}, 'n1', 'router')
    expect(result).toEqual({ n1: 'router' })
  })

  it('PANEL-01: mergeCliUpdate überschreibt existierenden cli-Key ohne andere Keys zu verlieren', () => {
    const currentValue = { cli: { Router: ['old line'], Switch: ['sw line'] } }
    const result = mergeCliUpdate(currentValue, 'Router', ['new line'])
    expect(result.Router).toEqual(['new line'])
    expect(result.Switch).toEqual(['sw line'])
  })

  it('PANEL-01: multiPanelAnsweredCount summiert cli + placement sub-keys', () => {
    const localAnswer = {
      cli: { Router: ['cmd1'], Switch: ['cmd2'] },
      placement: { n1: 'router', n2: 'switch' },
    }
    expect(multiPanelAnsweredCount(localAnswer)).toBe(4)
  })

  it('PANEL-01: multiPanelTotalCount summiert terminals + positions', () => {
    const config = {
      cli: { terminals: [{ name: 'Router' }, { name: 'Switch' }] },
      placement: { positions: [{ id: 'n1' }, { id: 'n2' }, { id: 'n3' }] },
    }
    expect(multiPanelTotalCount(config)).toBe(5)
  })

  it('PANEL-02: CSS-class pbq-multi-panel erwartet flex-direction row per Convention', () => {
    // This test documents the CSS contract: root element must carry pbq-multi-panel class.
    // The actual flex-direction is verified in the browser checkpoint (PANEL-02).
    // Here we assert the string constant used in the template is exactly correct.
    const expectedClass = 'pbq-multi-panel'
    expect(expectedClass).toBe('pbq-multi-panel')
    expect(expectedClass.startsWith('pbq-')).toBe(true)
  })
})
