import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { ADMIN, VOTER, clearCookies, response } from './helpers'

const PUBLICATION = { id: 7, version: 2, status: 'PUBLISHED', published_at: '2026-11-13T18:00:00Z', snapshot: { technical: [], popular: [] } }
const PREVIEW_OK = { can_publish: true, blockers: [], completeness: { pending: [] } }

// Servidor falso: responde por método + caminho e registra as chamadas.
function server(routes) {
  const calls = []
  const fetch = vi.fn(async (url, init) => {
    const path = new URL(url).pathname
    calls.push(`${init.method} ${path}`)
    if (path === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=t; path=/'
      return response(204)
    }
    const handler = routes[`${init.method} ${path}`]
    if (!handler) return response(404, { message: 'Recurso não encontrado.' })
    return typeof handler === 'function' ? handler(init) : handler
  })
  return { fetch, calls }
}

async function setup(user, routes) {
  vi.resetModules()
  clearCookies()
  const { fetch, calls } = server(routes)
  const client = await import('@/js/api/client')
  client.configureClient({ baseUrl: 'http://api.test', fetch })
  const { auth, AUTH } = await import('@/js/stores/auth')
  Object.assign(auth, { status: AUTH.AUTHENTICATED, user, permissions: user.permissions })
  const { eventContext } = await import('@/js/stores/event-context')
  Object.assign(eventContext, { status: 'ready', events: [{ id: 1, name: 'Evento', days: [] }], activeEventId: 1 })
  const ui = await import('@/js/stores/ui')
  return { calls, ui }
}

function run(composable) {
  let result
  mount(defineComponent({ setup() { result = composable(); return () => h('div') } }))
  return result
}

const ADMIN_RESULTS = { ...ADMIN, permissions: [...ADMIN.permissions, 'results.publish', 'results.view_published', 'voting_sessions.view'] }
const sessions = response(200, { data: [{ id: 3, status: 'CLOSED' }], meta: { current_page: 1, last_page: 1, total: 1 } })

describe('resultado publicado (painel)', () => {
  beforeEach(() => localStorage.clear())

  it('sem publicação → nenhum resultado oficial (estado vazio)', async () => {
    await setup(VOTER, { 'GET /api/v1/events/1/results/published': response(200, { data: null, meta: { official: false } }) })
    const { useResults } = await import('@/js/pages/results')
    const page = run(() => useResults())
    await flushPromises()
    expect(page.published.error.value).toBeNull()
    expect(page.current.value).toBeNull()
  })

  it('publicação existente → mostra o snapshot oficial', async () => {
    await setup(VOTER, { 'GET /api/v1/events/1/results/published': response(200, { data: PUBLICATION, meta: { official: true } }) })
    const { useResults } = await import('@/js/pages/results')
    const page = run(() => useResults())
    await flushPromises()
    expect(page.current.value).toMatchObject({ version: 2, status: 'PUBLISHED' })
    // O Votante não tem atalhos para a gestão nem para a apresentação.
    expect(page.can('resultados')).toBe(false)
    expect(page.can('apresentacao')).toBe(false)
  })
})

describe('publicação (gestão)', () => {
  beforeEach(() => localStorage.clear())

  it('sucesso: publica a sessão escolhida, avisa a versão e recarrega prévia e oficial', async () => {
    let published = null
    const { calls, ui } = await setup(ADMIN_RESULTS, {
      'GET /api/v1/events/1/voting-sessions': sessions,
      'GET /api/v1/events/1/results/preview': () => response(200, { data: PREVIEW_OK }),
      'GET /api/v1/events/1/results/published': () => response(200, { data: published }),
      'POST /api/v1/events/1/results/publish': (init) => {
        expect(JSON.parse(init.body)).toEqual({ voting_session_id: 3 })
        published = PUBLICATION
        return response(201, { data: PUBLICATION })
      },
    })
    const { useResultsAdmin } = await import('@/js/pages/results-admin')
    const page = run(() => useResultsAdmin())
    await flushPromises()
    expect(page.preview.data.value.can_publish).toBe(true)
    page.askPublish()
    await page.publish.run()
    await flushPromises()
    expect(page.modal.value).toBeNull()
    expect(ui.ui.toast).toMatchObject({ type: 'ok', text: 'Resultado oficial publicado (versão 2).' })
    expect(page.current.value.version).toBe(2)
    expect(calls.filter((call) => call === 'GET /api/v1/events/1/results/preview').length).toBe(2)
  })

  it('422 de bloqueio: mostra a mensagem do backend e não fecha a confirmação', async () => {
    const message = 'Há 3 atribuição(ões) ativa(s) sem avaliação enviada.'
    const { ui } = await setup(ADMIN_RESULTS, {
      'GET /api/v1/events/1/voting-sessions': sessions,
      'GET /api/v1/events/1/results/preview': response(200, { data: { can_publish: false, blockers: [{ code: 'PENDING_EVALUATIONS', message }], completeness: { pending: [] } } }),
      'GET /api/v1/events/1/results/published': response(200, { data: null }),
      'POST /api/v1/events/1/results/publish': response(422, { message, errors: { results: [message] } }),
    })
    const { useResultsAdmin } = await import('@/js/pages/results-admin')
    const page = run(() => useResultsAdmin())
    await flushPromises()
    expect(page.blockers.value.map((item) => item.message)).toEqual([message])
    page.askPublish()
    expect(await page.publish.run()).toBeUndefined()
    expect(page.modal.value).toBe('publish')
    expect(page.publish.fieldErrors.value).toEqual({ results: message })
    expect(ui.ui.toast).toMatchObject({ type: 'err', text: message })
    expect(page.current.value).toBeNull()
  })
})
