import { expect, test } from '@playwright/test'

const username = 'e2e-admin'
const password = process.env.WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD

test('bootstrapped administrator can login, restore the session after reload, and logout', async ({ page }) => {
  expect(password, 'WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD must be available to the browser gate').toBeTruthy()

  await page.goto('/admin/login')
  await expect(page).toHaveURL(/\/admin\/login$/)
  await expect(page.getByRole('heading', { name: 'WePlatform Admin' })).toBeVisible()

  await page.locator('input[name="username"]').fill(username)
  await page.locator('input[name="password"]').fill(password)
  await page.getByRole('button', { name: '登录' }).click()

  await expect(page).toHaveURL(/\/admin\/?$/)
  await expect(page.getByText('后台运行正常')).toBeVisible()
  await expect(page.getByText(username, { exact: true })).toBeVisible()

  await page.reload()
  await expect(page).toHaveURL(/\/admin\/?$/)
  await expect(page.getByText('后台运行正常')).toBeVisible()
  await expect(page.getByText(username, { exact: true })).toBeVisible()

  await page.getByRole('button', { name: '退出登录' }).click()
  await expect(page).toHaveURL(/\/admin\/login$/)
  await expect(page.getByRole('button', { name: '登录' })).toBeVisible()

  await page.reload()
  await expect(page).toHaveURL(/\/admin\/login$/)
  await expect(page.getByRole('button', { name: '登录' })).toBeVisible()
})
