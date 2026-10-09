import { describe, expect, it } from 'vitest'
import { findModule, modules } from './modules'
// Written by `make module-keys` from the API's module registry (php artisan modules:keys).
import serverKeys from './module-keys.json'

describe('client module registry', () => {
  it('has the same modules as the server, in the same order', () => {
    expect(modules.map(module => module.key)).toEqual(serverKeys)
  })

  it('gives every module a name, an icon and a lazy editor', () => {
    for (const module of modules) {
      expect(module.name).not.toBe('')
      expect(module.icon).toMatch(/^[a-z0-9-]+$/)
      expect(typeof module.editor).toBe('function')
    }
  })

  it('finds a module by key', () => {
    expect(findModule('voice')?.name).toBe('Voice')
    expect(findModule('nope')).toBeUndefined()
  })
})
