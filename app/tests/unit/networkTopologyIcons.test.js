import { describe, it, expect } from 'vitest'
import { DEVICE_ICONS } from '../../src/utils/networkTopologyIcons.js'

const REQUIRED_TYPES = ['router', 'switch', 'firewall', 'server', 'cloud', 'workstation', 'ap', 'wre']

describe('DEVICE_ICONS', () => {
  it('has all 8 required device types', () => {
    for (const type of REQUIRED_TYPES) {
      expect(DEVICE_ICONS).toHaveProperty(type)
    }
  })

  it('each type value is an Array', () => {
    for (const type of REQUIRED_TYPES) {
      expect(Array.isArray(DEVICE_ICONS[type])).toBe(true)
    }
  })

  it('each type has at least one path string (length > 0)', () => {
    for (const type of REQUIRED_TYPES) {
      expect(DEVICE_ICONS[type].length).toBeGreaterThan(0)
    }
  })

  it('every path string is a non-empty string', () => {
    for (const type of REQUIRED_TYPES) {
      for (const path of DEVICE_ICONS[type]) {
        expect(typeof path).toBe('string')
        expect(path.length).toBeGreaterThan(0)
      }
    }
  })
})
