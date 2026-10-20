import { describe, expect, it } from 'vitest'
import { canAccess, homeFor, legacyTarget, navFor, navState, sectionTabsFor } from '@/js/config/access'
import { ADMIN, JUROR, MANAGER, VOTER } from './helpers'

const labels = (user) => navFor(user).flatMap((group) => group.items.flatMap((item) => (item.children ? item.children.map((child) => child.label) : [item.label])))

describe('navegação por permissão', () => {
  it('administrador vê as áreas das permissões que tem', () => {
    const nav = labels(ADMIN)
    expect(nav).toEqual(expect.arrayContaining(['Dashboard', 'Evento', 'Pessoas e Equipes', 'Empresas e Desafios', 'Setores', 'Pendências', 'Julgamento', 'Votação e Resultados', 'Usuários', 'Auditoria']))
    expect(nav).not.toContain('Validador')
    expect(nav).not.toContain('Documentos')
    expect(nav).not.toContain('Relatórios')
    expect(homeFor(ADMIN)).toBe('dashboard')
  })

  it('menu do Administrador: seções planas, no máximo 14 itens, sem submenus', () => {
    const full = { ...ADMIN, permissions: [...ADMIN.permissions, 'attendance.view', 'reports.view'] }
    const nav = navFor(full)
    expect(nav.map((group) => group.group)).toEqual(['Dashboard', 'Preparação', 'Operação', 'Hackathon', 'Gestão'])
    const items = nav.flatMap((group) => group.items)
    expect(items.length).toBeLessThanOrEqual(14)
    expect(items.every((item) => !item.children)).toBe(true)
    expect(items.map((item) => item.label)).toContain('Presença')
  })

  it('páginas irmãs viram abas; o item do menu leva à primeira página permitida', () => {
    expect(sectionTabsFor('equipes', ADMIN)).toEqual([
      { id: 'participantes', label: 'Participantes', active: false },
      { id: 'equipes', label: 'Equipes', active: true },
    ])
    expect(sectionTabsFor('montar', ADMIN).find((tab) => tab.active).id).toBe('equipes')
    expect(sectionTabsFor('resultados', ADMIN).map((tab) => tab.label)).toEqual(['Votação', 'Resultados'])
    expect(navState('equipes', ADMIN)).toEqual({ item: 'participantes', group: '' })
    expect(navState('avaliacoes', ADMIN)).toEqual({ item: 'jurados', group: '' })
    expect(sectionTabsFor('dashboard', ADMIN)).toEqual([])
    // Gestor não vê Jurados: "Julgamento" leva direto a Avaliações, sem abas.
    const julgamento = navFor(MANAGER).flatMap((group) => group.items).find((item) => item.label === 'Julgamento')
    expect(julgamento.id).toBe('avaliacoes')
    expect(sectionTabsFor('avaliacoes', MANAGER)).toEqual([])
  })

  it('endereços antigos de Inscrições e Presença levam à Presença', () => {
    expect(legacyTarget('integracao')).toBe('presenca?config=1')
    expect(legacyTarget('entradas')).toBe('presenca?config=1')
    expect(legacyTarget('inscricoes')).toBe('presenca')
    expect(legacyTarget('presenca', { aba: 'sincronizacao' })).toBe('presenca?config=1')
    expect(legacyTarget('presenca', { aba: 'reconciliacao', fila: 'jurados' })).toBe('presenca')
    expect(legacyTarget('presenca', {})).toBe('')
    expect(legacyTarget('presenca', { config: '1' })).toBe('')
  })

  it('gestor não vê usuários, auditoria nem evento; setor vira "Meu setor"', () => {
    const nav = labels(MANAGER)
    expect(nav).toEqual(expect.arrayContaining(['Pendências', 'Ocorrências', 'Meu setor', 'Julgamento']))
    expect(nav).not.toContain('Usuários')
    expect(nav).not.toContain('Auditoria')
    expect(nav).not.toContain('Evento')
    expect(nav).not.toContain('Presença')
    expect(canAccess(MANAGER, 'auditoria')).toBe(false)
    expect(canAccess(MANAGER, 'pendencias')).toBe(true)
  })

  it('jurado e votante ficam na experiência focada', () => {
    expect(navFor(JUROR)).toEqual([])
    expect(homeFor(JUROR)).toBe('area-jurado')
    expect(canAccess(JUROR, 'avaliar')).toBe(true)
    expect(canAccess(JUROR, 'usuarios')).toBe(false)
    expect(canAccess(JUROR, 'painel')).toBe(true)
    expect(homeFor(VOTER)).toBe('votacao')
    expect(canAccess(VOTER, 'votacao')).toBe(true)
    expect(canAccess(VOTER, 'dashboard')).toBe(false)
  })

  it('Votante com results.view_published acessa o resultado publicado, mantendo a votação como início', () => {
    expect(homeFor(VOTER)).toBe('votacao')
    expect(canAccess(VOTER, 'painel')).toBe(true)
    // Nada administrativo: prévia/publicação/revogação/histórico ficam em #/resultados; apresentação e contagens também não.
    for (const route of ['resultados', 'apresentacao', 'votacao-gestao', 'avaliacoes', 'dashboard']) {
      expect(canAccess(VOTER, route)).toBe(false)
    }
    const semPermissao = { ...VOTER, permissions: VOTER.permissions.filter((code) => code !== 'results.view_published') }
    expect(canAccess(semPermissao, 'painel')).toBe(false)
  })

  it('administrador não vota (sem votes.cast)', () => {
    expect(canAccess(ADMIN, 'votacao')).toBe(false)
  })

  it('rotas removidas do protótipo não existem', () => {
    for (const route of ['cadastro', 'documentos', 'premiacao', 'roletas', 'manifestacao', 'financeiro']) {
      expect(canAccess(ADMIN, route)).toBe(false)
    }
  })

  it('usuário sem nenhuma área não tem página inicial', () => {
    expect(homeFor({ ...ADMIN, role: { code: 'CONSULTANT' }, permissions: [] })).toBe('')
  })

  it('rotas antigas redirecionam', () => {
    expect(legacyTarget('inicio')).toBe('dashboard')
    expect(legacyTarget('validar')).toBe('presenca')
    expect(legacyTarget('encerramento', { aba: 'votacao' })).toBe('votacao-gestao')
    expect(legacyTarget('usuarios')).toBe('')
  })

  it('detalhes acendem o item de navegação do módulo', () => {
    expect(navState('empresa', ADMIN)).toEqual({ item: 'empresas', group: '' })
    expect(navState('auditoria', ADMIN)).toEqual({ item: 'auditoria', group: '' })
  })
})
