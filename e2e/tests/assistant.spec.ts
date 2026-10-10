import { expect, test, type Page } from '@playwright/test'
import { device, fixtures, newTask, notes } from './helpers'

// Pick a mode once the session has loaded (the switch does nothing before that).
async function mode(page: Page, key: 'auto' | 'semi' | 'manual') {
  await expect(page.locator('[role="radio"][aria-checked="true"]')).toHaveCount(1)
  await page.getByTestId(`mode-${key}`).click()
  await expect(page.getByTestId(`mode-${key}`)).toHaveAttribute('aria-checked', 'true')
}

async function ask(page: Page, text: string) {
  await page.getByTestId('assistant-input').fill(text)
  await page.getByTestId('send').click()
}

test('the assistant: Semi waits for approval and can undo; Auto runs alone', async ({ browser }) => {
  const a = await device(browser, fixtures.owner)
  const b = await device(browser, fixtures.owner)
  const { url } = await newTask(a.context, `Assistant ${Date.now()}`)
  await a.page.goto(url)
  await b.page.goto(url)
  const steps = a.page.getByTestId('step')

  // Semi: three steps wait for review (and show up on the other device too).
  await mode(a.page, 'semi')
  await ask(a.page, 'demo')
  await expect(steps).toHaveCount(3)
  await expect(a.page.getByTestId('step-status')).toHaveText(['awaiting review', 'awaiting review', 'awaiting review'])
  await expect(b.page.getByTestId('step')).toHaveCount(3)

  // Approve them one by one; each change shows up in the editor.
  await a.page.getByTestId('approve').first().click()
  await expect(a.page.getByRole('tab', { name: 'details' })).toHaveAttribute('aria-selected', 'true')
  await a.page.getByTestId('tab-notes').click()
  await a.page.getByTestId('approve').first().click()
  await expect(a.page.getByTestId('added')).toContainText('Scene 1')
  await a.page.getByTestId('approve').first().click()
  await expect(notes(a.page)).toHaveValue(/Scene 1: .*\nScene 2: /)
  await expect(a.page.getByTestId('step-status')).toHaveText(['done', 'done', 'done'])

  // Undo the last one.
  await a.page.getByTestId('undo').last().click()
  await expect(a.page.getByTestId('step-status').last()).toHaveText('undone')
  await expect(notes(a.page)).not.toHaveValue(/Scene 2/)

  // Auto: it runs on its own, one step at a time.
  await mode(a.page, 'auto')
  await ask(a.page, 'demo')
  let mostRunning = 0
  for (let i = 0; i < 60; i++) {
    const statuses = await a.page.getByTestId('step-status').allTextContents()
    mostRunning = Math.max(mostRunning, statuses.filter(status => status.trim() === 'running').length)
    if (statuses.length === 6 && statuses.slice(3).every(status => status.trim() === 'done')) break
    await a.page.waitForTimeout(100)
  }
  await expect(a.page.getByTestId('step-status')).toHaveText(['done', 'done', 'undone', 'done', 'done', 'done'])
  expect(mostRunning).toBeLessThanOrEqual(1)
  await expect(b.page.getByTestId('step')).toHaveCount(6)
})
