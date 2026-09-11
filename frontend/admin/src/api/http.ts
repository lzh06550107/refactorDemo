import axios from 'axios'

const csrfCookieName = 'weplatform_admin_csrf'
const unsafeMethods = new Set(['post', 'put', 'patch', 'delete'])

function readCookie(name: string): string | null {
  const prefix = `${name}=`
  for (const item of document.cookie.split(';')) {
    const cookie = item.trim()
    if (cookie.startsWith(prefix)) {
      return decodeURIComponent(cookie.slice(prefix.length))
    }
  }

  return null
}

export const http = axios.create({
  baseURL: '/admin-api/v1',
  withCredentials: true,
})

http.interceptors.request.use((config) => {
  const method = config.method?.toLowerCase()
  if (!method || !unsafeMethods.has(method)) {
    return config
  }

  const csrfToken = readCookie(csrfCookieName)
  if (csrfToken) {
    config.headers.set('X-CSRF-Token', csrfToken)
  }

  return config
})
