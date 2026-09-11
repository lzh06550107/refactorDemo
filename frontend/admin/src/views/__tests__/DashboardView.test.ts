import ElementPlus from 'element-plus'
import { createPinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { fetchDashboardSummary } from '../../api/dashboard'
import AdminLayout from '../../layouts/AdminLayout.vue'
import { useAdminAuthStore } from '../../stores/auth'
import DashboardView from '../DashboardView.vue'

vi.mock('../../api/dashboard', () => ({
  fetchDashboardSummary: vi.fn(),
}))

const mockedFetchDashboardSummary = vi.mocked(fetchDashboardSummary)

async function layoutHarness() {
  const pinia = createPinia()
  const router = createRouter({
    history: createMemoryHistory('/admin/'),
    routes: [
      {
        path: '/',
        component: AdminLayout,
        children: [
          { path: '', name: 'dashboard', component: DashboardView },
        ],
      },
      { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
    ],
  })
  const auth = useAdminAuthStore(pinia)
  auth.user = { id: 'admin-layout-1', username: 'root' }
  auth.status = 'authenticated'
  await router.push('/')
  await router.isReady()

  const wrapper = mount(AdminLayout, {
    global: {
      plugins: [pinia, router, ElementPlus],
      stubs: {
        RouterView: { template: '<div data-test="router-view">router child</div>' },
      },
    },
  })

  return { wrapper, auth, router }
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('AdminLayout', () => {
  it('renders the authenticated admin shell contract', async () => {
    const { wrapper } = await layoutHarness()

    expect(wrapper.text()).toContain('WePlatform Admin')
    expect(wrapper.text()).toContain('Dashboard')
    expect(wrapper.text()).toContain('root')
    expect(wrapper.text()).toContain('退出登录')
    expect(wrapper.find('[data-test="router-view"]').exists()).toBe(true)
  })
})

describe('DashboardView', () => {
  it('renders a deterministic loading state', () => {
    mockedFetchDashboardSummary.mockImplementation(() => new Promise(() => {}))

    const wrapper = mount(DashboardView, {
      global: { plugins: [ElementPlus] },
    })

    expect(wrapper.text()).toContain('正在加载')
  })

  it('renders ready status from the admin dashboard API', async () => {
    mockedFetchDashboardSummary.mockResolvedValue({
      application: 'admin',
      status: 'ready',
      admin_user_id: 'admin-dashboard-1',
    })

    const wrapper = mount(DashboardView, {
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()

    expect(mockedFetchDashboardSummary).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('后台运行正常')
  })

  it('renders a deterministic generic error state', async () => {
    mockedFetchDashboardSummary.mockRejectedValue(new Error('private backend detail'))

    const wrapper = mount(DashboardView, {
      global: { plugins: [ElementPlus] },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('加载失败，请稍后重试')
    expect(wrapper.text()).not.toContain('private backend detail')
  })
})
