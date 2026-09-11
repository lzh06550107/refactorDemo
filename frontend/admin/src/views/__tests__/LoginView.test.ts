import ElementPlus from 'element-plus'
import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

import { useAdminAuthStore } from '../../stores/auth'
import LoginView from '../LoginView.vue'

async function mountLogin(redirect?: string) {
  const pinia = createPinia()
  const router = createRouter({
    history: createMemoryHistory('/admin/'),
    routes: [
      { path: '/', name: 'home', component: { template: '<div>home</div>' } },
      { path: '/login', name: 'login', component: LoginView },
      { path: '/private', name: 'private', component: { template: '<div>private</div>' } },
    ],
  })
  await router.push({
    path: '/login',
    query: redirect ? { redirect } : undefined,
  })
  await router.isReady()

  const wrapper = mount(LoginView, {
    global: {
      plugins: [pinia, router, ElementPlus],
    },
  })
  const auth = useAdminAuthStore(pinia)

  return { wrapper, router, auth }
}

async function fillCredentials(wrapper: ReturnType<typeof mount>) {
  await wrapper.find('input[name="username"]').setValue('root')
  await wrapper.find('input[name="password"]').setValue('correct-password')
}

describe('LoginView', () => {
  it('requires both username and password before calling signIn', async () => {
    const { wrapper, auth } = await mountLogin()
    const signIn = vi.spyOn(auth, 'signIn').mockResolvedValue({ id: 'admin-1', username: 'root' })

    await wrapper.find('form').trigger('submit')

    expect(signIn).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('请输入用户名和密码')
  })

  it('submits credentials and follows a validated internal admin redirect', async () => {
    const { wrapper, router, auth } = await mountLogin('/admin/private?tab=account')
    const signIn = vi.spyOn(auth, 'signIn').mockResolvedValue({ id: 'admin-1', username: 'root' })
    await fillCredentials(wrapper)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(signIn).toHaveBeenCalledWith('root', 'correct-password')
    expect(router.currentRoute.value.name).toBe('private')
    expect(router.currentRoute.value.query.tab).toBe('account')
  })

  it('shows one generic public message for invalid credentials', async () => {
    const { wrapper, auth } = await mountLogin()
    vi.spyOn(auth, 'signIn').mockRejectedValue(new Error('backend detail must stay private'))
    await fillCredentials(wrapper)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('用户名或密码错误')
    expect(wrapper.text()).not.toContain('backend detail must stay private')
  })

  it.each([
    'https://evil.example/steal',
    '//evil.example/steal',
    '/public',
    '/administrator',
  ])('rejects unsafe post-login redirect %s', async (redirect) => {
    const { wrapper, router, auth } = await mountLogin(redirect)
    vi.spyOn(auth, 'signIn').mockResolvedValue({ id: 'admin-1', username: 'root' })
    await fillCredentials(wrapper)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('prevents duplicate submissions while login is pending', async () => {
    const { wrapper, auth } = await mountLogin()
    let resolveLogin!: (value: { id: string; username: string }) => void
    const signIn = vi.spyOn(auth, 'signIn').mockImplementation(
      () => new Promise((resolve) => {
        resolveLogin = resolve
      }),
    )
    await fillCredentials(wrapper)

    void wrapper.find('form').trigger('submit')
    await flushPromises()
    void wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(signIn).toHaveBeenCalledTimes(1)

    resolveLogin({ id: 'admin-1', username: 'root' })
    await flushPromises()
  })
})
