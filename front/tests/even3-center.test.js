import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { ADMIN, JUROR, MANAGER, VOTER, clearCookies, response } from './helpers'

const CONSULTANT = {
  id: 7, email: 'consultor@hacklab.local', status: 'ACTIVE', role: { code: 'CONSULTANT', name: 'Consultor' }, sector: null,
  person: { id: 7, full_name: 'Consultor' }, permissions: ['events.view', 'integrations.view', 'event_registrations.view', 'attendance.view'],
}
const ADMIN_FULL = { ...ADMIN, permissions: [...ADMIN.permissions, 'integrations.manage', 'integrations.sync', 'event_registrations.view', 'attendance.view', 'companies.manage', 'participants.manage'] }

const WORKBENCH = (overrides = {}) => ({
  event_id: 1,
  configured: true,
  integration_status: 'ACTIVE',
  external_event_id: '793572',
  sync: { running: false, running_since: null, last_sync_at: new Date().toISOString(), last_sync_status: 'SUCCESS', last_error_code: null },
  totals: { registered: 187, present: 142, without_record: 45, public_without_access: 14 },
  attention: { total: 2, PARTICIPANT_NOT_FOUND: 1, IDENTITY_CONFLICT: 0, COMPANY_UNLINKED: 1, UNMAPPED_TICKET: 0 },
  tickets: { active: 4, mapped: 4 },
  event_differences: 0,
  ...overrides,
})
const ATTENTION = [
  { type: 'PARTICIPANT_NOT_FOUND', person: { id: 11, full_name: 'Bia Souza', email: 'bia@teste.local' }, category: 'Participante', registration_id: 31, ticket: null },
  { type: 'COMPANY_UNLINKED', person: { id: 12, full_name: 'Maria Silva', email: 'maria@teste.local' }, category: 'Empresa / Convidado', registration_id: 32, ticket: null },
]
const PAGE = (data) => ({ data, meta: { current_page: 1, last_page: 1, total: data.length } })
const RUN = { id: 9, status: 'SUCCESS', finished_at: new Date().toISOString(), counters: { attendees_created: 3, attendees_updated: 31, jurors_created: 2 } }

function defaultHandler(path) {
  if (path.endsWith('/sync')) return response(201, { data: RUN })
  if (path.endsWith('/attention')) return response(200, { data: ATTENTION, meta: { total: 2 } })
  if (path.endsWith('/event-registrations')) return response(200, PAGE([
    { id: 1, person: { full_name: 'Ana Souza', email: 'ana@teste.local' }, ticket: { internal_category: 'PARTICIPANT' }, check_ins: 2 },
    { id: 2, person: { full_name: 'Maria Alves', email: 'mariaa@teste.local' }, ticket: { internal_category: 'PUBLIC' }, check_ins: 0 },
  ]))
  if (path.endsWith('/companies')) return response(200, PAGE([{ id: 5, name: 'Senac Tech', status: 'CONFIRMED' }]))
  if (path.endsWith('/company-guests/12/link')) return response(201, { data: { id: 1 } })
  if (path.endsWith('/status')) return response(200, { data: { configured: true, status: 'ACTIVE', external_event_id: '793572', token_configured: true, webhook_configured: false, last_sync_at: null } })
  if (path.endsWith('/tickets')) return response(200, { data: [{ id: 1, external_name: 'Participante', internal_category: 'PARTICIPANT', active: true, registrations_count: 10 }] })
  if (path.endsWith('/sync-runs')) return response(200, PAGE([{ id: 9, status: 'SUCCESS', trigger: 'MANUAL', started_at: new Date().toISOString() }]))
  if (path.endsWith('/event-metadata')) return response(200, { data: { configured: true, fields: [] } })
  return response(200, { data: WORKBENCH() })
}

async function boot(user, handler = defaultHandler) {
  vi.resetModules()
  clearCookies()
  document.body.innerHTML = ''
  const calls = []
  const client = await import('@/js/api/client')
  client.configureClient({
    baseUrl: 'http://api.test',
    fetch: vi.fn(async (url, init) => {
      const path = new URL(url).pathname
      if (path === '/sanctum/csrf-cookie') { document.cookie = 'XSRF-TOKEN=t; path=/'; return response(204) }
      calls.push({ method: init.method, path, body: init.body ? JSON.parse(init.body) : null, signal: init.signal })
      return handler(path, init)
    }),
  })
  const { auth, AUTH } = await import('@/js/stores/auth')
  Object.assign(auth, { status: AUTH.AUTHENTICATED, user, permissions: [...user.permissions] })
  const { eventContext } = await import('@/js/stores/event-context')
  Object.assign(eventContext, { status: 'ready', events: [{ id: 1, name: 'A', days: [] }, { id: 2, name: 'B', days: [] }], activeEventId: 1 })
  const store = await import('@/js/stores/even3-context')
  return { calls, store, eventContext }
}

async function center(user, handler) {
  const ctx = await boot(user, handler)
  const Even3Center = (await import('@/components/Even3Center.vue')).default
  const wrapper = mount(Even3Center, { attachTo: document.body })
  await flushPromises()
  return { wrapper, ...ctx }
}

const workbenchCalls = (calls) => calls.filter((c) => c.path.endsWith('/workbench'))

describe('cabeçalho Even3 (compacto)', () => {
  it('Administrador: estado compacto e ↻ sincroniza na hora, sem confirmação', async () => {
    const { wrapper, calls, store } = await center(ADMIN_FULL)

    expect(wrapper.find('.even3-btn').text()).toContain('Even3')
    expect(wrapper.find('.even3-btn-state').text()).toBe('Atualizado agora')
    expect(wrapper.find('.even3-attention').text()).toBe('⚠ 2')

    calls.length = 0
    await wrapper.find('.even3-sync-btn').trigger('click')
    await flushPromises()
    const sync = calls.filter((c) => c.path.endsWith('/sync'))
    expect(sync).toEqual([expect.objectContaining({ method: 'POST', path: '/api/v1/events/1/integrations/even3/sync', body: { scope: 'ALL' } })])
    expect(document.querySelector('.modal')).toBeNull() // nada de "tem certeza?"
    expect(store.even3Context.lastResult.lines.map((l) => l.text)).toEqual([
      'inscrição(ões) nova(s)', 'inscrição(ões) atualizada(s)', 'jurado(s) adicionado(s)', 'pessoa(s) do público disponível(is) para primeiro acesso',
    ])

    await wrapper.find('.even3-btn').trigger('click')
    expect(wrapper.find('.even3-result').text()).toContain('31 inscrição(ões) atualizada(s)')
    expect(wrapper.find('.even3-result').text()).toContain('2 item(ns) precisam de atenção')
    expect(wrapper.text()).not.toMatch(/fila|candidato|reconcili/i)
  })

  it('Consultor vê o estado, sem sincronizar', async () => {
    const { wrapper } = await center(CONSULTANT)
    expect(wrapper.find('.even3-btn').exists()).toBe(true)
    expect(wrapper.find('.even3-sync-btn').exists()).toBe(false)
  })

  it.each([['Gestor', MANAGER], ['Jurado', JUROR], ['Votante', VOTER], ['Editor', { ...MANAGER, role: { code: 'EDITOR', name: 'Editor' } }]])('%s não vê a Even3 nem consulta o resumo', async (_, user) => {
    const { wrapper, calls } = await center(user)
    expect(wrapper.find('.even3-btn').exists()).toBe(false)
    expect(workbenchCalls(calls)).toHaveLength(0)
  })

  it.each([
    ['não configurado', WORKBENCH({ configured: false, totals: null, attention: null }), 'Não configurado'],
    ['sincronizando', WORKBENCH({ sync: { running: true, last_sync_at: new Date().toISOString(), last_sync_status: 'SUCCESS' } }), 'Sincronizando…'],
    ['falhou', WORKBENCH({ sync: { running: false, last_sync_at: new Date().toISOString(), last_sync_status: 'FAILED' } }), 'Última sincronização falhou'],
  ])('estado: %s', async (_, data, text) => {
    const { wrapper, store } = await center(ADMIN_FULL, () => response(200, { data }))
    expect(wrapper.find('.even3-btn-state').text()).toBe(text)
    store.resetEven3()
  })

  it('erro no resumo não trava o sistema e permite tentar de novo', async () => {
    let fail = true
    const { wrapper } = await center(ADMIN_FULL, (path) => (fail ? response(500, { message: 'x' }) : defaultHandler(path)))
    expect(wrapper.find('.even3-btn-state').text()).toBe('Indisponível')
    await wrapper.find('.even3-btn').trigger('click')
    await flushPromises()
    fail = false
    await wrapper.find('.even3-error button').trigger('click')
    await flushPromises()
    expect(wrapper.find('.even3-btn-state').text()).toBe('Atualizado agora')
  })

  it('trocar de evento cancela o pedido anterior e não mistura contadores', async () => {
    let release
    const { calls, eventContext, store } = await center(ADMIN_FULL, (path) => (path.includes('/events/2/')
      ? new Promise((resolve) => { release = () => resolve(response(200, { data: WORKBENCH({ event_id: 2, attention: { total: 9 } }) })) })
      : defaultHandler(path)))
    eventContext.activeEventId = 2
    await nextTick()
    expect(store.even3Context.attention).toBeNull()
    eventContext.activeEventId = 1
    await nextTick()
    expect(calls.find((c) => c.path.includes('/events/2/')).signal.aborted).toBe(true)
    release()
    await flushPromises()
    expect(store.even3Context.attention.total).toBe(2)
  })

  it('reserva silenciosa: sincroniza sozinho (automatic) só para Administrador e só com mais de 10 min', async () => {
    const old = new Date(Date.now() - 11 * 60_000).toISOString()
    const stale = (path) => (path.endsWith('/sync') ? defaultHandler(path) : response(200, { data: WORKBENCH({ sync: { running: false, last_sync_at: old, last_sync_status: 'SUCCESS' } }) }))
    const admin = await center(ADMIN_FULL, stale)
    await flushPromises()
    expect(admin.calls.filter((c) => c.path.endsWith('/sync')).map((c) => c.body)).toEqual([{ scope: 'ALL', automatic: true }])
    expect(admin.store.even3Context.lastResult).toBeNull() // automático não interrompe ninguém

    const consultant = await center(CONSULTANT, stale)
    await flushPromises()
    expect(consultant.calls.some((c) => c.path.endsWith('/sync'))).toBe(false)

    const recent = await center(ADMIN_FULL)
    await flushPromises()
    expect(recent.calls.some((c) => c.path.endsWith('/sync'))).toBe(false)
  })
})

describe('tela Presença', () => {
  async function presence(user = ADMIN_FULL, params = {}, handler = defaultHandler) {
    const ctx = await boot(user, handler)
    ctx.store.bindEven3Context()
    const Attendance = (await import('@/pages/Attendance.vue')).default
    const wrapper = mount(Attendance, { props: { params }, attachTo: document.body })
    await flushPromises()
    return { wrapper, ...ctx }
  }

  it('indicadores, lista com Presente/Sem registro e só as exceções em "Precisa de atenção"', async () => {
    const { wrapper } = await presence()
    const stats = wrapper.findAll('.stat').map((s) => s.text())
    expect(stats.join(' ')).toMatch(/Inscritos\s*187/)
    expect(stats.join(' ')).toMatch(/Presentes\s*142/)
    expect(stats.join(' ')).toMatch(/Sem registro\s*45/)
    expect(stats.join(' ')).toMatch(/Pendências\s*2/)
    expect(wrapper.find('.even3-attention-card h3').text()).toBe('Precisa de atenção (2)')
    expect(wrapper.text()).toContain('Participante não localizado')
    expect(wrapper.text()).toContain('Representante sem empresa')
    const rows = wrapper.findAll('.attendance-table tbody tr').map((tr) => tr.text())
    expect(rows[0]).toContain('Presente')
    expect(rows[1]).toContain('Sem registro')
    expect(wrapper.text()).not.toMatch(/Ausente|Materializar|Reconcilia/i)
  })

  it('vincular representante: escolher a empresa e pronto', async () => {
    const { wrapper, calls } = await presence()
    const item = wrapper.findAll('.recon-item').find((li) => li.text().includes('Maria Silva'))
    await item.find('select').setValue(5)
    await item.find('button').trigger('click')
    await flushPromises()
    expect(calls.find((c) => c.path.endsWith('/company-guests/12/link')).body).toEqual({ company_id: 5 })
  })

  it('Sincronizar inicia na hora e mostra o resultado resumido', async () => {
    const { wrapper, calls } = await presence()
    const button = wrapper.findAll('button').find((b) => b.text() === 'Sincronizar')
    await button.trigger('click')
    await flushPromises()
    expect(calls.filter((c) => c.path.endsWith('/sync'))).toHaveLength(1)
    expect(document.querySelector('.modal')).toBeNull()
    expect(wrapper.find('.even3-result').text()).toContain('✓ Sincronização concluída')
    expect(wrapper.find('.even3-result').text()).toContain('2 jurado(s) adicionado(s)')
  })

  it('configuração técnica fica num painel secundário (entradas e histórico)', async () => {
    const { wrapper } = await presence(ADMIN_FULL, { config: '1' })
    const drawer = document.querySelector('.sheet')
    expect(drawer.textContent).toContain('Configuração Even3')
    expect(drawer.textContent).toContain('Entradas')
    expect(drawer.textContent).toContain('Histórico')
    expect(drawer.textContent).not.toMatch(/token\s*[:=]|secret/i)
    wrapper.unmount()
  })

  it('Consultor: sem sincronizar, sem vincular', async () => {
    const { wrapper } = await presence(CONSULTANT)
    expect(wrapper.findAll('button').some((b) => b.text() === 'Sincronizar')).toBe(false)
    expect(wrapper.text()).toContain('Precisa de atenção')
    expect(wrapper.find('.recon-item select').exists()).toBe(false)
  })
})

describe('uma única sincronização', () => {
  const SRC = join(__dirname, '..', 'src')
  const read = (path) => readFileSync(join(SRC, path), 'utf8')

  it.each([
    ['Participantes', ['pages/People.vue', 'js/pages/people.js']],
    ['Jurados', ['pages/Jurors.vue', 'js/pages/jurors.js']],
    ['Empresas', ['pages/Companies.vue', 'js/pages/companies.js']],
    ['Usuários', ['pages/Users.vue', 'js/pages/users.js']],
    ['Votação', ['pages/VotingAdmin.vue', 'js/pages/voting-admin.js']],
    ['Evento', ['pages/EventConfig.vue', 'js/pages/event-config.js']],
  ])('%s não sincroniza nem mostra fila da Even3', (_, files) => {
    for (const file of files) {
      expect(read(file), file).not.toMatch(/syncEven3|integrations\/even3|Sincronizar|Even3PageQueue|reconcili/i)
    }
  })

  it('só o store central chama o endpoint de sincronização', () => {
    const offenders = []
    const walk = (dir) => readdirSync(join(SRC, dir), { withFileTypes: true }).forEach((entry) => {
      const rel = `${dir}/${entry.name}`
      if (entry.isDirectory()) return walk(rel)
      if (/\.(js|vue)$/.test(entry.name) && /integrationsApi\.syncEven3/.test(readFileSync(join(SRC, rel), 'utf8'))) offenders.push(rel)
    })
    walk('js')
    walk('pages')
    walk('components')
    expect(offenders).toEqual(['js/stores/even3-context.js'])
  })
})
