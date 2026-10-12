import { api } from '@/js/api/client'

// Ocorrências. Encaminhar/resolver/reabrir/gerar pendência são operações próprias; não existe exclusão.
export const listOccurrences = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/occurrences`, { query, ...options })
// Detalhe devolve { data, meta: { abilities } }.
export const getOccurrence = (id, options) => api.get(`/api/v1/occurrences/${id}`, options)
export const createOccurrence = (eventId, body) => api.post(`/api/v1/events/${eventId}/occurrences`, body)
export const updateOccurrence = (id, body) => api.patch(`/api/v1/occurrences/${id}`, body)
export const commentOccurrence = (id, body) => api.post(`/api/v1/occurrences/${id}/comments`, body)
export const forwardOccurrence = (id, body) => api.post(`/api/v1/occurrences/${id}/forward`, body)
export const resolveOccurrence = (id, body) => api.post(`/api/v1/occurrences/${id}/resolve`, body)
export const reopenOccurrence = (id, body) => api.post(`/api/v1/occurrences/${id}/reopen`, body)
export const generateOccurrenceTask = (id, body) => api.post(`/api/v1/occurrences/${id}/tasks`, body)
// Destinos válidos para encaminhar/criar para outro setor (setores ativos do evento). Só com occurrences.route.
export const listRoutingSectors = async (eventId, options) => (await api.get(`/api/v1/events/${eventId}/occurrences/routing-sectors`, options))?.data || []
