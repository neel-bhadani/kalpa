// How a file is cut into multipart parts. Part numbers start at 1.

export type PartPlan = {
  size: number
  partSize: number
  count: number
  lastPartSize: number
}

export function planParts(size: number, partSize: number): PartPlan {
  if (!Number.isInteger(size) || size < 1) {
    throw new RangeError('A file must have at least 1 byte.')
  }
  if (!Number.isInteger(partSize) || partSize < 1) {
    throw new RangeError('The part size must be a positive whole number.')
  }

  const count = Math.ceil(size / partSize)

  return { size, partSize, count, lastPartSize: size - (count - 1) * partSize }
}

// Byte range [start, end) of part `number`.
export function partRange(plan: PartPlan, number: number): { start: number; end: number } {
  if (!Number.isInteger(number) || number < 1 || number > plan.count) {
    throw new RangeError(`Part numbers go from 1 to ${plan.count}.`)
  }

  const start = (number - 1) * plan.partSize

  return { start, end: Math.min(start + plan.partSize, plan.size) }
}
