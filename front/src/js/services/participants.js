import { api, unwrap } from '@/js/api/client'

// Participantes e turmas. Não existe exclusão de participante: desistência é status WITHDRAWN.
export const listClasses = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/classes`, { query, ...options })
export const getClass = async (id) => unwrap(await api.get(`/api/v1/classes/${id}`))
export const createClass = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/classes`, body))
export const updateClass = async (id, body) => unwrap(await api.patch(`/api/v1/classes/${id}`, body))
export const changeClassStatus = async (id, active) => unwrap(await api.patch(`/api/v1/classes/${id}/status`, { active }))

export const listParticipants = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/participants`, { query, ...options })
export const getParticipant = async (id) => unwrap(await api.get(`/api/v1/participants/${id}`))
export const createParticipant = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/participants`, body))
export const updateParticipant = async (id, body) => unwrap(await api.patch(`/api/v1/participants/${id}`, body))
export const changeParticipantTeam = async (id, teamId) => unwrap(await api.patch(`/api/v1/participants/${id}/team`, { team_id: teamId }))
