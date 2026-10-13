import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { ADMIN, clearCookies, response } from './helpers'

const SUMMARY = {
  event_id: 1,
  participants: { total: 40, without_team: 3 },
  teams: { total: 8 },
  companies: null,
  challenges: { total: 5 },
  tasks: { pending: 4, in_progress: 2, overdue: 1 },
  occurrences: null,
  generated_at: '2026-10-13T12:00:00-03:00',
}

async function setup(handler = () => response(200, { data: SUMMARY })) {
  vi.resetModules()
  clearCookies()
  const calls = []
  const client = await import('@/js/api/client')
  client.configureClient({
    baseUrl: 'http://api.test',
    fetch: vi.fn((url, init) => {
      calls.push({ path: new URL(url).pathname, signal: init.signal })
      return handler(url, init)
    }),
  })
  const { auth, AUTH } = await import('@/js/stores/auth')
  Object.assign(auth, { status: AUTH.AUTHENTICATED, user: ADMIN, permissions: [...ADMIN.permissions] })
  const { eventContext } = await import('@/js/stores/event-context')
  Object.assign(eventContext, { status: 'ready', events: [{ id: 1, name: 'Evento', days: [] }, { id: 2, name: 'Outro', days: [] }], activeEventId: 1 })
  const { useDashboard } = await import('@/js/pages/dashboard')
  let page
  const wrapper = mount(defineComponent({ setup() { page = useDashboard(); return () => h('div') } }))
  await flushPromises()
  return { page, calls, wrapper, eventContext }
}

describe('Dashboard', () => {
  it('faz uma única requisição agregada (não uma por contagem)', async () => {
    const { calls } = await setup()
    expect(calls.map((call) => call.path)).toEqual(['/api/v1/events/1/dashboard'])
  })

  it('mostra todos os blocos juntos e esconde os que vieram null (sem permissão)', async () => {
    const { page } = await setup()
    const titles = page.blocks.value.map((block) => block.title)
    expect(titles).toEqual(['Participantes e equipes', 'Empresas e desafios', 'Pendências'])
    expect(page.blocks.value[0].items).toEqual([
      { label: 'Participantes', to: 'participantes', value: 40 },
      { label: 'Sem equipe', to: 'participantes', value: 3 },
      { label: 'Equipes', to: 'equipes', value: 8 },
    ])
    // Empresas veio null: o bloco fica só com Desafios.
    expect(page.blocks.value[1].items.map((item) => item.label)).toEqual(['Desafios'])
    expect(titles).not.toContain('Ocorrências')
  })

  it('erro mostra estado de erro e "tentar novamente" refaz uma requisição', async () => {
    let fail = true
    const { page, calls } = await setup(() => (fail ? response(500, { message: 'x' }) : response(200, { data: SUMMARY })))
    expect(page.summary.error.value).not.toBeNull()
    expect(page.blocks.value).toEqual([])
    fail = false
    await page.summary.load()
    expect(page.summary.error.value).toBeNull()
    expect(page.blocks.value).toHaveLength(3)
    expect(calls).toHaveLength(2)
  })

  it('trocar de evento recarrega uma vez; sair da tela cancela a requisição', async () => {
    let release
    const { calls, wrapper, eventContext } = await setup((url) => (url.includes('/events/2/')
      ? new Promise((resolve) => { release = resolve })
      : response(200, { data: SUMMARY })))
    eventContext.activeEventId = 2
    await nextTick()
    expect(calls.map((call) => call.path)).toEqual(['/api/v1/events/1/dashboard', '/api/v1/events/2/dashboard'])
    wrapper.unmount()
    expect(calls[1].signal.aborted).toBe(true)
    release?.(response(200, { data: SUMMARY }))
  })
})
