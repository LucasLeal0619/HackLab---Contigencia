import { api, unwrap } from '@/js/api/client'

// Equipes e membros. Não existe exclusão de equipe (inativar = status INACTIVE).
export const listTeams = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/teams`, { query, ...options })
export const getTeam = async (id) => unwrap(await api.get(`/api/v1/teams/${id}`))
export const createTeam = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/teams`, body))
export const updateTeam = async (id, body) => unwrap(await api.patch(`/api/v1/teams/${id}`, body))
export const listMembers = async (id, query) => unwrap(await api.get(`/api/v1/teams/${id}/members`, { query })) || []
export const addMember = async (id, participantId) => unwrap(await api.post(`/api/v1/teams/${id}/members`, { participant_id: participantId }))
export const removeMember = (id, participantId) => api.delete(`/api/v1/teams/${id}/members/${participantId}`)
