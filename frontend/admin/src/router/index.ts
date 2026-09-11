import { defineComponent } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'

import LoginView from '../views/LoginView.vue'
import NotFoundView from '../views/NotFoundView.vue'
import { adminAuthGuard } from './guards'

const AdminHomePlaceholder = defineComponent({
  name: 'AdminHomePlaceholder',
  setup: () => () => null,
})

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    {
      path: '/',
      name: 'home',
      component: AdminHomePlaceholder,
      meta: { requiresAuth: true },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: NotFoundView,
    },
  ],
})

router.beforeEach(adminAuthGuard)

export default router
