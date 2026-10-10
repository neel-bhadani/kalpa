import { expect, test } from '@playwright/test'
import { api, device, fixtures } from './helpers'

test('a viewer can look but not change anything', async ({ browser }) => {
  const { page, context } = await device(browser, fixtures.viewer)
  const { task } = fixtures

  await page.goto(`/m/${task.module}/tasks/${task.id}`)
  await expect(page.getByText(task.title).first()).toBeVisible()
  await expect(page.getByTestId('notes')).toHaveAttribute('readonly', '')
  await expect(page.getByTestId('read-only')).toBeVisible()
  // The assistant panel is open by default; a viewer gets no input box.
  await expect(page.getByTestId('assistant')).toBeVisible()
  await expect(page.getByTestId('assistant-input')).toHaveCount(0)

  // The API refuses changes too.
  const call = api(context)
  expect((await call('PATCH', `/api/v1/tasks/${task.id}`, { title: 'Hacked' })).status).toBe(403)
  expect((await call('PUT', `/api/v1/tasks/${task.id}/checkpoint`, { base_version: 1, checkpoint: { data: { notes: 'x' } } })).status).toBe(403)
})

test('another workspace sees nothing', async ({ browser }) => {
  const { page, context } = await device(browser, fixtures.stranger)
  const { task } = fixtures

  // Not on the dashboard, and not by id.
  await expect(page.getByText(task.title)).toHaveCount(0)
  const call = api(context)
  // Refused (403) or not found (404), never the task.
  expect([403, 404]).toContain((await call('GET', `/api/v1/tasks/${task.id}`)).status)
  expect([403, 404]).toContain((await call('GET', `/api/v1/tasks/${task.id}/ai/sessions`)).status)
  const pulled = (await call('GET', '/api/v1/sync')).data.data as { entity_id: string }[]
  expect(pulled.map(change => change.entity_id)).not.toContain(task.id)

  await page.goto(`/m/${task.module}/tasks/${task.id}`)
  await expect(page.getByText(task.title)).toHaveCount(0)
})
