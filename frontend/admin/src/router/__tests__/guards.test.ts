import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

import {
  createAdminAuthGuard,
  type AdminAuthGuardStore,
} from '../guards'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: { template: '<div />' },
    meta: { requiresAuth: true },
  },
  {
    path: '/login',
    name: 'login',
    component: { template: '<div />' },
  },
  {
    path: '/private',
    name: 'private',
    component: { template: '<div />' },
    meta: { requiresAuth: true },
  },
]

function makeRouter(auth: AdminAuthGuardStore) {
  const router = createRouter({
    history: createMemoryHistory('/admin/'),
    routes,
  })
  router.beforeEach(createAdminAuthGuard(auth))
  return router
}

describe('admin auth router guard', () => {
  it('redirects an anonymous protected navigation to login with the external admin return path', async () => {
    const auth: AdminAuthGuardStore = {
      status: 'anonymous',
      restore: vi.fn(),
    }
    const router = makeRouter(auth)

    await router.push('/private?tab=account')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/admin/private?tab=account')
  })

  it('redirects an authenticated login navigation to the admin home route', async () => {
    const auth: AdminAuthGuardStore = {
      status: 'authenticated',
      restore: vi.fn(),
    }
    const router = makeRouter(auth)

    await router.push('/login')

    expect(router.currentRoute.value.name).toBe('home')
    expect(router.resolve(router.currentRoute.value).href).toBe('/admin/')
  })

  it('restores unknown auth state exactly once before deciding the navigation', async () => {
    const auth: AdminAuthGuardStore = {
      status: 'unknown',
      restore: vi.fn(async () => {
        auth.status = 'authenticated'
      }),
    }
    const router = makeRouter(auth)

    await router.push('/private')

    expect(auth.restore).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('private')
  })

  it('keeps login public for an anonymous administrator', async () => {
    const auth: AdminAuthGuardStore = {
      status: 'anonymous',
      restore: vi.fn(),
    }
    const router = makeRouter(auth)

    await router.push('/login')

    expect(router.currentRoute.value.name).toBe('login')
    expect(auth.restore).not.toHaveBeenCalled()
  })
})
