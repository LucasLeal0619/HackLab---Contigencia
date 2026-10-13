// Sessão do usuário. Fonte da verdade: GET /api/v1/auth/me (cookie Sanctum). Nada disso vai para o navegador.
import { reactive } from 'vue'
import { onUnauthorized } from '@/js/api/client'
import * as authService from '@/js/services/auth'

export const AUTH = { UNKNOWN: 'UNKNOWN', AUTHENTICATED: 'AUTHENTICATED', GUEST: 'GUEST' }

export const auth = reactive({
  status: AUTH.UNKNOWN,
  user: null,
  permissions: [],
  // Erro ao descobrir a sessão (servidor fora do ar): a tela mostra erro de conexão, nunca um login falso.
  bootError: null,
})

const listeners = new Set()
// Cada mudança de sessão (bootstrap, login, logout) ganha um número: a resposta atrasada de um /auth/me
// antigo não desfaz um login feito enquanto ele estava em andamento.
let generation = 0

// Quem guarda dados em memória (contexto de evento, caches) limpa tudo quando a sessão termina.
export function onSessionEnd(fn) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

function setUser(user) {
  auth.user = user
  auth.permissions = Array.isArray(user?.permissions) ? [...user.permissions] : []
  auth.status = AUTH.AUTHENTICATED
  auth.bootError = null
}

export function clearSession() {
  const had = auth.status === AUTH.AUTHENTICATED
  auth.user = null
  auth.permissions = []
  auth.status = AUTH.GUEST
  if (had) listeners.forEach((fn) => fn())
}

// 401 em qualquer chamada: a sessão acabou no servidor.
onUnauthorized(() => {
  if (auth.status === AUTH.AUTHENTICATED) clearSession()
})

export async function bootstrap() {
  const mine = ++generation
  auth.status = AUTH.UNKNOWN
  auth.bootError = null
  try {
    const user = await authService.me()
    if (mine === generation) setUser(user)
  } catch (error) {
    if (mine !== generation) return
    if (error.kind === 'unauthorized') {
      clearSession()
      return
    }
    auth.bootError = error
  }
}

export async function login(email, password) {
  const user = await authService.login(email.trim().toLowerCase(), password)
  generation++
  // /auth/login já devolve o mesmo contrato de /auth/me (usuário, Person, perfil, setor e permissões).
  setUser(user)
  return user
}

export async function logout() {
  generation++
  try {
    await authService.logout()
  } finally {
    clearSession()
  }
}

export function hasPermission(code) {
  return auth.permissions.includes(code)
}

export function hasAnyPermission(codes) {
  return codes.some((code) => auth.permissions.includes(code))
}

export function roleCode() {
  return auth.user?.role?.code || ''
}

export function useAuth() {
  return auth
}
