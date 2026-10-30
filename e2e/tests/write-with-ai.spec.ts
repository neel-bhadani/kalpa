import { expect, test } from '@playwright/test'
import { device, fixtures, newTask } from './helpers'

test('Write with AI in Semi: approve each stage, scenes appear, undo one AI step, restore', async ({ browser }) => {
  test.setTimeout(120_000)
  const { page } = await device(browser, fixtures.owner)
  const { url } = await newTask(page.context(), `Write with AI ${Date.now()}`, 'story', 'write_script')
  await page.goto(url)

  // The scripted Story brain (local env only).
  await page.getByTestId('brain-picker').selectOption('fake')
  await expect(page.getByTestId('brain-status')).toContainText('Test brain')

  // A new script opens with the "Write with AI" box.
  await page.getByTestId('write-brief').fill('A 3-minute short film about a girl in Surat who fixes old radios')
  await page.getByTestId('write-minutes').fill('3')
  await page.getByTestId('write-mode').selectOption('semi')
  await page.getByTestId('write-start').click()

  // Semi: the Brief stage stops with its step waiting, and a reason on it.
  const stage = (label: string) => page.getByTestId('stage').filter({ hasText: label })
  await expect(stage('Brief')).toHaveAttribute('data-state', 'review')
  await expect(page.getByTestId('step').first()).toContainText('Set up the brief')
  await expect(page.getByTestId('step-reason').first()).toHaveText('Setting up a warm family drama from your brief')
  await expect(page.getByTestId('step-status').first()).toHaveText('awaiting review')

  // Approve stage after stage until the plan is done.
  for (let i = 0; i < 10; i++) {
    const approve = page.getByTestId('approve-stage')
    const done = page.getByTestId('stage-bar').getByText('Done')
    await expect(approve.or(done)).toBeVisible({ timeout: 20_000 })
    if (await done.isVisible()) break
    await approve.click()
    // Hidden while the stage's steps run and the next stage is written.
    await expect(approve).toBeHidden()
  }
  await expect(page.getByTestId('stage-bar').getByText('Done')).toBeVisible()
  await expect(page.getByTestId('stage')).toHaveText(['Brief', 'Logline', 'Characters', 'Outline', 'Scene list', 'Draft scenes', 'Polish dialogue', 'Review'])

  // The scenes are in the outline and on the page, drafted.
  await expect(page.getByTestId('outline-scene')).toHaveCount(2)
  await expect(page.locator('.sp-page .sp-dialogue').first()).toHaveText('One valve. That\'s all, Dada.')
  await expect(page.getByTestId('story-character')).toHaveCount(2)
  await expect(page.getByTestId('version-indicator')).toHaveText(/^v\d+ · saved$/)

  // Undo one AI step: the polish of Scene 1.
  const polish = page.getByTestId('step').filter({ hasText: 'Polished dialogue in Scene 1' })
  await polish.getByTestId('undo').click()
  await expect(polish.getByTestId('step-status')).toHaveText('undone')
  await expect(page.locator('.sp-page .sp-dialogue').first()).toHaveText('It only needs one valve, Dada.')

  // Each AI step is its own version; restore the one before the drafts.
  await page.getByTestId('version-indicator').click()
  await expect(page.getByTestId('version').filter({ hasText: 'AI: Drafted Scene 1' })).toHaveCount(1)
  await expect(page.getByTestId('version').filter({ hasText: 'Undid AI: Polished dialogue in Scene 1' })).toHaveCount(1)
  await page.getByTestId('version').filter({ hasText: 'AI: Drafted Scene 1' }).getByTestId('restore').click()
  await expect(page.locator('.sp-page .sp-el')).not.toHaveCount(0)
  await expect(page.getByTestId('outline-scene')).toHaveCount(2)
  // Scene 2 is back to undrafted: its heading is followed by the end of the page.
  await expect(page.locator('.sp-page .sp-heading').nth(1)).toBeVisible()
  await expect(page.locator('.sp-page .sp-heading').nth(1).locator('xpath=following-sibling::*')).toHaveCount(0)
})
