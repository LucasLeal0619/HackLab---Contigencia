import { api, unwrap } from '@/js/api/client'

// Relatórios consolidados (reports.view): 1 relatório = 1 endpoint agregado do backend.
// O PDF é montado só a partir deste payload (ver services/reports/).
const get = async (eventId, type, options) => unwrap(await api.get(`/api/v1/events/${eventId}/reports/${type}`, { timeout: 60000, ...options }))

export const general = (eventId, options) => get(eventId, 'general', options)
export const participantsPresence = (eventId, options) => get(eventId, 'participants-presence', options)
export const operations = (eventId, options) => get(eventId, 'operations', options)
export const closingResults = (eventId, options) => get(eventId, 'closing-results', options)
