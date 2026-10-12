import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeFetch, response } from './helpers'

const EVENT = (id, extra = {}) => ({ id, name: `Evento ${id}`, status: 'PLANNED', timezone: 'America/Sao_Paulo', days: [], ...extra })
const page = (items, current = 1, last = 1) => ({ data: items, meta: { current_page: current, last_page: last, total: items.length }, links: {} })

async function load(fetch, prefs) {
  vi.resetModules()
  localStorage.clear()
  if (prefs) localStorage.setItem('hacklab.ui', JSON.stringify(prefs))
  const client = await import('@/js/api/client')
  client.configureClient({ baseUrl: 'http://api.test', fetch })
  return import('@/js/stores/event-context')
}

describe('contexto de evento', () => {
  beforeEach(() => localStorage.clear())

  it('nenhum evento → sem evento ativo', async () => {
    const ctx = await load(fakeFetch(response(200, page([]))))
    await ctx.loadEvents()
    expect(ctx.eventContext.status).toBe('ready')
    expect(ctx.eventContext.activeEventId).toBeNull()
  })

  it('um evento → selecionado automaticamente, com fuso e dias da API', async () => {
    const days = [{ id: 2, day_number: 2, date: '2026-11-12' }, { id: 1, day_number: 1, date: '2026-11-11' }]
    const ctx = await load(fakeFetch(response(200, page([EVENT(7, { days })]))))
    await ctx.loadEvents()
    expect(ctx.eventContext.activeEventId).toBe(7)
    expect(ctx.eventTimezone.value).toBe('America/Sao_Paulo')
    expect(ctx.activeDays.value.map((d) => d.day_number)).toEqual([1, 2])
  })

  it('preferência salva só vale se o evento existir na API', async () => {
    const ctx = await load(fakeFetch(response(200, page([EVENT(3), EVENT(4, { status: 'ACTIVE' })]))), { activeEventId: 999 })
    await ctx.loadEvents()
    expect(ctx.eventContext.activeEventId).toBe(4)
    const ctx2 = await load(fakeFetch(response(200, page([EVENT(3), EVENT(4)]))), { activeEventId: 3 })
    await ctx2.loadEvents()
    expect(ctx2.eventContext.activeEventId).toBe(3)
  })

  it('percorre todas as páginas de /events', async () => {
    const fetch = fakeFetch(response(200, page([EVENT(1)], 1, 2)), response(200, page([EVENT(2)], 2, 2)))
    const ctx = await load(fetch)
    await ctx.loadEvents()
    expect(ctx.eventContext.events.map((e) => e.id)).toEqual([1, 2])
    expect(fetch.mock.calls[1][0]).toContain('page=2')
  })

  it('trocar de evento muda a versão (telas recarregam) e grava só o id em hacklab.ui', async () => {
    const ctx = await load(fakeFetch(response(200, page([EVENT(1), EVENT(2)]))))
    const ui = await import('@/js/stores/ui')
    ui.bindUi()
    await ctx.loadEvents()
    const before = ctx.eventContext.version
    ctx.selectEvent(2)
    expect(ctx.eventContext.activeEventId).toBe(2)
    expect(ctx.eventContext.version).toBe(before + 1)
    await new Promise((resolve) => setTimeout(resolve))
    expect(JSON.parse(localStorage.getItem('hacklab.ui')).activeEventId).toBe(2)
    expect(Object.keys(localStorage)).toEqual(['hacklab.ui'])
  })

  it('erro ao carregar eventos fica visível (sem evento inventado)', async () => {
    const ctx = await load(fakeFetch(new TypeError('Failed to fetch')))
    await ctx.loadEvents()
    expect(ctx.eventContext.status).toBe('error')
    expect(ctx.eventContext.activeEventId).toBeNull()
  })
})
