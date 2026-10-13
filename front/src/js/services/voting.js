import { api, unwrap } from '@/js/api/client'

// Votação pública. Nenhuma rota devolve eleitor -> equipe; o voto é definitivo (sem PATCH/DELETE).
// Listagens paginadas devolvem o payload bruto ({ data, meta }); o resto já vem sem o envelope `data`.
export const listSessions = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/voting-sessions`, { query, ...options })
export const getSession = async (id, options) => unwrap(await api.get(`/api/v1/voting-sessions/${id}`, options))
export const createSession = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/voting-sessions`, body))
export const updateSession = async (id, body) => unwrap(await api.patch(`/api/v1/voting-sessions/${id}`, body))
// Lista final de equipes candidatas (team_ids) e de eleitores elegíveis (user_ids). Só em DRAFT.
export const syncCandidates = async (id, teamIds) => unwrap(await api.put(`/api/v1/voting-sessions/${id}/teams`, { team_ids: teamIds }))
export const listEligibilities = (id, query, options) => api.get(`/api/v1/voting-sessions/${id}/eligibilities`, { query, ...options })
export const syncEligibilities = async (id, userIds) => unwrap(await api.put(`/api/v1/voting-sessions/${id}/eligibilities`, { user_ids: userIds }))
// Preparação dos Votantes do público (contas já ativadas pelo primeiro acesso). Usa o banco local, não a Even3.
export const publicVoterReadiness = async (id, options) => unwrap(await api.get(`/api/v1/voting-sessions/${id}/public-voters/readiness`, options))
export const addPublicVoters = async (id) => unwrap(await api.post(`/api/v1/voting-sessions/${id}/eligibilities/public-voters`))
export const openSession = async (id) => unwrap(await api.post(`/api/v1/voting-sessions/${id}/open`))
export const closeSession = async (id) => unwrap(await api.post(`/api/v1/voting-sessions/${id}/close`))
export const cancelSession = async (id, reason) => unwrap(await api.post(`/api/v1/voting-sessions/${id}/cancel`, { reason: reason || null }))
// Participação: só totais (qualquer status). Contagem por equipe: só com a sessão encerrada.
export const sessionTurnout = async (id, options) => unwrap(await api.get(`/api/v1/voting-sessions/${id}/turnout`, options))
export const sessionResults = (id, options) => api.get(`/api/v1/voting-sessions/${id}/results`, options)

// Área do eleitor.
export const mySessions = async (eventId, options) => unwrap(await api.get(`/api/v1/events/${eventId}/my-voting-sessions`, options)) || []
export const ballot = async (id, options) => unwrap(await api.get(`/api/v1/voting-sessions/${id}/ballot`, options))
export const castVote = async (id, teamId) => unwrap(await api.post(`/api/v1/voting-sessions/${id}/vote`, { team_id: teamId }))
export const myVote = async (id, options) => unwrap(await api.get(`/api/v1/voting-sessions/${id}/my-vote`, options))
