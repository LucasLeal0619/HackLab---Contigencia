import { api } from '@/js/api/client'

// Pendências. Encaminhar/concluir/reabrir são operações próprias; não existe exclusão.
export const listTasks = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/tasks`, { query, ...options })
// Detalhe devolve { data, meta: { abilities } }.
export const getTask = (id, options) => api.get(`/api/v1/tasks/${id}`, options)
export const createTask = (eventId, body) => api.post(`/api/v1/events/${eventId}/tasks`, body)
export const updateTask = (id, body) => api.patch(`/api/v1/tasks/${id}`, body)
export const commentTask = (id, body) => api.post(`/api/v1/tasks/${id}/comments`, body)
export const forwardTask = (id, body) => api.post(`/api/v1/tasks/${id}/forward`, body)
export const completeTask = (id, body) => api.post(`/api/v1/tasks/${id}/complete`, body)
export const reopenTask = (id, body) => api.post(`/api/v1/tasks/${id}/reopen`, body)
// Destinos válidos para encaminhar/criar para outro setor (setores ativos do evento). Só com tasks.route.
export const listRoutingSectors = async (eventId, options) => (await api.get(`/api/v1/events/${eventId}/tasks/routing-sectors`, options))?.data || []
