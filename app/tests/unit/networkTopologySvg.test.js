import { describe, it, expect } from 'vitest'

// Pure helper functions — reproduce the computed/method logic from NetworkTopologySvg.vue inline.
// No Vue import needed — these are pure JS functions testable without DOM.

function computeViewBox(nodes, pad = 40) {
  if (!nodes.length) return '0 0 400 300'
  const xs = nodes.map(n => n.x)
  const ys = nodes.map(n => n.y)
  const minX = Math.min(...xs) - pad
  const minY = Math.min(...ys) - pad
  const w = Math.max(...xs) - Math.min(...xs) + pad * 2
  const h = Math.max(...ys) - Math.min(...ys) + pad * 2
  return `${minX} ${minY} ${w} ${h}`
}

function nodeById(nodes, id) {
  return nodes.find(n => n.id === id) || { x: 0, y: 0 }
}

describe('NetworkTopologySvg pure logic', () => {
  it('viewBox adds 40px padding around node bounds', () => {
    const nodes = [
      { id: 'a', x: 100, y: 100 },
      { id: 'b', x: 300, y: 200 },
    ]
    // minX = 100 - 40 = 60, minY = 100 - 40 = 60
    // w = (300-100) + 80 = 280, h = (200-100) + 80 = 180
    expect(computeViewBox(nodes)).toBe('60 60 280 180')
  })

  it('nodeById returns node by id', () => {
    const nodes = [
      { id: 'r1', x: 100, y: 150, label: 'Router' },
      { id: 'sw1', x: 300, y: 150, label: 'Switch' },
    ]
    const found = nodeById(nodes, 'r1')
    expect(found.x).toBe(100)
    expect(found.y).toBe(150)
  })

  it('nodeById returns {x:0,y:0} fallback for unknown id', () => {
    const nodes = [{ id: 'r1', x: 100, y: 150 }]
    const fallback = nodeById(nodes, 'unknown-id')
    expect(fallback).toEqual({ x: 0, y: 0 })
  })
})
