import { expect, test } from '@playwright/test'
import { device, fixtures } from './helpers'

test('upload an image: the asset appears, then its thumbnail', async ({ browser }) => {
  const { page } = await device(browser, fixtures.owner)
  await page.goto('/assets')

  // A real 1200×800 PNG, drawn in the browser.
  const png = await page.evaluate(async () => {
    const canvas = document.createElement('canvas')
    canvas.width = 1200
    canvas.height = 800
    const g = canvas.getContext('2d')!
    const gradient = g.createLinearGradient(0, 0, 1200, 800)
    gradient.addColorStop(0, '#8B7CFF')
    gradient.addColorStop(1, '#45E3FF')
    g.fillStyle = gradient
    g.fillRect(0, 0, 1200, 800)
    g.fillStyle = '#07080C'
    g.fillText(String(Date.now()), 40, 760) // unique bytes every run (no dedupe)
    const blob: Blob = await new Promise(resolve => canvas.toBlob(b => resolve(b!), 'image/png'))
    return Array.from(new Uint8Array(await blob.arrayBuffer()))
  })
  const name = `e2e-poster-${Date.now()}.png`
  await page.getByTestId('file-input').setInputFiles({ name, mimeType: 'image/png', buffer: Buffer.from(png) })

  await expect(page.getByTestId('upload-item').first()).toContainText('Asset created', { timeout: 30_000 })
  const card = page.getByTestId('asset-card').filter({ hasText: name })
  await expect(card).toBeVisible()

  // The thumbnail (512 px WebP from the file.thumbnail job) replaces the full image, live.
  await expect.poll(() => card.locator('img').evaluate((img: HTMLImageElement) => img.complete ? img.naturalWidth : 0), { timeout: 30_000 }).toBe(512)
})
