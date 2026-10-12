import { api, unwrap } from '@/js/api/client'

export const listChallenges = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/challenges`, { query, ...options })
export const getChallenge = async (id) => unwrap(await api.get(`/api/v1/challenges/${id}`))
export const createChallenge = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/challenges`, body))
export const updateChallenge = async (id, body) => unwrap(await api.patch(`/api/v1/challenges/${id}`, body))
export const changeChallengeStatus = async (id, status) => unwrap(await api.patch(`/api/v1/challenges/${id}/status`, { status }))
// Distribuição: equipe do desafio (null remove).
export const assignChallengeTeam = async (id, teamId) => unwrap(await api.patch(`/api/v1/challenges/${id}/team`, { team_id: teamId }))
