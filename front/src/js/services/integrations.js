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

// Centro Even3 (Complemento 11.2): resumo e reconciliação dos dados JÁ sincronizados. Nada aqui chama a Even3;
// sincronizar é sempre o syncEven3 acima (endpoint central).
const even3 = (eventId) => `/api/v1/events/${eventId}/integrations/even3`
export const even3Workbench = (eventId, options) => api.get(`${even3(eventId)}/workbench`, options)
export const listCandidates = (eventId, queue, query, options) => api.get(`${even3(eventId)}/reconciliation/${queue}`, { query, ...options })
export const confirmJurorCandidate = (eventId, personId, body) => api.post(`${even3(eventId)}/reconciliation/jurors/${personId}/confirm`, body)
export const linkCompanyGuest = (eventId, personId, body) => api.post(`${even3(eventId)}/reconciliation/company-guests/${personId}/link`, body)
export const grantOrganizationAccess = (eventId, personId, body) => api.post(`${even3(eventId)}/reconciliation/organization/${personId}/grant`, body)
export const ignoreCandidate = (eventId, queue, personId, body) => api.post(`${even3(eventId)}/reconciliation/${queue}/${personId}/ignore`, body)
export const restoreCandidate = (eventId, queue, personId) => api.post(`${even3(eventId)}/reconciliation/${queue}/${personId}/restore`)
export const revokeAccessGrant = (grantId) => api.post(`/api/v1/account-access-grants/${grantId}/revoke`)
export const eventMetadata = (eventId, options) => api.get(`${even3(eventId)}/event-metadata`, options)
// Só QUAIS campos: os valores vêm do retrato da Even3 guardado no backend.
export const applyEventMetadata = (eventId, fields) => api.post(`${even3(eventId)}/event-metadata/apply`, { fields })
