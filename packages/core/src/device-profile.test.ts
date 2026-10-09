import { describe, expect, it } from 'vitest'
import { computeTier, type DeviceProfile } from './device-profile'

function profile(overrides: Partial<DeviceProfile> = {}): DeviceProfile {
  return {
    memoryGb: 8,
    cores: 6,
    webgl2: true,
    gpu: 'Test GPU',
    webgpu: false,
    storageQuotaBytes: null,
    storageUsageBytes: null,
    ...overrides,
  }
}

describe('computeTier', () => {
  it('is mid for an ordinary desktop', () => {
    expect(computeTier(profile())).toBe('mid')
  })

  it('is low at 4 GB or less', () => {
    expect(computeTier(profile({ memoryGb: 4 }))).toBe('low')
    expect(computeTier(profile({ memoryGb: 0.5 }))).toBe('low')
  })

  it('is mid just above 4 GB', () => {
    expect(computeTier(profile({ memoryGb: 6 }))).toBe('mid')
  })

  it('is low at 2 cores or less', () => {
    expect(computeTier(profile({ cores: 2 }))).toBe('low')
    expect(computeTier(profile({ cores: 1 }))).toBe('low')
  })

  it('is low without WebGL2, even on strong hardware', () => {
    expect(computeTier(profile({ webgl2: false, memoryGb: 8, cores: 16, webgpu: true }))).toBe('low')
  })

  it('is high with 8 GB, 8 cores and WebGPU', () => {
    expect(computeTier(profile({ memoryGb: 8, cores: 8, webgpu: true }))).toBe('high')
  })

  it('is mid when one high condition is missing', () => {
    expect(computeTier(profile({ memoryGb: 8, cores: 8, webgpu: false }))).toBe('mid')
    expect(computeTier(profile({ memoryGb: 8, cores: 7, webgpu: true }))).toBe('mid')
    expect(computeTier(profile({ memoryGb: 6, cores: 16, webgpu: true }))).toBe('mid')
  })

  it('treats unknown memory or cores as neither low nor high', () => {
    expect(computeTier(profile({ memoryGb: null, cores: 16, webgpu: true }))).toBe('mid')
    expect(computeTier(profile({ memoryGb: 8, cores: null, webgpu: true }))).toBe('mid')
    expect(computeTier(profile({ memoryGb: null, cores: null }))).toBe('mid')
  })

  it('is still low with unknown memory when cores are few', () => {
    expect(computeTier(profile({ memoryGb: null, cores: 2 }))).toBe('low')
  })
})
