import { defineConfig } from 'vitest/config'
import base from './vitest.config'

// make eval-story: the Story assistant against a real model (slow; not part of make test).
export default defineConfig({
  resolve: base.resolve,
  test: {
    dir: '../../packages',
    include: ['**/*.eval.ts'],
    testTimeout: 6 * 60 * 60 * 1000,
    // Show the progress lines as they come.
    silent: false,
    reporters: ['verbose'],
  },
})
