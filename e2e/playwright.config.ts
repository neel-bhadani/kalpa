import { defineConfig, devices } from '@playwright/test'

// End-to-end tests against the running stack (make e2e builds the web app first, so the
// offline app shell works). Each test uses two browser contexts as two devices.
export default defineConfig({
  testDir: './tests',
  // One at a time: the tests share the dev database and this machine is small.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: [['list']],
  use: {
    baseURL: process.env.KALPA_URL ?? 'http://localhost:8089',
    trace: 'retain-on-failure',
    serviceWorkers: 'allow',
    testIdAttribute: 'data-test',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
