import { expect, test } from '@playwright/test'
import { device, fixtures, newTask, notes, onServer, typeNotes } from './helpers'

test('edit offline, reload offline, come back online: the other device updates', async ({ browser }) => {
  const a = await device(browser, fixtures.owner)
  const b = await device(browser, fixtures.owner)
  const { url, taskId } = await newTask(a.context, `Offline ${Date.now()}`)

  await a.page.goto(url)
  // Let the service worker take over, so the app shell can load offline.
  await a.page.evaluate(() => navigator.serviceWorker.ready)
  await a.page.reload()
  await expect.poll(() => a.page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBe(true)
  await typeNotes(a.page, 'Online line.')
  await onServer(a.context, taskId, { data: { notes: 'Online line.' } })

  await b.page.goto(url)
  await expect(notes(b.page)).toHaveValue('Online line.')

  // A goes offline and keeps writing.
  await a.context.setOffline(true)
  await typeNotes(a.page, '\nWritten offline.')
  await expect(a.page.getByTestId('sync-pill')).toHaveText(/Offline \(1 waiting\)/, { timeout: 20_000 })

  // Reload while still offline: the app and the notes come back from this device.
  await a.page.reload()
  await expect(notes(a.page)).toHaveValue('Online line.\nWritten offline.')
  await expect(a.page.getByTestId('unsaved')).toBeVisible()

  // The dashboard, still offline, shows the task as Unsaved.
  await a.page.locator('a[title="Dashboard"]').click()
  await expect(a.page.getByTestId('offline-note')).toBeVisible()
  await expect(a.page.getByRole('link', { name: /Offline \d+/ }).getByTestId('unsaved').first()).toBeVisible()

  // Back online: it syncs, and B updates without a reload.
  await a.context.setOffline(false)
  await expect(a.page.getByTestId('sync-pill')).toHaveText('Synced', { timeout: 20_000 })
  await onServer(b.context, taskId, { data: { notes: 'Online line.\nWritten offline.' } })
  await expect(notes(b.page)).toHaveValue('Online line.\nWritten offline.', { timeout: 20_000 })
})
