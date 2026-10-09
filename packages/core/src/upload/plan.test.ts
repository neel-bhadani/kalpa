import { describe, expect, it } from 'vitest'
import { partRange, planParts } from './plan'

const MB = 1024 * 1024
const PART = 8 * MB

describe('planParts', () => {
  it('cuts a file into full parts and a smaller last part', () => {
    expect(planParts(20 * MB, PART)).toEqual({ size: 20 * MB, partSize: PART, count: 3, lastPartSize: 4 * MB })
  })

  it('gives an exact multiple a full last part', () => {
    expect(planParts(16 * MB, PART)).toMatchObject({ count: 2, lastPartSize: PART })
  })

  it('uses one part for a small file', () => {
    expect(planParts(1, PART)).toMatchObject({ count: 1, lastPartSize: 1 })
    expect(planParts(PART - 1, PART)).toMatchObject({ count: 1, lastPartSize: PART - 1 })
  })

  it('handles one byte over a part boundary', () => {
    expect(planParts(PART + 1, PART)).toMatchObject({ count: 2, lastPartSize: 1 })
  })

  it('plans a 2 GB file into 256 parts', () => {
    expect(planParts(2 * 1024 * MB, PART)).toMatchObject({ count: 256, lastPartSize: PART })
  })

  it('rejects an empty file or a bad part size', () => {
    expect(() => planParts(0, PART)).toThrow(RangeError)
    expect(() => planParts(10, 0)).toThrow(RangeError)
  })
})

describe('partRange', () => {
  const plan = planParts(20 * MB, PART)

  it('gives each part its byte range', () => {
    expect(partRange(plan, 1)).toEqual({ start: 0, end: PART })
    expect(partRange(plan, 2)).toEqual({ start: PART, end: 2 * PART })
    expect(partRange(plan, 3)).toEqual({ start: 2 * PART, end: 20 * MB })
  })

  it('rejects part numbers outside 1..count', () => {
    expect(() => partRange(plan, 0)).toThrow(RangeError)
    expect(() => partRange(plan, 4)).toThrow(RangeError)
  })
})
