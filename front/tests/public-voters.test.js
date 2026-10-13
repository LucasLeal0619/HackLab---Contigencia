import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { ADMIN, clearCookies, response } from './helpers'

const SESSION = { id: 9, event_id: 1, name: 'Escolha do Público', status: 'DRAFT', candidates_count: 3, eligible_voters_count: 2 }
const READINESS = { public_confirmed_people: 10, activated_voter_accounts: 7, already_eligible: 2, public_without_account: 3, public_with_other_account: 0, eligible_voters: 2, candidates: 3 }

async function setup() {
  vi.resetModules()
  clearCookies()
  const calls = []
  const client = await import('@/js/api/client')
  client.configureClient({
    baseUrl: 'http://api.test',
    fetch: vi.fn(async (url, init) => {
      const path = new URL(url).pathname
      if (path === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=t; path=/'
        return response(204)
      }
      calls.push(`${init.method} ${path}`)
      if (path.endsWith('/public-voters/readiness')) return response(200, { data: READINESS })
      if (path.endsWith('/eligibilities/public-voters')) return response(200, { data: { eligible_public_accounts: 7, already_eligible: 2, added: 5, public_without_account: 3 } })
      if (path === '/api/v1/voting-sessions/9') return response(200, { data: { ...SESSION, eligible_voters_count: 7 } })
      return response(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    }),
  })
  const { auth, AUTH } = await import('@/js/stores/auth')
  Object.assign(auth, { status: AUTH.AUTHENTICATED, user: ADMIN, permissions: [...ADMIN.permissions, 'voting_sessions.manage', 'voting_eligibility.manage'] })
  const { eventContext } = await import('@/js/stores/event-context')
  Object.assign(eventContext, { status: 'ready', events: [{ id: 1, name: 'Evento', days: [] }], activeEventId: 1 })
  const ui = await import('@/js/stores/ui')
  const { useVotingAdmin } = await import('@/js/pages/voting-admin')
  let page
  mount(defineComponent({ setup() { page = useVotingAdmin(); return () => h('div') } }))
  await flushPromises()
  await page.openSession(SESSION)
  await flushPromises()
  return { page, calls, ui }
}

describe('preparação dos votantes do público', () => {
  it('"Adicionar votantes ativados" chama o endpoint da sessão e recarrega elegíveis e prontidão', async () => {
    const { page, calls, ui } = await setup()
    calls.length = 0
    await page.addPublic.run()
    expect(calls[0]).toBe('POST /api/v1/voting-sessions/9/eligibilities/public-voters')
    expect(calls).toContain('GET /api/v1/voting-sessions/9/public-voters/readiness')
    expect(calls).toContain('GET /api/v1/voting-sessions/9')
    expect(ui.ui.toast.text).toBe('5 votante(s) adicionado(s) à sessão.')
    expect(page.session.value.eligible_voters_count).toBe(7)
  })

  it('pedir para abrir carrega a prontidão (para o aviso de inscritos sem acesso)', async () => {
    const { page, calls } = await setup()
    calls.length = 0
    page.ask('open')
    await flushPromises()
    expect(calls).toContain('GET /api/v1/voting-sessions/9/public-voters/readiness')
    expect(page.readiness.data.value.public_without_account).toBe(3)
    expect(page.confirmText.value.subtitle).toContain('candidatas e elegíveis ficam congelados')
  })

  it('não chama a Even3 nem expõe dados pessoais na prontidão', async () => {
    const { page, calls } = await setup()
    page.ask('open')
    await flushPromises()
    expect(calls.every((call) => call.includes('/api/v1/'))).toBe(true)
    expect(Object.keys(page.readiness.data.value)).not.toContain('email')
  })
})
