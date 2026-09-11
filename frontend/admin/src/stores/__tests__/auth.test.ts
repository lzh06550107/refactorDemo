import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as authApi from '../../api/auth'
import { useAdminAuthStore } from '../auth'

vi.mock('../../api/auth', () => ({
  bootstrapCsrf: vi.fn(),
  login: vi.fn(),
  me: vi.fn(),
  logout: vi.fn(),
}))

const mockedBootstrapCsrf = vi.mocked(authApi.bootstrapCsrf)
const mockedLogin = vi.mocked(authApi.login)
const mockedMe = vi.mocked(authApi.me)
const mockedLogout = vi.mocked(authApi.logout)

describe('admin auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('restores an authenticated administrator', async () => {
    mockedMe.mockResolvedValue({ id: 'admin-1', username: 'root' })
    const store = useAdminAuthStore()

    await store.restore()

    expect(store.status).toBe('authenticated')
    expect(store.user).toEqual({ id: 'admin-1', username: 'root' })
  })

  it('treats restore 401 as anonymous instead of an application crash', async () => {
    mockedMe.mockRejectedValue({ response: { status: 401 } })
    const store = useAdminAuthStore()

    await expect(store.restore()).resolves.toBeUndefined()

    expect(store.status).toBe('anonymous')
    expect(store.user).toBeNull()
  })

  it('bootstraps csrf before successful login', async () => {
    mockedBootstrapCsrf.mockResolvedValue(undefined)
    mockedLogin.mockResolvedValue({ id: 'admin-1', username: 'root' })
    const store = useAdminAuthStore()

    const user = await store.signIn('root', 'correct-password')

    expect(mockedBootstrapCsrf).toHaveBeenCalledTimes(1)
    expect(mockedLogin).toHaveBeenCalledWith('root', 'correct-password')
    expect(user).toEqual({ id: 'admin-1', username: 'root' })
    expect(store.status).toBe('authenticated')
    expect(store.user).toEqual(user)
  })

  it('clears in-memory auth state when login fails', async () => {
    mockedBootstrapCsrf.mockResolvedValue(undefined)
    mockedLogin.mockRejectedValue(new Error('invalid credentials'))
    const store = useAdminAuthStore()

    await expect(store.signIn('root', 'wrong-password')).rejects.toThrow('invalid credentials')

    expect(store.status).toBe('anonymous')
    expect(store.user).toBeNull()
  })

  it('logs out through the server and clears in-memory auth state', async () => {
    mockedLogout.mockResolvedValue(undefined)
    const store = useAdminAuthStore()
    store.$patch({
      status: 'authenticated',
      user: { id: 'admin-1', username: 'root' },
    })

    await store.signOut()

    expect(mockedLogout).toHaveBeenCalledTimes(1)
    expect(store.status).toBe('anonymous')
    expect(store.user).toBeNull()
  })

  it('stores no browser session token in Pinia state', () => {
    const store = useAdminAuthStore()

    expect(Object.keys(store.$state).sort()).toEqual(['status', 'user'])
    expect(JSON.stringify(store.$state).toLowerCase()).not.toContain('token')
  })

  it('does not persist auth material to Web Storage', () => {
    const productionSources = import.meta.glob(
      ['../../api/*.ts', '../*.ts'],
      { eager: true, import: 'default', query: '?raw' },
    ) as Record<string, string>

    const source = Object.values(productionSources).join('\n')
    expect(source).not.toMatch(/(?:localStorage|sessionStorage)\.setItem\s*\(/)
  })
})
