import { api, unwrap } from '@/js/api/client'

// Jurados, atribuições explícitas jurado <-> equipe e critérios de avaliação.
export const listJurors = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/jurors`, { query, ...options })
export const getJuror = async (id) => unwrap(await api.get(`/api/v1/jurors/${id}`))
export const createJuror = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/jurors`, body))
export const updateJuror = async (id, body) => unwrap(await api.patch(`/api/v1/jurors/${id}`, body))
export const changeJurorStatus = async (id, status) => unwrap(await api.patch(`/api/v1/jurors/${id}/status`, { status }))
export const listAssignments = (id, options) => api.get(`/api/v1/jurors/${id}/assignments`, options)
// Lista final de equipes atribuídas (PUT substitui a lista).
export const syncAssignments = (id, body) => api.put(`/api/v1/jurors/${id}/assignments`, body)

// Critérios: a listagem devolve meta.locked (critérios travados depois da primeira avaliação).
export const listCriteria = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/evaluation-criteria`, { query, ...options })
export const createCriterion = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/evaluation-criteria`, body))
export const updateCriterion = async (id, body) => unwrap(await api.patch(`/api/v1/evaluation-criteria/${id}`, body))
export const changeCriterionStatus = async (id, body) => unwrap(await api.patch(`/api/v1/evaluation-criteria/${id}/status`, body))
