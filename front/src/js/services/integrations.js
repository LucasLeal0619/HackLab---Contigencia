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
export const listAttendance = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/attendance`, { query, ...options })

// Presença / cabeçalho: resumo e "Precisa de atenção" dos dados JÁ sincronizados. Nada aqui chama a Even3;
// sincronizar é sempre o syncEven3 acima (endpoint central).
const even3 = (eventId) => `/api/v1/events/${eventId}/integrations/even3`
export const even3Workbench = (eventId, options) => api.get(`${even3(eventId)}/workbench`, options)
export const listAttention = (eventId, options) => api.get(`${even3(eventId)}/attention`, options)
// Única pendência com ação: representante de empresa sem empresa identificada.
export const linkCompanyGuest = (eventId, personId, body) => api.post(`${even3(eventId)}/company-guests/${personId}/link`, body)
// Só aviso: nome/datas do evento são do HackLab.
export const eventMetadata = (eventId, options) => api.get(`${even3(eventId)}/event-metadata`, options)
