import { expect, test } from '@playwright/test'
import { device, fixtures, newTask } from './helpers'

test('"Plan with Producer" makes 3 linked tasks on the dashboard', async ({ browser }) => {
  const { page, context } = await device(browser, fixtures.owner)
  const { projectId } = await newTask(context, `Producer ${Date.now()}`)
  const goal = `a short ${Date.now()}`

  await page.goto(`/projects/${projectId}`)
  await page.getByLabel('Goal').fill(goal)
  await page.getByTestId('plan').click()
  await expect(page.getByTestId('planned')).toContainText('Write the script → Design the main character → Record the dialogue')

  await page.locator('a[title="Dashboard"]').click()
  // Rows in the task list (the "Continue" cards above are links too).
  const rows = page.locator('li').getByRole('link', { name: new RegExp(goal) })
  await expect(rows).toHaveCount(3)
  await expect(rows.and(page.locator('a[href^="/m/voice/"]'))).toContainText(`after: Design the main character: ${goal}`)
  await expect(rows.and(page.locator('a[href^="/m/character/"]'))).toContainText(`after: Write the script: ${goal}`)
})
