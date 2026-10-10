import { expect, test } from '@playwright/test'
import { device, notes, onServer, taskIdOf, typeNotes } from './helpers'

test('register, make a task, and resume it exactly on a second device', async ({ browser }) => {
  // Device A: a brand-new account through the sign-up page.
  const email = `e2e-new-${Date.now()}@example.test`
  const password = 'e2e-password-123'
  const a = await browser.newContext()
  const pageA = await a.newPage()
  await pageA.goto('/register')
  await pageA.getByLabel('Name').fill('New Person')
  await pageA.getByLabel('Email').fill(email)
  await pageA.getByLabel('Password').fill(password)
  await pageA.getByRole('button', { name: 'Create account' }).click()
  await expect(pageA.getByText('Welcome, New Person')).toBeVisible()
  await expect(pageA.getByRole('link', { name: 'Create your first project' })).toBeVisible()

  // A project and a task, through the UI.
  await pageA.getByRole('link', { name: 'Create your first project' }).click()
  await pageA.getByPlaceholder('Name').fill('Resume test')
  await pageA.getByRole('button', { name: 'Create' }).click()
  await pageA.getByRole('link', { name: /Resume test/ }).click()
  await pageA.getByPlaceholder('story').fill('story')
  await pageA.getByPlaceholder('outline').fill('write_script')
  await pageA.getByPlaceholder('What needs doing').fill('Draft the opening')
  await pageA.getByRole('button', { name: 'Add task' }).click()
  await expect(pageA.getByText('Draft the opening')).toBeVisible()

  // Open it from the dashboard, type notes, switch to Details and scroll.
  await pageA.locator('a[title="Dashboard"]').click()
  await pageA.getByRole('link', { name: /Draft the opening/ }).first().click()
  await typeNotes(pageA, 'Lanterns flicker on as the night market wakes.')
  await pageA.getByTestId('tab-details').click()
  await pageA.getByTestId('details').evaluate((el) => { el.scrollTop = 300 })
  const url = pageA.url()
  await onServer(a, taskIdOf(pageA), { view: { tab: 'details', scroll: 300 }, data: { notes: 'Lanterns flicker on as the night market wakes.' } })

  // Device B: the same user, its own storage.
  const b = await device(browser, email, password)
  await b.page.goto(url)
  await expect(b.page.getByRole('tab', { name: 'details' })).toHaveAttribute('aria-selected', 'true')
  await expect.poll(() => b.page.getByTestId('details').evaluate(el => el.scrollTop)).toBe(300)
  await b.page.getByTestId('tab-notes').click()
  await expect(notes(b.page)).toHaveValue('Lanterns flicker on as the night market wakes.')

})
