import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, buildUrl, configureClient, onUnauthorized } from '@/js/api/client'
import { ApiError } from '@/js/api/errors'
import { clearCookies, fakeFetch, response } from './helpers'

describe('client HTTP', () => {
  beforeEach(() => {
    clearCookies()
    configureClient({ baseUrl: 'http://api.test' })
    onUnauthorized(null)
  })
  afterEach(() => clearCookies())

  it('monta URL com base do ambiente e query sem vazios', () => {
    expect(buildUrl('/api/v1/users', { search: 'ana', role: '', page: 2, has_team: false, status: null })).toBe('http://api.test/api/v1/users?search=ana&page=2&has_team=false')
  })

  it('envia cookies (credentials include) e JSON', async () => {
    const fetch = fakeFetch(response(200, { data: [1] }))
    configureClient({ fetch })
    const payload = await api.get('/api/v1/events')
    expect(payload).toEqual({ data: [1] })
    const [url, init] = fetch.mock.calls[0]
    expect(url).toBe('http://api.test/api/v1/events')
    expect(init.credentials).toBe('include')
    expect(init.headers.Accept).toBe('application/json')
    expect(init.headers['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('GET só com cabeçalhos simples (sem preflight OPTIONS); escrita mantém X-Requested-With', async () => {
    document.cookie = 'XSRF-TOKEN=t; path=/'
    const fetch = fakeFetch(response(200, { data: [] }))
    configureClient({ fetch })
    await api.get('/api/v1/events/1/teams', { query: { per_page: 1 } })
    expect(Object.keys(fetch.mock.calls[0][1].headers)).toEqual(['Accept'])
    await api.post('/api/v1/teams', { name: 'X' })
    expect(fetch.mock.calls[1][1].headers['X-Requested-With']).toBe('XMLHttpRequest')
  })

  it('mutation sem XSRF-TOKEN busca /sanctum/csrf-cookie antes e envia o header', async () => {
    const fetch = fakeFetch(
      (url) => {
        expect(url).toBe('http://api.test/sanctum/csrf-cookie')
        document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/'
        return response(204)
      },
      (url, init) => {
        expect(init.headers['X-XSRF-TOKEN']).toBe('abc==')
        expect(JSON.parse(init.body)).toEqual({ name: 'X' })
        return response(201, { data: { id: 9 } })
      },
    )
    configureClient({ fetch })
    const payload = await api.post('/api/v1/teams', { name: 'X' })
    expect(payload.data.id).toBe(9)
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('419 renova o CSRF e repete uma única vez', async () => {
    document.cookie = 'XSRF-TOKEN=velho; path=/'
    const fetch = fakeFetch(
      response(419, { message: 'Sessão expirada ou token CSRF inválido.' }),
      () => { document.cookie = 'XSRF-TOKEN=novo; path=/'; return response(204) },
      (url, init) => { expect(init.headers['X-XSRF-TOKEN']).toBe('novo'); return response(200, { data: { ok: true } }) },
    )
    configureClient({ fetch })
    await expect(api.patch('/api/v1/teams/1', { name: 'Y' })).resolves.toEqual({ data: { ok: true } })
    expect(fetch).toHaveBeenCalledTimes(3)
  })

  it('419 repetido não entra em loop', async () => {
    document.cookie = 'XSRF-TOKEN=x; path=/'
    const fetch = fakeFetch((url) => (url.includes('csrf-cookie') ? response(204) : response(419, {})))
    configureClient({ fetch })
    await expect(api.post('/api/v1/teams', {})).rejects.toMatchObject({ kind: 'csrf', status: 419 })
    expect(fetch).toHaveBeenCalledTimes(3)
  })

  it('401 chama o tratador de sessão encerrada', async () => {
    const handler = vi.fn()
    onUnauthorized(handler)
    configureClient({ fetch: fakeFetch(response(401, { message: 'Não autenticado.' })) })
    await expect(api.get('/api/v1/users')).rejects.toMatchObject({ kind: 'unauthorized' })
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('403 não encerra a sessão e informa falta de permissão', async () => {
    const handler = vi.fn()
    onUnauthorized(handler)
    configureClient({ fetch: fakeFetch(response(403, { message: 'Acesso negado.', errors: {} })) })
    const error = await api.get('/api/v1/audit-logs').catch((e) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect(error.kind).toBe('forbidden')
    expect(error.message).toBe('Você não tem permissão para esta ação.')
    expect(handler).not.toHaveBeenCalled()
  })

  it('403 com mensagem própria do backend mantém a mensagem', async () => {
    configureClient({ fetch: fakeFetch(response(403, { message: 'Conta inativa.' })) })
    await expect(api.get('/api/v1/auth/me')).rejects.toMatchObject({ message: 'Conta inativa.' })
  })

  it('422 expõe erros por campo e a primeira mensagem', async () => {
    document.cookie = 'XSRF-TOKEN=x; path=/'
    configureClient({ fetch: fakeFetch(response(422, { message: 'O nome é obrigatório. (and 1 more error)', errors: { name: ['O nome é obrigatório.'], code: ['Código em uso.'] } })) })
    const error = await api.post('/api/v1/teams', {}).catch((e) => e)
    expect(error.kind).toBe('validation')
    expect(error.message).toBe('O nome é obrigatório.')
    expect(error.fieldErrors).toEqual({ name: 'O nome é obrigatório.', code: 'Código em uso.' })
  })

  it('429 vira mensagem amigável e pode ser repetido', async () => {
    configureClient({ fetch: fakeFetch(response(429, { message: 'Muitas requisições. Tente novamente em instantes.' })) })
    const error = await api.get('/api/v1/users').catch((e) => e)
    expect(error.kind).toBe('rate_limited')
    expect(error.retryable).toBe(true)
  })

  it('5xx não mostra detalhes do servidor', async () => {
    configureClient({ fetch: fakeFetch(response(500, { message: 'SQLSTATE[...] detalhe interno' })) })
    const error = await api.get('/api/v1/users').catch((e) => e)
    expect(error.kind).toBe('server')
    expect(error.message).not.toContain('SQLSTATE')
  })

  it('falha de rede vira erro de conexão (sem fallback local)', async () => {
    configureClient({ fetch: fakeFetch(new TypeError('Failed to fetch')) })
    await expect(api.get('/api/v1/auth/me')).rejects.toMatchObject({ kind: 'network' })
  })

  it('timeout aborta a requisição', async () => {
    configureClient({
      fetch: vi.fn((url, init) => new Promise((_, reject) => init.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))))),
    })
    await expect(api.get('/api/v1/users', { timeout: 10 })).rejects.toMatchObject({ kind: 'timeout' })
  })

  it('204 devolve null', async () => {
    document.cookie = 'XSRF-TOKEN=x; path=/'
    configureClient({ fetch: fakeFetch(response(204)) })
    await expect(api.post('/api/v1/auth/logout')).resolves.toBeNull()
  })
})
