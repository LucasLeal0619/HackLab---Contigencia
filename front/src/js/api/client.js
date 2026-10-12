// Único cliente HTTP do HackLab. Sanctum SPA: cookie de sessão (HttpOnly, nunca lido aqui) + XSRF-TOKEN.
// Só os services chamam este módulo; páginas e componentes chamam os services.
import { ApiError } from './errors'

const DEFAULT_TIMEOUT = 30000
const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

const settings = {
  // Configuração só por ambiente (VITE_API_URL). Vazio = mesma origem do front.
  baseUrl: String(import.meta.env.VITE_API_URL || '').replace(/\/+$/, ''),
  fetch: (...args) => globalThis.fetch(...args),
}

const handlers = { unauthorized: null }

export function configureClient(partial = {}) {
  Object.assign(settings, partial)
}

// O store de autenticação registra aqui o que fazer com um 401 (limpar sessão e ir para o login).
export function onUnauthorized(fn) {
  handlers.unauthorized = fn
}

export function readCookie(name) {
  const prefix = `${name}=`
  const found = String(document.cookie || '').split(';').map((item) => item.trim()).find((item) => item.startsWith(prefix))
  return found ? decodeURIComponent(found.slice(prefix.length)) : ''
}

export function buildUrl(path, query) {
  const url = `${settings.baseUrl}${path.startsWith('/') ? path : `/${path}`}`
  if (!query) return url
  const params = new URLSearchParams()
  Object.entries(query).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return
    if (Array.isArray(value)) value.forEach((item) => params.append(`${key}[]`, item))
    else params.append(key, typeof value === 'boolean' ? String(value) : value)
  })
  const qs = params.toString()
  return qs ? `${url}?${qs}` : url
}

async function parse(response) {
  if (response.status === 204) return null
  const text = await response.text()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

async function send(method, path, { query, body, timeout = DEFAULT_TIMEOUT, signal } = {}) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (MUTATING.has(method)) {
    const token = readCookie('XSRF-TOKEN')
    if (token) headers['X-XSRF-TOKEN'] = token
  }

  const controller = new AbortController()
  let timedOut = false
  const timer = setTimeout(() => { timedOut = true; controller.abort() }, timeout)
  const forward = () => controller.abort()
  if (signal) {
    if (signal.aborted) controller.abort()
    else signal.addEventListener('abort', forward, { once: true })
  }

  let response
  try {
    response = await settings.fetch(buildUrl(path, query), {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'include',
      signal: controller.signal,
    })
  } catch {
    if (timedOut) throw ApiError.of('timeout')
    if (signal?.aborted) throw ApiError.of('aborted')
    throw ApiError.of('network')
  } finally {
    clearTimeout(timer)
    signal?.removeEventListener?.('abort', forward)
  }

  const payload = await parse(response)
  if (!response.ok) throw ApiError.fromResponse(response.status, payload)
  return payload
}

export async function csrfCookie() {
  await send('GET', '/sanctum/csrf-cookie')
}

export async function request(method, path, options = {}) {
  const verb = method.toUpperCase()
  if (MUTATING.has(verb) && !readCookie('XSRF-TOKEN')) await csrfCookie()
  try {
    return await send(verb, path, options)
  } catch (error) {
    // 419: CSRF vencido. Renova e repete uma única vez.
    if (error.kind === 'csrf' && !options.retried) {
      await csrfCookie()
      return request(verb, path, { ...options, retried: true })
    }
    if (error.kind === 'unauthorized' && !options.skipUnauthorized) handlers.unauthorized?.(error)
    throw error
  }
}

export const api = {
  get: (path, options) => request('GET', path, options),
  post: (path, body, options) => request('POST', path, { ...options, body: body ?? {} }),
  put: (path, body, options) => request('PUT', path, { ...options, body: body ?? {} }),
  patch: (path, body, options) => request('PATCH', path, { ...options, body: body ?? {} }),
  delete: (path, options) => request('DELETE', path, options),
}

// Recurso único do Laravel: { data: {...} }.
export function unwrap(payload) {
  return payload && typeof payload === 'object' && 'data' in payload ? payload.data : payload
}
