import type { NavigationGuard, RouteLocationNormalized } from 'vue-router'

import { useAdminAuthStore, type AdminAuthStatus } from '../stores/auth'

export type AdminAuthGuardStore = {
  status: AdminAuthStatus
  restore(): Promise<void>
}

function externalAdminPath(fullPath: string): string {
  return fullPath === '/' ? '/admin' : `/admin${fullPath}`
}

async function decideNavigation(
  auth: AdminAuthGuardStore,
  to: RouteLocationNormalized,
) {
  if (auth.status === 'unknown') {
    await auth.restore()
  }

  if (to.name === 'login' && auth.status === 'authenticated') {
    return { path: '/' }
  }

  if (to.meta.requiresAuth && auth.status !== 'authenticated') {
    return {
      name: 'login',
      query: { redirect: externalAdminPath(to.fullPath) },
    }
  }

  return true
}

export function createAdminAuthGuard(auth: AdminAuthGuardStore): NavigationGuard {
  return (to) => decideNavigation(auth, to)
}

export const adminAuthGuard: NavigationGuard = (to) => decideNavigation(useAdminAuthStore(), to)
