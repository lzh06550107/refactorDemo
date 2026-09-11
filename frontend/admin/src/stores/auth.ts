import { defineStore } from 'pinia'

import {
  bootstrapCsrf,
  login,
  logout,
  me,
  type AdminUser,
} from '../api/auth'

export type AdminAuthStatus = 'unknown' | 'authenticated' | 'anonymous'

export const useAdminAuthStore = defineStore('adminAuth', {
  state: (): { user: AdminUser | null; status: AdminAuthStatus } => ({
    user: null,
    status: 'unknown',
  }),

  actions: {
    async restore(): Promise<void> {
      try {
        this.user = await me()
        this.status = 'authenticated'
      } catch (error) {
        const status = (error as { response?: { status?: number } }).response?.status
        if (status === 401) {
          this.user = null
          this.status = 'anonymous'
          return
        }

        throw error
      }
    },

    async signIn(username: string, password: string): Promise<AdminUser> {
      try {
        await bootstrapCsrf()
        const user = await login(username, password)
        this.user = user
        this.status = 'authenticated'
        return user
      } catch (error) {
        this.user = null
        this.status = 'anonymous'
        throw error
      }
    },

    async signOut(): Promise<void> {
      await logout()
      this.user = null
      this.status = 'anonymous'
    },
  },
})
