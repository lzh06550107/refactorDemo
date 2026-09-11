<script setup lang="ts">
import { onMounted, ref } from 'vue'

import { fetchDashboardSummary, type DashboardSummary } from '../api/dashboard'

const loading = ref(true)
const error = ref(false)
const summary = ref<DashboardSummary | null>(null)

onMounted(async () => {
  try {
    summary.value = await fetchDashboardSummary()
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="dashboard-view">
    <el-card shadow="never">
      <template #header>
        <strong>Dashboard</strong>
      </template>

      <p v-if="loading" class="dashboard-state">正在加载</p>
      <p v-else-if="error" class="dashboard-state dashboard-error">加载失败，请稍后重试</p>
      <div v-else-if="summary" class="dashboard-ready">
        <p class="dashboard-status">后台运行正常</p>
        <p class="dashboard-meta">管理员 ID：{{ summary.admin_user_id }}</p>
      </div>
    </el-card>
  </section>
</template>

<style scoped>
.dashboard-view {
  max-width: 960px;
  margin: 0 auto;
}

.dashboard-state,
.dashboard-status,
.dashboard-meta {
  margin: 0;
}

.dashboard-status {
  font-weight: 600;
}

.dashboard-meta {
  margin-top: 0.75rem;
  color: #6b7280;
}

.dashboard-error {
  color: #c45656;
}
</style>
