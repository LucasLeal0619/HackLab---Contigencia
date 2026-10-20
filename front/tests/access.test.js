import { describe, expect, it } from 'vitest'
import { canAccess, homeFor, legacyTarget, navFor, navState } from '@/js/config/access'
import { ADMIN, JUROR, MANAGER, VOTER } from './helpers'

const labels = (user) => navFor(user).flatMap((group) => group.items.flatMap((item) => (item.children ? item.children.map((child) => child.label) : [item.label])))

describe('navegação por permissão', () => {
  it('administrador vê as áreas das permissões que tem', () => {
    const nav = labels(ADMIN)
    expect(nav).toEqual(expect.arrayContaining(['Dashboard', 'Evento', 'Participantes', 'Usuários e Permissões', 'Auditoria', 'Integração', 'Entradas']))
    expect(nav).not.toContain('Validador')
    expect(nav).not.toContain('Documentos')
    expect(nav).not.toContain('Relatórios')
    expect(homeFor(ADMIN)).toBe('dashboard')
  })

  it('Inscrições e Presença é submenu (Integração, Entradas, Inscrições, Presença) e links antigos continuam valendo', () => {
    const full = { ...ADMIN, permissions: [...ADMIN.permissions, 'event_registrations.view', 'attendance.view'] }
    const group = navFor(full).flatMap((g) => g.items).find((item) => item.id === 'inscricoes-presenca')
    expect(group.label).toBe('Inscrições e Presença')
    expect(group.children.map((c) => c.id)).toEqual(['integracao', 'entradas', 'inscricoes', 'presenca'])
    expect(navState('entradas', full)).toEqual({ item: 'entradas', group: 'inscricoes-presenca' })
    expect(legacyTarget('presenca', { aba: 'integracao' })).toBe('integracao')
    expect(legacyTarget('presenca', { aba: 'sincronizacao' })).toBe('integracao')
    expect(legacyTarget('presenca', { aba: 'reconciliacao', fila: 'jurados' })).toBe('jurados')
    expect(legacyTarget('presenca', { aba: 'reconciliacao', fila: 'organizacao' })).toBe('usuarios')
    expect(legacyTarget('presenca', {})).toBe('')
    expect(legacyTarget('presenca', { aba: 'presenca' })).toBe('')
  })

  it('gestor não vê usuários, auditoria nem evento; setor vira "Meu setor"', () => {
    const nav = labels(MANAGER)
    expect(nav).toEqual(expect.arrayContaining(['Pendências', 'Ocorrências', 'Meu setor', 'Avaliações']))
    expect(nav).not.toContain('Usuários e Permissões')
    expect(nav).not.toContain('Auditoria')
    expect(nav).not.toContain('Evento')
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
    expect(navState('empresa', ADMIN)).toEqual({ item: 'empresas', group: 'preparacao' })
    expect(navState('auditoria', ADMIN)).toEqual({ item: 'auditoria', group: '' })
  })
})
