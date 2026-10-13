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

// Primeiro acesso e recuperação de senha (rotas públicas). Os pedidos respondem sempre com a mesma
// mensagem genérica; tokens vêm do link do e-mail e nunca são guardados no navegador.
export const requestActivation = (email) => api.post('/api/v1/auth/activation/request', { email }, { skipUnauthorized: true })

export const completeActivation = (token, password, passwordConfirmation) => api.post(
  '/api/v1/auth/activation/complete',
  { token, password, password_confirmation: passwordConfirmation },
  { skipUnauthorized: true },
)

export const forgotPassword = (email) => api.post('/api/v1/auth/password/forgot', { email }, { skipUnauthorized: true })

export const resetPassword = (email, token, password, passwordConfirmation) => api.post(
  '/api/v1/auth/password/reset',
  { email, token, password, password_confirmation: passwordConfirmation },
  { skipUnauthorized: true },
)
