// Checks tool call arguments against a command's schema (the JsonSchema subset in core/modules).
// Returns plain messages, e.g. ["text is required", "tab must be one of: notes, details"].

import type { JsonSchema } from '../../core/src/modules'

export function validateArgs(schema: JsonSchema, value: unknown, path = ''): string[] {
  const name = path || 'arguments'
  const errors: string[] = []

  switch (schema.type) {
    case 'object': {
      if (typeof value !== 'object' || value === null || Array.isArray(value)) return [`${name} must be an object`]
      const record = value as Record<string, unknown>
      for (const key of schema.required ?? []) {
        if (record[key] === undefined) errors.push(`${join(path, key)} is required`)
      }
      for (const [key, item] of Object.entries(record)) {
        const child = schema.properties?.[key]
        if (child) errors.push(...validateArgs(child, item, join(path, key)))
        else if (schema.additionalProperties === false) errors.push(`${join(path, key)} is not allowed`)
      }
      return errors
    }
    case 'string':
      if (typeof value !== 'string') return [`${name} must be text`]
      if (schema.minLength !== undefined && value.length < schema.minLength) errors.push(`${name} must have at least ${schema.minLength} character(s)`)
      if (schema.maxLength !== undefined && value.length > schema.maxLength) errors.push(`${name} must have at most ${schema.maxLength} characters`)
      break
    case 'number':
    case 'integer':
      if (typeof value !== 'number' || (schema.type === 'integer' && !Number.isInteger(value))) return [`${name} must be a${schema.type === 'integer' ? 'n integer' : ' number'}`]
      break
    case 'boolean':
      if (typeof value !== 'boolean') return [`${name} must be true or false`]
      break
    case 'array':
      if (!Array.isArray(value)) return [`${name} must be a list`]
      if (schema.items) value.forEach((item, i) => errors.push(...validateArgs(schema.items!, item, `${name}[${i}]`)))
      break
  }

  if (schema.enum && !schema.enum.includes(value as string | number)) {
    errors.push(`${name} must be one of: ${schema.enum.join(', ')}`)
  }

  return errors
}

function join(path: string, key: string): string {
  return path ? `${path}.${key}` : key
}
