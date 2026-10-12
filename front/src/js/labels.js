// Rótulos em português dos códigos de enum do backend. Internamente a interface usa sempre o código.
// Tom (cor do Badge): ok, warn, info, danger, orange ou '' (neutro).

const E = (label, tone = '') => ({ label, tone })

export const ENUMS = {
  role: {
    ADMINISTRATOR: E('Administrador', 'info'),
    MANAGER: E('Gestor', 'info'),
    EDITOR: E('Editor', 'info'),
    CONSULTANT: E('Consultor', 'info'),
    JUROR: E('Jurado', 'orange'),
    VOTER: E('Votante', 'orange'),
  },
  activeStatus: { ACTIVE: E('Ativo', 'ok'), INACTIVE: E('Inativo', 'danger') },
  eventStatus: { PLANNED: E('Planejado', 'info'), ACTIVE: E('Em andamento', 'ok'), FINISHED: E('Encerrado'), CANCELLED: E('Cancelado', 'danger') },
  participantStatus: { AVAILABLE: E('Disponível', 'ok'), UNAVAILABLE: E('Indisponível', 'warn'), WITHDRAWN: E('Desistente', 'danger') },
  teamStatus: { ACTIVE: E('Ativa', 'ok'), INACTIVE: E('Inativa', 'danger') },
  companyStatus: { DRAFT: E('Rascunho'), CONFIRMED: E('Confirmada', 'ok'), INACTIVE: E('Inativa', 'danger') },
  companyType: { PARTICIPANT: E('Participante'), PARTNER: E('Parceira'), SPONSOR: E('Patrocinadora'), SUPPORT: E('Apoiadora'), OTHER: E('Outra') },
  challengeStatus: {
    DRAFT: E('Rascunho'),
    RECEIVED: E('Recebido', 'info'),
    UNDER_REVIEW: E('Em análise', 'warn'),
    APPROVED: E('Aprovado', 'ok'),
    DISTRIBUTED: E('Distribuído', 'info'),
    IN_DEVELOPMENT: E('Em desenvolvimento', 'warn'),
    FINISHED: E('Finalizado', 'ok'),
  },
  meetingStatus: { SCHEDULED: E('Agendada', 'info'), DONE: E('Realizada', 'ok'), CANCELLED: E('Cancelada', 'danger') },
  meetingScope: { GENERAL: E('Geral', 'info'), SECTOR: E('Setorial', 'orange') },
  priority: { LOW: E('Baixa'), MEDIUM: E('Média', 'info'), HIGH: E('Alta', 'warn'), URGENT: E('Urgente', 'danger') },
  taskStatus: { PENDING: E('Pendente', 'warn'), IN_PROGRESS: E('Em andamento', 'info'), COMPLETED: E('Concluída', 'ok') },
  occurrenceStatus: { OPEN: E('Aberta', 'danger'), IN_PROGRESS: E('Em andamento', 'warn'), RESOLVED: E('Resolvida', 'ok') },
  occurrenceCategory: {
    TECHNOLOGY: E('Tecnologia'),
    INFRASTRUCTURE: E('Infraestrutura'),
    PRODUCTION: E('Produção'),
    PARTICIPANT: E('Participante'),
    TEAM: E('Equipe'),
    COMPANY: E('Empresa'),
    ORGANIZATION: E('Organização'),
    OTHER: E('Outra'),
  },
  interaction: {
    CREATED: E('Criação'),
    UPDATED: E('Edição'),
    COMMENT: E('Comentário'),
    STATUS_CHANGED: E('Status alterado'),
    FORWARDED: E('Encaminhamento'),
    ASSIGNEE_CHANGED: E('Responsável alterado'),
    PRIORITY_CHANGED: E('Prioridade alterada'),
    DUE_CHANGED: E('Prazo alterado'),
    SECTOR_ADDED: E('Setor envolvido adicionado'),
    SECTOR_REMOVED: E('Setor envolvido removido'),
    COMPLETED: E('Conclusão'),
    RESOLVED: E('Resolução'),
    REOPENED: E('Reabertura'),
    TASK_GENERATED: E('Pendência gerada'),
  },
  jurorStatus: { ACTIVE: E('Ativo', 'ok'), INACTIVE: E('Inativo', 'danger') },
  assignmentStatus: { ACTIVE: E('Ativa', 'ok'), REVOKED: E('Revogada') },
  evaluationStatus: { NOT_STARTED: E('Não iniciada'), DRAFT: E('Rascunho', 'warn'), SUBMITTED: E('Enviada', 'ok'), REVISION_REQUESTED: E('Revisão solicitada', 'danger') },
  votingStatus: { DRAFT: E('Rascunho'), OPEN: E('Aberta', 'ok'), CLOSED: E('Encerrada', 'info'), CANCELLED: E('Cancelada', 'danger') },
  publicationStatus: { PUBLISHED: E('Publicado', 'ok'), REVOKED: E('Revogado', 'danger') },
  integrationStatus: { ACTIVE: E('Ativa', 'ok'), DISABLED: E('Desativada') },
  registrationStatus: { PENDING: E('Pendente', 'warn'), CONFIRMED: E('Confirmada', 'ok'), CANCELLED: E('Cancelada', 'danger'), UNKNOWN: E('Desconhecida') },
  ticketCategory: {
    PARTICIPANT: E('Participante', 'info'),
    ORGANIZATION: E('Organização', 'info'),
    COMPANY_GUEST: E('Empresa / Convidado', 'info'),
    JUROR: E('Jurado', 'orange'),
    PUBLIC: E('Público', 'info'),
  },
  syncScope: { EVENT: E('Evento'), TICKETS: E('Entradas'), ATTENDEES: E('Inscritos'), ALL: E('Completo') },
  syncStatus: { RUNNING: E('Em execução', 'warn'), SUCCESS: E('Concluída', 'ok'), PARTIAL: E('Parcial', 'warn'), FAILED: E('Falhou', 'danger') },
  syncTrigger: { MANUAL: E('Manual'), SCHEDULED: E('Agendada'), RECONCILIATION: E('Reconciliação') },
  attendanceKind: { EVENT: E('Evento'), ACTIVITY: E('Atividade') },
  attendanceSource: { WEBHOOK: E('Webhook'), API: E('API') },
}

export function label(group, code, fallback = '—') {
  if (code === null || code === undefined || code === '') return fallback
  return ENUMS[group]?.[code]?.label || String(code)
}

export function tone(group, code) {
  return ENUMS[group]?.[code]?.tone || ''
}

// Lista [código, rótulo] para selects e filtros, na ordem do backend.
export function options(group) {
  return Object.entries(ENUMS[group] || {}).map(([code, item]) => [code, item.label])
}
