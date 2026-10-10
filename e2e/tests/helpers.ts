import { readFileSync } from 'node:fs'
import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test'

// Users made by `php artisan e2e:fixtures` for this run (see make e2e).
export type Fixtures = {
  password: string
  owner: string
  viewer: string
  stranger: string
  task: { id: string; module: string; title: string }
}

export const fixtures: Fixtures = JSON.parse(readFileSync(new URL('../.fixtures.json', import.meta.url), 'utf8'))

export async function login(page: Page, email: string, password = fixtures.password) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Log in' }).click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByText(/^Welcome, /)).toBeVisible()
}

// Session cookies per user. Sign-in is limited to 5 a minute per email, so each user logs in
// through the page once; later devices reuse only the cookies (not localStorage or IndexedDB),
// so each one is still its own device with its own device id and offline copy.
const sessions = new Map<string, Awaited<ReturnType<BrowserContext['cookies']>>>()

// A signed-in "device": its own browser context (own storage, own service worker).
export async function device(browser: Browser, email: string, password = fixtures.password) {
  const context = await browser.newContext()
  const page = await context.newPage()
  const cookies = sessions.get(email)
  if (cookies) {
    await context.addCookies(cookies)
    await page.goto('/')
    await expect(page.getByText(/^Welcome, /)).toBeVisible()
  } else {
    await login(page, email, password)
    sessions.set(email, await context.cookies())
  }
  return { context, page }
}

// Calls the API with the context's session (like the app does: CSRF and workspace headers).
export function api(context: BrowserContext) {
  let workspaceId: string | null = null

  async function call<T = any>(method: string, path: string, body?: unknown): Promise<{ status: number; data: T }> {
    const xsrf = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN')?.value
    if (!workspaceId && path !== '/api/v1/me') {
      const me = await call('GET', '/api/v1/me')
      if (!me.data?.data?.workspaces) throw new Error(`GET /api/v1/me: ${me.status}`)
      workspaceId = me.data.data.workspaces[0].id
    }
    const response = await context.request.fetch(path, {
      method,
      data: body,
      headers: {
        'Accept': 'application/json',
        // Sanctum uses the session cookie only for requests from the app's own origin.
        'Referer': `${process.env.KALPA_URL ?? 'http://localhost:8089'}/`,
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        ...(workspaceId ? { 'X-Workspace-Id': workspaceId } : {}),
      },
    })
    return { status: response.status(), data: await response.json().catch(() => null) }
  }

  return call
}

// A fresh project and task for one test, so tests don't affect each other.
export async function newTask(context: BrowserContext, title: string, module = 'story', kind = 'write_script') {
  const call = api(context)
  const project = (await call('POST', '/api/v1/projects', { name: `${title} project` })).data.data
  const task = (await call('POST', `/api/v1/projects/${project.id}/tasks`, { module, kind, title })).data.data
  return { projectId: project.id as string, taskId: task.id as string, url: `/m/${module}/tasks/${task.id}` }
}

export function notes(page: Page) {
  return page.getByTestId('notes')
}

// Type at the end of the notes, the way a person does.
export async function typeNotes(page: Page, text: string) {
  await page.getByTestId('tab-notes').click()
  await notes(page).click()
  await page.keyboard.press('End')
  await page.keyboard.press('Control+End')
  await page.keyboard.type(text)
}

// Wait until the server has the task's checkpoint (the editor sends 2 s after the last change).
export async function onServer(context: BrowserContext, taskId: string, expected: Record<string, unknown>) {
  const call = api(context)
  await expect.poll(async () => (await call('GET', `/api/v1/tasks/${taskId}`)).data?.data?.checkpoint ?? null, { timeout: 20_000 })
    .toMatchObject(expected)
}

export function taskIdOf(page: Page): string {
  return page.url().split('/').pop()!
}
