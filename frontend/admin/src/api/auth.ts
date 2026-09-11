import { http } from './http'

export type AdminUser = {
  id: string
  username: string
}

type ApiEnvelope<T> = {
  code: string
  message: string
  data: T
  request_id: string
}

export async function bootstrapCsrf(): Promise<void> {
  await http.get<ApiEnvelope<{ ready: true }>>('/auth/csrf')
}

export async function login(username: string, password: string): Promise<AdminUser> {
  const response = await http.post<ApiEnvelope<AdminUser>>('/auth/login', { username, password })
  return response.data.data
}

export async function me(): Promise<AdminUser> {
  const response = await http.get<ApiEnvelope<AdminUser>>('/auth/me')
  return response.data.data
}

export async function logout(): Promise<void> {
  await http.post<ApiEnvelope<{ logged_out: true }>>('/auth/logout')
}
