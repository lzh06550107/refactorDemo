<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterView, useRouter } from 'vue-router'

import { useAdminAuthStore } from '../stores/auth'

const auth = useAdminAuthStore()
const router = useRouter()
const loggingOut = ref(false)
const username = computed(() => auth.user?.username ?? '-')

async function signOut(): Promise<void> {
  if (loggingOut.value) {
    return
  }

  loggingOut.value = true
  try {
    await auth.signOut()
    await router.replace('/login')
  } finally {
    loggingOut.value = false
  }
}
</script>

<template>
  <el-container class="admin-shell">
    <el-aside class="admin-sidebar" width="220px">
      <div class="admin-brand">WePlatform Admin</div>
      <el-menu router default-active="/" class="admin-menu">
        <el-menu-item index="/">Dashboard</el-menu-item>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="admin-header">
        <span class="admin-username">{{ username }}</span>
        <el-button :loading="loggingOut" :disabled="loggingOut" @click="signOut">
          退出登录
        </el-button>
      </el-header>

      <el-main class="admin-main">
        <RouterView />
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped>
.admin-shell {
  min-height: 100vh;
}

.admin-sidebar {
  border-right: 1px solid #e5e7eb;
  background: #ffffff;
}

.admin-brand {
  padding: 1.25rem;
  font-size: 1.05rem;
  font-weight: 700;
}

.admin-menu {
  border-right: 0;
}

.admin-header {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 1rem;
  border-bottom: 1px solid #e5e7eb;
  background: #ffffff;
}

.admin-username {
  color: #374151;
}

.admin-main {
  background: #f5f7fa;
}
</style>
