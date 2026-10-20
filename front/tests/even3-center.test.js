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
const ADMIN_FULL = { ...ADMIN, permissions: [...ADMIN.permissions, 'integrations.manage', 'integrations.sync', 'jurors.manage', 'users.manage', 'companies.manage', 'participants.manage', 'events.manage'] }

const WORKBENCH = (overrides = {}) => ({
  event_id: 1,
  configured: true,
  integration_status: 'ACTIVE',
  external_event_id: '793572',
  sync: { running: false, running_since: null, last_sync_at: new Date().toISOString(), last_sync_status: 'SUCCESS', last_error_code: null },
  pending: { participants: 0, jurors: 3, organization: 5, company_guests: 4, public_without_access: 27, event_differences: 2 },
  ignored: { jurors: 1, organization: 0, company_guests: 0 },
  unmapped_tickets: 0,
  ...overrides,
})

const RUNS = { data: [{ id: 3, scope: 'ALL', status: 'SUCCESS', started_at: '2026-10-20T12:00:00Z', counters: { participants_created: 2 } }], meta: { current_page: 1, last_page: 1, total: 1 } }

async function setup(user, handler = (path) => (path.endsWith('/sync-runs') ? response(200, RUNS) : response(200, { data: WORKBENCH() }))) {
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
  const Even3Center = (await import('@/components/Even3Center.vue')).default
  const wrapper = mount(Even3Center, { attachTo: document.body })
  await flushPromises()
  return { wrapper, calls, store, eventContext }
}

const workbenchCalls = (calls) => calls.filter((c) => c.path.endsWith('/workbench'))

describe('Centro Even3', () => {
  it('Administrador vê o botão, o estado e as pendências; sincronizar usa o endpoint central', async () => {
    const { wrapper, calls } = await setup(ADMIN_FULL, (path) => (path.endsWith('/sync')
      ? response(201, { data: { id: 9, status: 'SUCCESS' } })
      : response(200, { data: WORKBENCH() })))

    expect(wrapper.find('.even3-btn').text()).toContain('Sincronizar Even3')
    expect(wrapper.find('.even3-btn').text()).toContain('9 pendências') // 3 + 4 + 2 (público não acende o selo; Organização é interna)
    await wrapper.find('.even3-btn').trigger('click')
    await flushPromises()
    const queues = wrapper.findAll('.even3-queues li').map((li) => li.text())
    // Participantes não aparecem (automáticos) e Organização é interna.
    expect(queues).toEqual(['Jurados · 1 ignorado(s)3', 'Empresa / Convidado4', 'Público sem acesso27', 'Divergências do evento2'])
    // Histórico das últimas sincronizações no próprio painel.
    expect(calls.some((c) => c.path.endsWith('/sync-runs'))).toBe(true)
    // Painel só com resumo: nenhuma lista de pessoas foi pedida.
    expect(calls.some((c) => c.path.includes('/reconciliation/'))).toBe(false)

    calls.length = 0
    await wrapper.find('.even3-sync').trigger('click')
    await flushPromises()
    const sync = calls.filter((c) => c.path.endsWith('/sync'))
    expect(sync).toHaveLength(1)
    expect(sync[0]).toMatchObject({ method: 'POST', path: '/api/v1/events/1/integrations/even3/sync', body: { scope: 'ALL' } })
    expect(workbenchCalls(calls)).toHaveLength(1) // recarrega o resumo depois de sincronizar
  })

  it('Consultor vê estado e pendências, sem sincronizar', async () => {
    const { wrapper } = await setup(CONSULTANT)
    expect(wrapper.find('.even3-btn').text()).not.toContain('Sincronizar Even3')
    await wrapper.find('.even3-btn').trigger('click')
    expect(wrapper.find('.even3-sync').exists()).toBe(false)
    expect(wrapper.findAll('.even3-queues li')).toHaveLength(4)
  })

  it.each([['Gestor', MANAGER], ['Jurado', JUROR], ['Votante', VOTER], ['Editor', { ...MANAGER, role: { code: 'EDITOR', name: 'Editor' } }]])('%s não vê o Centro nem consulta o resumo', async (_, user) => {
    const { wrapper, calls } = await setup(user)
    expect(wrapper.find('.even3-btn').exists()).toBe(false)
    expect(workbenchCalls(calls)).toHaveLength(0)
  })

  it.each([
    ['não configurado', WORKBENCH({ configured: false, pending: null, ignored: null }), 'Não configurado'],
    ['sincronizando', WORKBENCH({ sync: { running: true, running_since: '2026-10-20T12:00:00Z', last_sync_at: null, last_sync_status: null } }), 'Sincronizando…'],
    ['falhou', WORKBENCH({ sync: { running: false, last_sync_at: '2026-10-20T12:00:00Z', last_sync_status: 'FAILED', last_error_code: 'UNAUTHORIZED' } }), 'Última sincronização falhou'],
    ['atualizado', WORKBENCH({ pending: { participants: 0, jurors: 0, organization: 0, company_guests: 0, public_without_access: 10, event_differences: 0 } }), 'Atualizado'],
  ])('estado: %s', async (_, data, text) => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const { wrapper, store } = await setup(ADMIN_FULL, () => response(200, { data }))
    expect(wrapper.find('.even3-btn-state').text()).toBe(text)
    store.resetEven3()
    vi.useRealTimers()
  })

  it('não configurado: Administrador vê "Configurar integração", sem sincronizar', async () => {
    const { wrapper } = await setup(ADMIN_FULL, () => response(200, { data: WORKBENCH({ configured: false, pending: null }) }))
    await wrapper.find('.even3-btn').trigger('click')
    expect(wrapper.text()).toContain('Configurar integração')
    expect(wrapper.find('.even3-sync').exists()).toBe(false)
  })

  it('erro no resumo não trava: mostra mensagem e "Tentar novamente"', async () => {
    let fail = true
    const { wrapper, calls } = await setup(ADMIN_FULL, () => (fail ? response(500, { message: 'x' }) : response(200, { data: WORKBENCH() })))
    expect(wrapper.find('.even3-btn-state').text()).toBe('Indisponível')
    await wrapper.find('.even3-btn').trigger('click') // abrir já tenta de novo (e falha de novo)
    expect(wrapper.text()).toContain('Não foi possível consultar o estado da integração.')
    await flushPromises()
    expect(wrapper.text()).toContain('Não foi possível consultar o estado da integração.')
    fail = false
    await wrapper.find('.even3-error button').trigger('click')
    await flushPromises()
    expect(wrapper.find('.even3-btn-state').text()).toBe('9 pendências')
    expect(workbenchCalls(calls)).toHaveLength(3)
  })

  it('sincronização já em andamento (409) vira aviso claro', async () => {
    const { wrapper, store } = await setup(ADMIN_FULL, (path) => (path.endsWith('/sync') ? response(409, { message: 'Já existe' }) : response(200, { data: WORKBENCH() })))
    await wrapper.find('.even3-btn').trigger('click')
    await wrapper.find('.even3-sync').trigger('click')
    await flushPromises()
    expect(store.even3Context.syncError.message).toBe('Sincronização já em andamento.')
    expect(wrapper.text()).toContain('Sincronização já em andamento.')
  })

  it('trocar de evento cancela o pedido anterior, zera e carrega os contadores do novo evento', async () => {
    let release
    const { wrapper, calls, eventContext, store } = await setup(ADMIN_FULL, (path) => (path.includes('/events/2/')
      ? new Promise((resolve) => { release = () => resolve(response(200, { data: WORKBENCH({ event_id: 2, pending: { participants: 1, jurors: 0, organization: 0, company_guests: 0, public_without_access: 0, event_differences: 0 } }) })) })
      : response(200, { data: WORKBENCH() })))
    expect(store.even3Context.pending.jurors).toBe(3)

    eventContext.activeEventId = 2
    await nextTick()
    expect(store.even3Context.pending).toBeNull() // nada do evento anterior
    eventContext.activeEventId = 1
    await nextTick()
    const toEvent2 = calls.find((c) => c.path.includes('/events/2/'))
    expect(toEvent2.signal.aborted).toBe(true)
    release()
    await flushPromises()
    expect(store.even3Context.eventId).toBe(1)
    expect(store.even3Context.pending.jurors).toBe(3)
    expect(wrapper.find('.even3-btn').exists()).toBe(true)
  })

  it('cada pendência leva à página onde é revisada; histórico mostra as últimas sincronizações', async () => {
    const { wrapper } = await setup(ADMIN_FULL)
    await wrapper.find('.even3-btn').trigger('click')
    await flushPromises()
    expect(wrapper.find('.even3-history').text()).toContain('2 participante(s) novo(s)')
    const targets = {}
    for (const [index, label] of ['Jurados', 'Empresa / Convidado', 'Público sem acesso', 'Divergências do evento'].entries()) {
      if (!wrapper.find('.even3-panel').exists()) await wrapper.find('.even3-btn').trigger('click')
      await wrapper.findAll('.even3-queues button')[index].trigger('click')
      targets[label] = window.location.hash
    }
    expect(targets).toEqual({
      Jurados: '#/jurados',
      'Empresa / Convidado': '#/empresas',
      'Público sem acesso': '#/votacao-gestao',
      'Divergências do evento': '#/config',
    })
  })

  it('sincroniza sozinho (automatic) quando a última tem mais de 10 minutos e o usuário é Administrador', async () => {
    const old = new Date(Date.now() - 11 * 60_000).toISOString()
    const { calls } = await setup(ADMIN_FULL, (path) => (path.endsWith('/sync')
      ? response(201, { data: { id: 5, status: 'SUCCESS', finished_at: new Date().toISOString(), counters: { attendees_created: 2, jurors_created: 1 } } })
      : response(200, { data: WORKBENCH({ sync: { running: false, last_sync_at: old, last_sync_status: 'SUCCESS' } }) })))
    await flushPromises()
    const sync = calls.filter((c) => c.path.endsWith('/sync'))
    expect(sync).toHaveLength(1)
    expect(sync[0].body).toEqual({ scope: 'ALL', automatic: true })
  })

  it('não sincroniza sozinho se a última é recente, nem para o Consultor', async () => {
    const recent = await setup(ADMIN_FULL)
    await flushPromises()
    expect(recent.calls.some((c) => c.path.endsWith('/sync'))).toBe(false)
    const old = new Date(Date.now() - 60 * 60_000).toISOString()
    const consultant = await setup(CONSULTANT, () => response(200, { data: WORKBENCH({ sync: { running: false, last_sync_at: old, last_sync_status: 'SUCCESS' } }) }))
    await flushPromises()
    expect(consultant.calls.some((c) => c.path.endsWith('/sync'))).toBe(false)
  })

  it('avisa as novidades da sincronização no botão; abrir o painel mostra e limpa o aviso', async () => {
    const run = { id: 5, status: 'SUCCESS', finished_at: new Date().toISOString(), counters: { attendees_created: 2, attendees_unchanged: 9, participants_created: 1, jurors_created: 1 } }
    const zero = { participants: 0, jurors: 0, organization: 0, company_guests: 0, public_without_access: 0, event_differences: 0 }
    const { wrapper } = await setup(ADMIN_FULL, (path) => (path.endsWith('/sync') ? response(201, { data: run }) : response(200, { data: WORKBENCH({ pending: zero }) })))
    await wrapper.find('.even3-btn').trigger('click')
    await wrapper.find('.even3-sync').trigger('click')
    await flushPromises()
    await wrapper.find('.even3-btn').trigger('click') // fecha
    expect(wrapper.find('.even3-btn-state').text()).toBe('4 novidades')
    await wrapper.find('.even3-btn').trigger('click') // abre: mostra e limpa
    expect(wrapper.find('.even3-news').text()).toContain('2 inscrição(ões) nova(s)')
    expect(wrapper.find('.even3-news').text()).toContain('1 jurado(s) novo(s)')
    expect(wrapper.find('.even3-btn-state').text()).toBe('Atualizado')
  })

  it('abrir o painel não refaz o pedido se o resumo é recente', async () => {
    const { wrapper, calls } = await setup(ADMIN_FULL)
    await wrapper.find('.even3-btn').trigger('click')
    await wrapper.find('.even3-btn').trigger('click')
    await wrapper.find('.even3-btn').trigger('click')
    await flushPromises()
    expect(workbenchCalls(calls)).toHaveLength(1)
  })
})

describe('revisão da Even3 dentro das páginas', () => {
  const CANDIDATES = { data: [{ person: { id: 41, full_name: 'Joana Jurada', email: 'joana@teste.local' }, tickets: ['Jurado'], state: 'PENDING', internal: { juror: null }, decision: null }], meta: { current_page: 1, last_page: 1, total: 1 } }

  async function queue(user, fila, pending, ignored = { jurors: 0, organization: 0, company_guests: 0 }) {
    const { wrapper: center, store, calls } = await setup(user, (path) => (path.includes('/reconciliation/')
      ? response(200, CANDIDATES)
      : response(200, { data: WORKBENCH({ pending, ignored }) })))
    center.unmount()
    const Queue = (await import('@/components/Even3PageQueue.vue')).default
    const wrapper = mount(Queue, { props: { fila } })
    await flushPromises()
    return { wrapper, store, calls }
  }

  it('Jurados: mostra os candidatos com as ações, sem botão de sincronizar', async () => {
    const { wrapper, calls } = await queue(ADMIN_FULL, 'jurados', WORKBENCH().pending)
    expect(wrapper.text()).toContain('Inscritos como Jurado na Even3')
    expect(wrapper.text()).toContain('Joana Jurada')
    expect(wrapper.text()).toContain('Cadastrar como Jurado')
    expect(wrapper.text()).toContain('Ignorar')
    expect(wrapper.text()).not.toMatch(/Sincronizar/)
    expect(calls.some((c) => c.path === '/api/v1/events/1/integrations/even3/reconciliation/jurors')).toBe(true)
    expect(calls.some((c) => c.path.endsWith('/sync'))).toBe(false)
  })

  it('não aparece sem pendência nem ignorado, nem para quem não pode reconciliar', async () => {
    const zero = await queue(ADMIN_FULL, 'jurados', { ...WORKBENCH().pending, jurors: 0 })
    expect(zero.wrapper.find('.even3-queue').exists()).toBe(false)
    const consultant = await queue(CONSULTANT, 'empresas', WORKBENCH().pending)
    expect(consultant.wrapper.find('.even3-queue').exists()).toBe(false)
  })

  it('continua visível só com ignorados (para poder restaurar) e some quando zera', async () => {
    const { wrapper, store } = await queue(ADMIN_FULL, 'empresas', { ...WORKBENCH().pending, company_guests: 0 }, { jurors: 0, organization: 0, company_guests: 1 })
    expect(wrapper.find('.even3-queue').exists()).toBe(true)
    store.even3Context.ignored = { jurors: 0, organization: 0, company_guests: 0 }
    await nextTick()
    expect(wrapper.find('.even3-queue').exists()).toBe(false)
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
    ['Inscrições e Presença', ['pages/Attendance.vue', 'js/pages/attendance.js']],
  ])('%s não tem botão nem chamada de sincronização própria', (_, files) => {
    for (const file of files) {
      const text = read(file)
      // Nenhuma chamada de sync e nenhum botão "Sincronizar…" (texto de ajuda apontando para o topo é permitido).
      expect(text, file).not.toMatch(/syncEven3|integrations\/even3\/sync|>\s*Sincronizar/)
    }
  })

  it('só o store central chama o endpoint de sincronização', () => {
    const offenders = []
    const walk = (dir) => readdirSync(join(SRC, dir), { withFileTypes: true }).forEach((entry) => {
      const rel = `${dir}/${entry.name}`
      if (entry.isDirectory()) return walk(rel)
      if (/\.(js|vue)$/.test(entry.name) && /integrationsApi\.syncEven3|from '@\/js\/services\/integrations'[^\n]*syncEven3/.test(readFileSync(join(SRC, rel), 'utf8'))) offenders.push(rel)
    })
    walk('js')
    walk('pages')
    walk('components')
    expect(offenders).toEqual(['js/stores/even3-context.js'])
  })

  it('não existe mais botão de materializar participantes (automático ao sincronizar)', () => {
    for (const file of ['pages/Attendance.vue', 'js/pages/attendance.js', 'components/ReconciliationPanel.vue', 'js/services/integrations.js']) {
      expect(read(file), file).not.toMatch(/materializ/i)
    }
  })
})
