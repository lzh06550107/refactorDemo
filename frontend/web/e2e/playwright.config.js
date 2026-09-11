import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: '.',
  testMatch: 'home.spec.js',
  fullyParallel: false,
  workers: 1,
  reporter: process.env.CI ? 'line' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:18080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'php ../../../think run -p 18080',
    url: 'http://127.0.0.1:18080/health',
    reuseExistingServer: !process.env.CI,
    timeout: 120000,
    stdout: 'pipe',
    stderr: 'pipe',
    env: {
      ...process.env,
      PHP_WEPLATFORM_ADMIN_SESSION_PEPPER: 'ci-admin-session-pepper',
      PHP_WEPLATFORM_OPENPLATFORM_AUTHORIZATION_CALLBACK_URI: 'https://example.com/api/v1/openplatform/authorization/callback',
      PHP_WEPLATFORM_OPENPLATFORM_HTTP_TIMEOUT_SECONDS: '10',
      PHP_WEPLATFORM_OPENPLATFORM_SECRET_KEY_VERSION: 'v1',
      PHP_WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64: 'a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=',
      PHP_WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON: '{"ci/ref":"ci-secret"}',
    },
  },
})
