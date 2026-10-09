import { defineConfig } from 'vitest/config'

// Unit tests for the shared packages (packages/*), run from the web container.
export default defineConfig({
  test: {
    dir: '../../packages',
    include: ['**/*.test.ts'],
  },
})
