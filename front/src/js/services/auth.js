import { api, csrfCookie, unwrap } from '@/js/api/client'

export async function login(email, password) {
  await csrfCookie()
  return unwrap(await api.post('/api/v1/auth/login', { email, password }, { skipUnauthorized: true }))
}

export async function me() {
  return unwrap(await api.get('/api/v1/auth/me', { skipUnauthorized: true }))
}

export async function logout() {
  await api.post('/api/v1/auth/logout', {}, { skipUnauthorized: true })
}
