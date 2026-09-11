import { http } from './http'

export type DashboardSummary = {
  application: 'admin'
  status: 'ready'
  admin_user_id: string
}

type ApiEnvelope<T> = {
  code: string
  message: string
  data: T
  request_id: string
}

export async function fetchDashboardSummary(): Promise<DashboardSummary> {
  const response = await http.get<ApiEnvelope<DashboardSummary>>('/dashboard')
  return response.data.data
}
