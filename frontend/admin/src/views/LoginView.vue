<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { useAdminAuthStore } from '../stores/auth'

const auth = useAdminAuthStore()
const route = useRoute()
const router = useRouter()

const username = ref('')
const password = ref('')
const loading = ref(false)
const errorMessage = ref('')

function safeInternalRedirect(value: unknown): string {
  if (typeof value !== 'string') {
    return '/'
  }

  if (value === '/admin' || value === '/admin/') {
    return '/'
  }

  if (!value.startsWith('/admin/')) {
    return '/'
  }

  const internal = value.slice('/admin'.length)
  if (!internal.startsWith('/') || internal.startsWith('//')) {
    return '/'
  }

  return internal
}

async function submit(): Promise<void> {
  if (loading.value) {
    return
  }

  errorMessage.value = ''
  const normalizedUsername = username.value.trim()
  if (!normalizedUsername || !password.value) {
    errorMessage.value = '请输入用户名和密码'
    return
  }

  loading.value = true
  try {
    await auth.signIn(normalizedUsername, password.value)
    await router.replace(safeInternalRedirect(route.query.redirect))
  } catch {
    errorMessage.value = '用户名或密码错误'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="login-view">
    <el-card class="login-card" shadow="never">
      <div class="login-heading">
        <h1>WePlatform Admin</h1>
        <p>登录管理后台</p>
      </div>

      <el-form @submit.prevent="submit">
        <el-form-item>
          <el-input
            v-model="username"
            name="username"
            autocomplete="username"
            placeholder="用户名"
            :disabled="loading"
          />
        </el-form-item>

        <el-form-item>
          <el-input
            v-model="password"
            name="password"
            type="password"
            autocomplete="current-password"
            placeholder="密码"
            show-password
            :disabled="loading"
          />
        </el-form-item>

        <p v-if="errorMessage" class="login-error" role="alert">{{ errorMessage }}</p>

        <el-button
          class="login-submit"
          type="primary"
          native-type="submit"
          :loading="loading"
          :disabled="loading"
        >
          登录
        </el-button>
      </el-form>
    </el-card>
  </main>
</template>

<style scoped>
.login-view {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 2rem;
}

.login-card {
  width: min(100%, 420px);
}

.login-heading {
  margin-bottom: 1.5rem;
  text-align: center;
}

.login-heading h1,
.login-heading p,
.login-error {
  margin: 0;
}

.login-heading p {
  margin-top: 0.5rem;
  color: #6b7280;
}

.login-error {
  margin: -0.25rem 0 1rem;
  color: #c45656;
  font-size: 0.875rem;
}

.login-submit {
  width: 100%;
}
</style>
