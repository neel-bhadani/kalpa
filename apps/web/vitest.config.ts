import { defineConfig } from 'vitest/config'

// Unit tests for the shared packages (packages/*), run from the web container.
export default defineConfig({
  // Files in packages/ import these too; resolve them from apps/web/node_modules.
  resolve: { dedupe: ['idb', 'fake-indexeddb', 'fast-check', '@wllama/wllama', '@mlc-ai/web-llm', 'prosemirror-model', 'prosemirror-state', 'prosemirror-view', 'prosemirror-keymap', 'prosemirror-history', 'prosemirror-commands', 'prosemirror-transform'] },
  test: {
    dir: '../../packages',
    include: ['**/*.test.ts'],
  },
})
