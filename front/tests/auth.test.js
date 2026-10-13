import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ADMIN, clearCookies, fakeFetch, response } from './helpers'

// Cada teste carrega módulos novos (o store é um singleton).
async function load(fetch) {
  vi.resetModules()
  const client = await import('@/js/api/client')
  client.configureClient({ baseUrl: 'http://api.test', fetch })
  const store = await import('@/js/stores/auth')
  return { client, ...store }
}

describe('store de autenticação', () => {
  beforeEach(() => {
    clearCookies()
    localStorage.clear()
  })

  it('bootstrap autenticado usa /auth/me e as permissões do backend', async () => {
    const fetch = fakeFetch(response(200, { data: ADMIN }))
    const { auth, bootstrap, AUTH, hasPermission, hasAnyPermission, roleCode } = await load(fetch)
    expect(auth.status).toBe(AUTH.UNKNOWN)
    await bootstrap()
    expect(fetch.mock.calls[0][0]).toBe('http://api.test/api/v1/auth/me')
    expect(auth.status).toBe(AUTH.AUTHENTICATED)
    expect(auth.user.email).toBe('admin@hacklab.local')
    expect(hasPermission('audit_logs.view')).toBe(true)
    expect(hasPermission('votes.cast')).toBe(false)
    expect(hasAnyPermission(['votes.cast', 'users.view'])).toBe(true)
    expect(roleCode()).toBe('ADMINISTRATOR')
  })

  it('bootstrap 401 vira GUEST sem erro', async () => {
    const { auth, bootstrap, AUTH } = await load(fakeFetch(response(401, { message: 'Não autenticado.' })))
    await bootstrap()
    expect(auth.status).toBe(AUTH.GUEST)
    expect(auth.bootError).toBeNull()
  })

  it('/auth/me atrasado (401) não desfaz um login feito enquanto ele estava em andamento', async () => {
    let answerMe
    const fetch = vi.fn((url) => {
      if (url.endsWith('/auth/me')) return new Promise((resolve) => { answerMe = resolve })
      if (url.endsWith('/sanctum/csrf-cookie')) { document.cookie = 'XSRF-TOKEN=t; path=/'; return Promise.resolve(response(204)) }
      return Promise.resolve(response(200, { data: ADMIN }))
    })
    const { auth, bootstrap, login, AUTH } = await load(fetch)
    const booting = bootstrap()
    await login('admin@hacklab.local', 'x')
    expect(auth.status).toBe(AUTH.AUTHENTICATED)
    answerMe(response(401, { message: 'Não autenticado.' }))
    await booting
    expect(auth.status).toBe(AUTH.AUTHENTICATED)
    expect(auth.user.email).toBe('admin@hacklab.local')
  })

  it('backend fora do ar não vira login falso nem admin falso', async () => {
    const { auth, bootstrap, AUTH } = await load(fakeFetch(new TypeError('Failed to fetch')))
    await bootstrap()
    expect(auth.status).toBe(AUTH.UNKNOWN)
    expect(auth.user).toBeNull()
    expect(auth.bootError.kind).toBe('network')
  })

  it('login: csrf-cookie → POST /auth/login → usuário do backend; nada no localStorage', async () => {
    const fetch = fakeFetch(
      () => { document.cookie = 'XSRF-TOKEN=t; path=/'; return response(204) },
      (url, init) => {
        expect(url).toBe('http://api.test/api/v1/auth/login')
        expect(JSON.parse(init.body)).toEqual({ email: 'admin@hacklab.local', password: 'segredo1' })
        return response(200, { data: ADMIN })
      },
    )
    const { auth, login, AUTH } = await load(fetch)
    await login(' Admin@HackLab.local ', 'segredo1')
    expect(fetch.mock.calls[0][0]).toBe('http://api.test/sanctum/csrf-cookie')
    expect(auth.status).toBe(AUTH.AUTHENTICATED)
    expect(JSON.stringify({ ...localStorage })).not.toMatch(/segredo1|admin@|session|token/)
  })

  it('login com credencial inválida propaga o 422 do backend', async () => {
    const { auth, login, AUTH } = await load(fakeFetch(
      () => { document.cookie = 'XSRF-TOKEN=t; path=/'; return response(204) },
      response(422, { message: 'E-mail ou senha inválidos.', errors: { email: ['E-mail ou senha inválidos.'] } }),
    ))
    await expect(login('a@b.c', 'x')).rejects.toMatchObject({ kind: 'validation', message: 'E-mail ou senha inválidos.' })
    expect(auth.status).not.toBe(AUTH.AUTHENTICATED)
  })

  it('logout chama a API, limpa o usuário e avisa quem guarda dados em memória', async () => {
    document.cookie = 'XSRF-TOKEN=t; path=/'
    const fetch = fakeFetch(response(200, { data: ADMIN }), response(204))
    const { auth, bootstrap, logout, onSessionEnd, AUTH } = await load(fetch)
    await bootstrap()
    const ended = vi.fn()
    onSessionEnd(ended)
    await logout()
    expect(fetch.mock.calls[1][0]).toBe('http://api.test/api/v1/auth/logout')
    expect(auth.status).toBe(AUTH.GUEST)
    expect(auth.user).toBeNull()
    expect(auth.permissions).toEqual([])
    expect(ended).toHaveBeenCalledTimes(1)
  })

  it('401 em qualquer chamada encerra a sessão local', async () => {
    const fetch = fakeFetch(response(200, { data: ADMIN }), response(401, {}))
    const { auth, bootstrap, client, AUTH } = await load(fetch)
    await bootstrap()
    await expect(client.api.get('/api/v1/users')).rejects.toMatchObject({ kind: 'unauthorized' })
    expect(auth.status).toBe(AUTH.GUEST)
  })

  it('403 em uma chamada não encerra a sessão', async () => {
    const fetch = fakeFetch(response(200, { data: ADMIN }), response(403, { message: 'Acesso negado.' }))
    const { auth, bootstrap, client, AUTH } = await load(fetch)
    await bootstrap()
    await expect(client.api.get('/api/v1/audit-logs')).rejects.toMatchObject({ kind: 'forbidden' })
    expect(auth.status).toBe(AUTH.AUTHENTICATED)
  })
})
