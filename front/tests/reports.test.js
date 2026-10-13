import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { canAccess, navFor } from '@/js/config/access'
import { ADMIN, JUROR, MANAGER, VOTER, clearCookies, response } from './helpers'
import FIXTURES from './fixtures/reports.json'

const CONSULTANT = { ...MANAGER, id: 7, sector: null, role: { code: 'CONSULTANT', name: 'Consultor' }, permissions: ['participants.view', 'tasks.view', 'reports.view'] }
const ADMIN_REPORTS = { ...ADMIN, permissions: [...ADMIN.permissions, 'reports.view'] }

// Payload "com publicação" no formato real do snapshot oficial.
const PUBLISHED = {
  ...FIXTURES['closing-results'],
  official_result: {
    published: true,
    version: 2,
    published_at: '2026-11-13T21:00:00+00:00',
    voting_session: { id: 3, name: 'Escolha do Público', status: 'CLOSED' },
    technical: { teams: [{ rank: 1, team: { id: 2, name: 'Equipe Técnica' }, tied: false, technical_score: 91.5, valid_evaluations: 3 }], has_ties: false },
    popular: { teams: [{ rank: 1, team: { id: 1, name: 'Equipe do Público' }, tied: false, vote_count: 7, percentage: 70 }], has_ties: false, total_votes: 10 },
    turnout: { eligible_voters: 12, votes_cast: 10, pending_voters: 2, turnout_percent: 83.33 },
    rules: { technical: 'juror_evaluations', popular: 'one_person_one_vote', combined: false },
  },
}

function allText(node) {
  if (node === null || node === undefined) return ''
  if (typeof node === 'string' || typeof node === 'number') return String(node)
  if (Array.isArray(node)) return node.map(allText).join(' ')
  if (typeof node === 'object') return Object.entries(node).filter(([key]) => !['images', 'background', 'header', 'footer', 'pageBreakBefore', 'layout'].includes(key)).map(([, value]) => allText(value)).join(' ')
  return ''
}

describe('acesso à Central de Relatórios', () => {
  it('reports.view libera a rota e o item "Relatórios"', () => {
    for (const user of [ADMIN_REPORTS, CONSULTANT]) {
      expect(canAccess(user, 'relatorios')).toBe(true)
      expect(navFor(user).flatMap((group) => group.items.map((item) => item.label))).toContain('Relatórios')
    }
  })

  it('sem reports.view não acessa (Gestor, Jurado, Votante, admin sem a permissão)', () => {
    for (const user of [MANAGER, JUROR, VOTER, ADMIN]) {
      expect(canAccess(user, 'relatorios')).toBe(false)
    }
    expect(navFor(MANAGER).flatMap((group) => group.items.map((item) => item.label))).not.toContain('Relatórios')
  })
})

describe('catálogo e service', () => {
  it('tem exatamente os quatro relatórios e nenhum financeiro', async () => {
    const { REPORT_MODELS } = await import('@/js/services/reports/pdf')
    expect(REPORT_MODELS.map((item) => item.id)).toEqual(['general', 'participants-presence', 'operations', 'closing-results'])
    expect(REPORT_MODELS.map((item) => item.title)).toEqual(['Relatório Geral do Hackathon', 'Participantes e Presença', 'Gestão e Operação', 'Encerramento e Resultados'])
    expect(JSON.stringify(REPORT_MODELS.map(({ id, title, text }) => ({ id, title, text })))).not.toMatch(/financ/i)
  })

  it('cada relatório chama um único endpoint agregado', async () => {
    vi.resetModules()
    clearCookies()
    const calls = []
    const client = await import('@/js/api/client')
    client.configureClient({ baseUrl: 'http://api.test', fetch: vi.fn(async (url) => { calls.push(new URL(url).pathname); return response(200, { data: { type: 'x' } }) }) })
    const api = await import('@/js/services/reports')
    await api.general(5)
    await api.participantsPresence(5)
    await api.operations(5)
    await api.closingResults(5)
    expect(calls).toEqual([
      '/api/v1/events/5/reports/general',
      '/api/v1/events/5/reports/participants-presence',
      '/api/v1/events/5/reports/operations',
      '/api/v1/events/5/reports/closing-results',
    ])
  })
})

describe('templates de PDF (funções puras sobre o payload do backend)', () => {
  it('Relatório Geral: título, resumo e seções a partir do payload', async () => {
    const { reportDefinition } = await import('@/js/services/reports/pdf')
    const payload = FIXTURES.general
    const { report, definition, fileName } = reportDefinition('general', payload)
    expect(report.title).toBe('Relatório Geral do Hackathon')
    expect(report.summary).toContainEqual(['Participantes cadastrados', String(payload.summary.participants.total)])
    expect(report.summary).toContainEqual(['Resultado oficial publicado', 'Não'])
    expect(report.sections.map((section) => section.title)).toEqual(['Participantes e equipes', 'Empresas e desafios', 'Gestão', 'Inscrições e presença', 'Encerramento'])
    const textContent = allText(definition.content)
    expect(textContent).toContain(payload.event.name)
    expect(textContent).toContain('HackLab — dados registrados no backend')
    expect(textContent).toContain(payload.generated_by.name)
    expect(textContent).not.toMatch(/demo|demonstra|protótipo|financ/i)
    expect(fileName).toMatch(/^HackLab_Relatorio_Geral_HackLab_Exemplo_dev_\d{4}-\d{2}-\d{2}\.pdf$/)
  })

  it('usa generated_at do backend (no fuso do evento), não o relógio do navegador', async () => {
    const { reportContext, reportFileName } = await import('@/js/services/reports/pdf')
    const payload = { ...FIXTURES.general, generated_at: '2026-11-12T02:30:00+00:00', event: { ...FIXTURES.general.event, timezone: 'America/Sao_Paulo' } }
    expect(reportContext(payload).generatedAt).toBe('11/11/2026, 23:30')
    expect(reportFileName({ file: 'Relatorio_Geral' }, payload)).toBe('HackLab_Relatorio_Geral_HackLab_Exemplo_dev_2026-11-11.pdf')
  })

  it('Participantes e Presença: presença por dia como "Presente" / "Sem registro", nunca "Ausente"', async () => {
    const { reportDefinition } = await import('@/js/services/reports/pdf')
    const base = FIXTURES['participants-presence']
    const [day1, day2] = base.event.days
    const payload = {
      ...base,
      participants: [
        { id: 1, name: 'Ana Teste', status: 'AVAILABLE', class: { id: 1, name: 'Turma A' }, team: { id: 1, name: 'Equipe Alfa' }, presence: { [day1.id]: 'PRESENT', [day2.id]: 'NO_RECORD' } },
      ],
    }
    const { report } = reportDefinition('participants-presence', payload)
    const list = report.sections.find((section) => section.title === 'Lista de participantes').blocks[0]
    expect(list.head).toEqual(['Participante', 'Turma', 'Status', 'Equipe', ...base.event.days.map((day) => day.label)])
    expect(list.rows[0].slice(0, 6)).toEqual(['Ana Teste', 'Turma A', 'Disponível', 'Equipe Alfa', 'Presente', 'Sem registro'])
    expect(JSON.stringify(report)).not.toMatch(/Ausente|ABSENT/)
  })

  it('tabelas largas cabem em A4: ocorrência com linha de detalhe e presença compacta com muitos dias', async () => {
    const { reportDefinition } = await import('@/js/services/reports/pdf')
    const operations = reportDefinition('operations', FIXTURES.operations).report
    const list = operations.sections.find((section) => section.title === 'Ocorrências').blocks.find((block) => block.title === 'Lista').blocks[0]
    expect(list.head).toEqual(['Ref.', 'Ocorrência', 'Categoria', 'Setor', 'Prioridade', 'Status'])
    const resolved = FIXTURES.operations.occurrences.items.findIndex((item) => item.status === 'RESOLVED')
    expect(list.rows[resolved][1].sub).toContain('Solução:')

    const base = FIXTURES['participants-presence']
    const days = [1, 2, 3, 4, 5].map((n) => ({ id: 100 + n, day_number: n, date: `2026-11-1${n}`, label: `Dia ${n}` }))
    const payload = { ...base, event: { ...base.event, days }, participants: [{ id: 1, name: 'Ana', status: 'AVAILABLE', class: null, team: null, presence: { 101: 'PRESENT', 102: 'NO_RECORD', 103: 'NO_RECORD', 104: 'NO_RECORD', 105: 'PRESENT' } }] }
    const table = reportDefinition('participants-presence', payload).report.sections.find((section) => section.title === 'Lista de participantes').blocks[0]
    expect(table.rows[0].slice(4)).toEqual(['Sim', '—', '—', '—', 'Sim'])
    expect(table.widths.slice(4)).toEqual([34, 34, 34, 34, 34])
  })

  it('Gestão e Operação: seções reais, sem atas/documentos/finanças como seção', async () => {
    const { reportDefinition } = await import('@/js/services/reports/pdf')
    const { report } = reportDefinition('operations', FIXTURES.operations)
    expect(report.sections.map((section) => section.title)).toEqual(['Setores', 'Reuniões', 'Empresas, desafios e equipes', 'Pendências', 'Ocorrências'])
    expect(JSON.stringify(report)).not.toMatch(/financ|demo/i)
  })

  it('Encerramento: técnico e público separados, só do snapshot publicado', async () => {
    const { reportDefinition } = await import('@/js/services/reports/pdf')
    const unpublished = reportDefinition('closing-results', FIXTURES['closing-results']).report
    const official = unpublished.sections.find((section) => section.title === 'Resultado oficial')
    expect(official.blocks).toEqual([{ kind: 'note', text: 'Resultado oficial ainda não publicado.' }])

    const { report } = reportDefinition('closing-results', PUBLISHED)
    const blocks = report.sections.find((section) => section.title === 'Resultado oficial').blocks
    const technical = blocks.find((block) => block.title === 'Ranking técnico (jurados)')
    const popular = blocks.find((block) => block.title === 'Escolha do Público')
    expect(technical.blocks[0].rows[0]).toEqual(['1º', 'Equipe Técnica', '91,50', '3'])
    expect(popular.blocks[0].rows[0][1]).toBe('Equipe do Público')
    expect(JSON.stringify(report)).toContain('não existe nota combinada')
    expect(JSON.stringify(report)).not.toMatch(/financ|demo/i)
  })
})

describe('Central de Relatórios (página)', () => {
  let created
  let revoked

  beforeEach(() => {
    created = []
    revoked = []
    URL.createObjectURL = vi.fn(() => { const url = `blob:test/${created.length + 1}`; created.push(url); return url })
    URL.revokeObjectURL = vi.fn((url) => revoked.push(url))
  })

  async function setup(handler) {
    vi.resetModules()
    clearCookies()
    const client = await import('@/js/api/client')
    client.configureClient({ baseUrl: 'http://api.test', fetch: vi.fn(async (url) => handler(new URL(url).pathname)) })
    const { eventContext } = await import('@/js/stores/event-context')
    Object.assign(eventContext, { status: 'ready', events: [{ id: 1, name: 'A', days: [] }, { id: 2, name: 'B', days: [] }], activeEventId: 1 })
    const { useReports } = await import('@/js/pages/reports')
    const render = vi.fn(async (id, payload) => ({ blob: new Blob(['%PDF']), fileName: `${id}-${payload.event.id}.pdf`, title: payload.title }))
    const download = vi.fn()
    let page
    const wrapper = mount(defineComponent({ setup() { page = useReports({ render, download }); return () => h('div') } }))
    return { page, render, download, eventContext, wrapper }
  }

  const payloadFor = (path) => {
    const eventId = Number(path.split('/')[4])
    return response(200, { data: { ...FIXTURES.general, event: { ...FIXTURES.general.event, id: eventId } } })
  }

  it('o PDF recebe exatamente o payload do backend e a preview usa Blob URL', async () => {
    const { page, render } = await setup(payloadFor)
    await page.view('general')
    expect(render).toHaveBeenCalledWith('general', expect.objectContaining({ type: 'general', event: expect.objectContaining({ id: 1 }) }))
    expect(page.preview.value).toMatchObject({ id: 'general', url: 'blob:test/1', fileName: 'general-1.pdf' })
  })

  it('gerar de novo revoga a Blob URL anterior; desmontar revoga tudo', async () => {
    const { page, wrapper } = await setup(payloadFor)
    await page.view('general')
    await page.view('general')
    expect(revoked).toEqual(['blob:test/1'])
    wrapper.unmount()
    expect(revoked).toEqual(['blob:test/1', 'blob:test/2'])
  })

  it('trocar o evento fecha a preview, revoga URLs e não baixa relatório do evento anterior', async () => {
    let hold = false
    let release
    const { page, download, eventContext } = await setup((path) => (hold
      ? new Promise((resolve) => { release = () => resolve(payloadFor(path)) })
      : payloadFor(path)))
    await page.view('operations') // preview aberta do evento 1
    expect(page.preview.value).not.toBeNull()
    hold = true
    const pending = page.exportPdf('general') // download do evento 1 ainda carregando...
    await flushPromises()
    eventContext.activeEventId = 2 // ...quando o usuário troca para o evento 2
    await nextTick()
    hold = false
    release()
    await pending
    await flushPromises()
    expect(download).not.toHaveBeenCalled()
    expect(page.preview.value).toBeNull()
    expect(revoked).toContain('blob:test/1')
    expect(Object.values(page.rows).every((row) => row.result === null && row.busy === '')).toBe(true)
    // Novo download já é do evento 2.
    await page.exportPdf('general')
    expect(download).toHaveBeenCalledWith(expect.objectContaining({ fileName: 'general-2.pdf' }))
  })

  it('403 mostra "sem permissão" só naquele relatório; erro de rede permite tentar de novo', async () => {
    let fail = true
    const { page } = await setup((path) => {
      if (path.endsWith('/operations')) return response(403, { message: 'Acesso negado.' })
      if (path.endsWith('/general') && fail) throw new TypeError('Failed to fetch')
      return payloadFor(path)
    })
    await page.view('operations')
    expect(page.rows.operations.error.kind).toBe('forbidden')
    await page.view('general')
    expect(page.rows.general.error.kind).toBe('network')
    expect(page.rows.general.busy).toBe('')
    // Os outros continuam funcionando.
    await page.view('closing-results')
    expect(page.rows['closing-results'].error).toBeNull()
    fail = false
    await page.retry('general')
    expect(page.rows.general.error).toBeNull()
    expect(page.rows.general.result).not.toBeNull()
  })
})
