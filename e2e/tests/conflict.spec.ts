import { expect, test } from '@playwright/test'
import { device, fixtures, newTask, notes, onServer, typeNotes } from './helpers'

test('a conflict, resolved with "Keep mine" and then "Use theirs"', async ({ browser }) => {
  const a = await device(browser, fixtures.owner)
  const b = await device(browser, fixtures.owner)
  const { url, taskId } = await newTask(a.context, `Conflict ${Date.now()}`)
  await a.page.goto(url)
  await b.page.goto(url)
  await typeNotes(a.page, 'Start.')
  await onServer(a.context, taskId, { data: { notes: 'Start.' } })
  await expect(notes(b.page)).toHaveValue('Start.')

  // A is offline while both change the notes; B's edit reaches the server first.
  async function clash(fromA: string, fromB: string, serverNotes: string) {
    await a.context.setOffline(true)
    await typeNotes(b.page, fromB)
    await onServer(b.context, taskId, { data: { notes: serverNotes } })
    await typeNotes(a.page, fromA)
    await expect(a.page.getByTestId('sync-pill')).toHaveText(/Offline \(1 waiting\)/, { timeout: 20_000 })
    await a.context.setOffline(false)
    await expect(a.page.getByTestId('conflict')).toBeVisible({ timeout: 20_000 })
  }

  // Keep mine: A's version wins on both devices.
  await clash(' A1', ' B1', 'Start. B1')
  await expect(a.page.getByTestId('conflict-mine')).toContainText('Start. A1')
  await expect(a.page.getByTestId('conflict-theirs')).toContainText('Start. B1')
  await a.page.getByTestId('keep-mine').click()
  await expect(a.page.getByTestId('conflict')).toBeHidden()
  await onServer(a.context, taskId, { data: { notes: 'Start. A1' } })
  await expect(notes(b.page)).toHaveValue('Start. A1', { timeout: 20_000 })

  // Use theirs: A drops its edit and shows B's.
  await clash(' A2', ' B2', 'Start. A1 B2')
  await a.page.getByTestId('use-theirs').click()
  await expect(a.page.getByTestId('conflict')).toBeHidden()
  await expect(notes(a.page)).toHaveValue('Start. A1 B2')
  await expect(notes(b.page)).toHaveValue('Start. A1 B2')
  await onServer(a.context, taskId, { data: { notes: 'Start. A1 B2' } })
})
