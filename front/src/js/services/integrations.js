import { api } from '@/js/api/client'

// Integração Even3 pelo backend. O front nunca chama a Even3 nem vê token/segredo.
export const even3Status = (eventId, options) => api.get(`/api/v1/events/${eventId}/integrations/even3/status`, options)
export const configureEven3 = (eventId, body) => api.put(`/api/v1/events/${eventId}/integrations/even3`, body)
export const listTickets = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/integrations/even3/tickets`, { query, ...options })
export const mapTicket = (ticketId, body) => api.patch(`/api/v1/external-ticket-types/${ticketId}/mapping`, body)
// A sincronização chama a Even3 no servidor: pode demorar.
export const syncEven3 = (eventId, body) => api.post(`/api/v1/events/${eventId}/integrations/even3/sync`, body, { timeout: 180000 })
export const listSyncRuns = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/integrations/even3/sync-runs`, { query, ...options })
export const getSyncRun = (id, options) => api.get(`/api/v1/integration-sync-runs/${id}`, options)

export const listRegistrations = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/event-registrations`, { query, ...options })
export const getRegistration = (id, options) => api.get(`/api/v1/event-registrations/${id}`, options)
export const materializeParticipants = (eventId) => api.post(`/api/v1/events/${eventId}/event-registrations/materialize-participants`)
export const listAttendance = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/attendance`, { query, ...options })
