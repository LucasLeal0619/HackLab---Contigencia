// Navegação e rotas a partir das permissões de /auth/me. Esconder um item não é segurança:
// o backend valida tudo (Policies). role.code só escolhe a experiência visual (Jurado e Votante em tela focada).

// Rota → permissões (basta uma). Rotas fora desta tabela não existem no app.
export const ROUTES = {
  dashboard: ['participants.view', 'teams.view', 'tasks.view', 'occurrences.view', 'sectors.view', 'users.view'],
  config: ['events.manage'],
  usuarios: ['users.view'],
  participantes: ['participants.view'],
  equipes: ['teams.view'],
  montar: ['teams.manage'],
  empresas: ['companies.view'],
  empresa: ['companies.view'],
  desafios: ['challenges.view'],
  desafio: ['challenges.view'],
  distribuicao: ['challenges.view'],
  setores: ['sectors.view'],
  reunioes: ['meetings.view'],
  reuniao: ['meetings.view'],
  pendencias: ['tasks.view'],
  ocorrencias: ['occurrences.view'],
  presenca: ['integrations.view', 'event_registrations.view', 'attendance.view'],
  jurados: ['jurors.view', 'evaluation_criteria.manage'],
  avaliacoes: ['evaluations.progress.view', 'evaluations.all.view'],
  'votacao-gestao': ['voting_sessions.view'],
  resultados: ['results.preview', 'results.view_published'],
  painel: ['results.view_published'],
  apresentacao: ['results.view_published'],
  auditoria: ['audit_logs.view'],
  relatorios: ['reports.view'],
  'area-jurado': ['evaluations.own'],
  avaliar: ['evaluations.own'],
  votacao: ['votes.cast'],
}

// Páginas abertas sem o Shell administrativo (tela cheia ou focada).
export const BARE_ROUTES = new Set(['area-jurado', 'avaliar', 'votacao', 'apresentacao'])

// Perfis com experiência focada (sem Sidebar): rota inicial e rotas da experiência.
// O resultado oficial publicado (painel) também fica disponível a eles (results.view_published).
export const FOCUS = {
  JUROR: { home: 'area-jurado', title: 'Área do Jurado', routes: ['area-jurado', 'avaliar', 'painel'], tabs: [['area-jurado', 'Minhas avaliações'], ['painel', 'Resultado oficial']] },
  VOTER: { home: 'votacao', title: 'Votação do Público', routes: ['votacao', 'painel'], tabs: [['votacao', 'Votação'], ['painel', 'Resultado oficial']] },
}

const NAV = [
  { group: 'Dashboard', items: [{ id: 'dashboard', label: 'Dashboard', icon: 'home' }] },
  {
    group: 'Organização',
    items: [
      {
        id: 'preparacao',
        label: 'Preparação',
        icon: 'sliders',
        children: [
          { id: 'config', label: 'Evento' },
          { id: 'participantes', label: 'Participantes' },
          { id: 'equipes', label: 'Equipes' },
          { id: 'empresas', label: 'Empresas' },
          { id: 'desafios', label: 'Desafios' },
        ],
      },
      {
        id: 'gestao',
        label: 'Gestão',
        icon: 'grid',
        children: [
          { id: 'setores', label: 'Setores' },
          { id: 'reunioes', label: 'Reuniões' },
          { id: 'pendencias', label: 'Pendências' },
          { id: 'ocorrencias', label: 'Ocorrências' },
        ],
      },
    ],
  },
  {
    group: 'Evento',
    items: [
      { id: 'presenca', label: 'Inscrições e Presença', icon: 'ticket' },
      {
        id: 'encerramento',
        label: 'Encerramento',
        icon: 'star',
        children: [
          { id: 'jurados', label: 'Jurados' },
          { id: 'avaliacoes', label: 'Avaliações' },
          { id: 'votacao-gestao', label: 'Votação' },
          { id: 'resultados', label: 'Resultados' },
        ],
      },
    ],
  },
  { group: 'Análise', items: [{ id: 'relatorios', label: 'Relatórios', icon: 'chart' }] },
  {
    group: 'Administração',
    items: [
      { id: 'usuarios', label: 'Usuários e Permissões', icon: 'users' },
      { id: 'auditoria', label: 'Auditoria', icon: 'eye' },
    ],
  },
]

// Páginas de detalhe que acendem o item de navegação correspondente.
const LEAF = {
  dashboard: ['dashboard'],
  equipes: ['equipes', 'montar'],
  empresas: ['empresas', 'empresa'],
  desafios: ['desafios', 'desafio', 'distribuicao'],
  reunioes: ['reunioes', 'reuniao'],
  resultados: ['resultados', 'painel'],
}

// Endereços antigos do protótipo continuam funcionando.
export function legacyTarget(path, params = {}) {
  if (path === 'inicio') return 'dashboard'
  if (path === 'preparacao') {
    if (params.aba === 'participantes') return 'participantes'
    if (params.aba === 'equipes') return 'equipes'
    if (params.aba === 'empresas') return params.inner === 'desafios' ? 'desafios' : 'empresas'
    return 'config'
  }
  if (path === 'gestao') {
    if (params.aba === 'pendencias') return 'pendencias'
    if (params.aba === 'reunioes' || params.aba === 'atas' || params.aba === 'decisoes') return 'reunioes'
    return 'setores'
  }
  if (path === 'encerramento') {
    if (params.aba === 'avaliacoes') return 'avaliacoes'
    if (params.aba === 'votacao') return 'votacao-gestao'
    if (params.aba === 'resultados') return 'resultados'
    return 'jurados'
  }
  if (path === 'empresas' && params.aba === 'desafios') return 'desafios'
  if (['evento', 'ingresso', 'validar'].includes(path)) return 'presenca'
  if (path === 'sala') return 'setores'
  return ''
}

function allowed(permissions, ids) {
  return ids.some((code) => permissions.includes(code))
}

export function focusFor(role) {
  return FOCUS[role] || null
}

export function canAccess(user, path) {
  const route = String(path || '').split('?')[0]
  const required = ROUTES[route]
  if (!required || !user) return false
  const focus = focusFor(user.role?.code)
  // Jurado e Votante ficam só na própria experiência focada (incluindo o resultado publicado, se permitido).
  if (focus && !focus.routes.includes(route)) return false
  return allowed(user.permissions || [], required)
}

export function navFor(user) {
  if (!user || focusFor(user.role?.code)) return []
  const sectorScoped = Boolean(user.sector)
  return NAV.map((group) => ({
    group: group.group,
    items: group.items.map((item) => {
      if (!item.children) return canAccess(user, item.id) ? item : null
      const children = item.children
        .filter((child) => canAccess(user, child.id))
        .map((child) => (child.id === 'setores' && sectorScoped ? { ...child, label: 'Meu setor' } : child))
      return children.length ? { ...item, children } : null
    }).filter(Boolean),
  })).filter((group) => group.items.length)
}

export function homeFor(user) {
  if (!user) return 'login'
  const focus = focusFor(user.role?.code)
  if (focus) return canAccess(user, focus.home) ? focus.home : ''
  if (canAccess(user, 'dashboard')) return 'dashboard'
  for (const group of navFor(user)) {
    for (const item of group.items) {
      if (item.children?.length) return item.children[0].id
      if (!item.children) return item.id
    }
  }
  return ''
}

export function navActive(id, path) {
  if (path === id) return true
  return (LEAF[id] || []).includes(path)
}

export function navState(path, user) {
  for (const group of navFor(user)) {
    for (const item of group.items) {
      const child = item.children?.find((entry) => navActive(entry.id, path))
      if (child) return { item: child.id, group: item.id }
      if (!item.children && navActive(item.id, path)) return { item: item.id, group: '' }
    }
  }
  return { item: '', group: '' }
}
