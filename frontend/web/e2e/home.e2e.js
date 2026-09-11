import { expect, test } from '@playwright/test'

test.describe('server-rendered home without JavaScript', () => {
  test.use({ javaScriptEnabled: false })

  test('keeps title, hero, body, and navigation visible without JavaScript', async ({ page }) => {
    await page.goto('/')

    await expect(page).toHaveTitle(/WePlatform/)
    await expect(page.getByRole('heading', { level: 1, name: 'WePlatform' })).toBeVisible()
    await expect(page.getByText('ThinkPHP 8 Web/H5 theme runtime')).toBeVisible()
    await expect(page.locator('[data-nav-panel]')).toBeVisible()
  })
})

test('mobile navigation toggles in a real browser', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')

  const toggle = page.locator('[data-nav-toggle]')
  const navigation = page.locator('[data-nav-panel]')

  await expect(toggle).toHaveAttribute('aria-expanded', 'false')
  await expect(navigation).toBeHidden()

  await toggle.click()
  await expect(toggle).toHaveAttribute('aria-expanded', 'true')
  await expect(navigation).toBeVisible()

  await toggle.click()
  await expect(toggle).toHaveAttribute('aria-expanded', 'false')
  await expect(navigation).toBeHidden()
})
