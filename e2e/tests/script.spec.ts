import { expect, test, type Page } from '@playwright/test'
import { device, fixtures, newTask } from './helpers'

// The screenplay page's elements, as "type: text".
async function elements(page: Page) {
  return page.locator('.sp-page .sp-el').evaluateAll(nodes => nodes.map((node) => {
    const type = [...node.classList].find(name => name.startsWith('sp-') && !['sp-el', 'sp-current', 'sp-empty'].includes(name))!.slice(3)
    return `${type}: ${node.textContent}`
  }))
}

test('write a scene, reload, and restore an older version', async ({ browser }) => {
  const { page } = await device(browser, fixtures.owner)
  const { url } = await newTask(page.context(), `Script ${Date.now()}`, 'story', 'write_script')
  await page.goto(url)
  await expect(page.getByTestId('version-indicator')).toHaveText('v1 · saved')

  // The new script's empty scene heading has the cursor.
  await page.locator('.sp-page .sp-heading').first().click()
  await page.keyboard.type('int. night market - night')
  await page.keyboard.press('Enter')
  await page.keyboard.type('Lanterns flicker on, one by one.')
  // After action: Enter, then Tab on the empty line makes it a character; after the character comes dialogue.
  await page.keyboard.press('Enter')
  await page.keyboard.press('Tab')
  await page.keyboard.type('Mira')
  await page.keyboard.press('Enter')
  await page.keyboard.type('Late again.')
  // A second scene, typed as "EXT." on an action line.
  await page.keyboard.press('Enter')
  await page.keyboard.press('Enter')
  await page.keyboard.type('EXT. ALLEY - NIGHT')

  const written = [
    'heading: INT. NIGHT MARKET - NIGHT',
    'action: Lanterns flicker on, one by one.',
    'character: MIRA',
    'dialogue: Late again.',
    'heading: EXT. ALLEY - NIGHT',
  ]
  // As typed; the script itself is in capitals (heading and character), which the page shows after a reload.
  await expect.poll(() => elements(page)).toEqual([
    'heading: int. night market - night',
    'action: Lanterns flicker on, one by one.',
    'character: Mira',
    'dialogue: Late again.',
    'heading: EXT. ALLEY - NIGHT',
  ])
  await expect(page.getByTestId('outline-scene')).toHaveCount(2)
  await expect(page.getByTestId('version-indicator')).toHaveText('unsaved changes')

  // Ctrl+S makes version 2, with a message saying what changed.
  await page.keyboard.press('Control+s')
  await expect(page.getByTestId('version-indicator')).toHaveText('v2 · saved')

  // Reload: the same script, and the cursor back in the second scene.
  await page.reload()
  await expect.poll(() => elements(page)).toEqual(written)
  await expect(page.locator('[data-test=outline-scene][aria-current=location]')).toContainText('EXT. ALLEY - NIGHT')
  await page.keyboard.type(' (LATER)')
  await expect(page.locator('.sp-page .sp-heading').nth(1)).toHaveText('EXT. ALLEY - NIGHT (LATER)')
  await page.keyboard.press('Control+s')
  await expect(page.getByTestId('version-indicator')).toHaveText('v3 · saved')

  // Restore v1: the empty script is back, as version 4.
  await page.getByTestId('version-indicator').click()
  await expect(page.getByTestId('version')).toHaveCount(3)
  await expect(page.getByTestId('version').nth(1)).toContainText('Added Scene 2')
  await page.getByTestId('version').filter({ hasText: 'v1' }).getByTestId('restore').click()
  await expect(page.getByTestId('version-indicator')).toHaveText('v4 · saved')
  await expect.poll(() => elements(page)).toEqual(['heading: '])

  // And back to v3.
  await page.getByTestId('version').filter({ hasText: 'v3' }).getByTestId('restore').click()
  await expect(page.getByTestId('version-indicator')).toHaveText('v5 · saved')
  await expect(page.locator('.sp-page .sp-heading').nth(1)).toHaveText('EXT. ALLEY - NIGHT (LATER)')
})
