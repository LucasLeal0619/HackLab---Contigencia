import { api, unwrap } from '@/js/api/client'

// Resultado oficial: prévia/publicação/revogação (gestão) e publicado (todos com results.view_published).
// O front não calcula nada: mostra o que o backend devolve (técnico e popular separados, sem nota combinada).
export const previewResults = async (eventId, votingSessionId, options) => unwrap(await api.get(`/api/v1/events/${eventId}/results/preview`, {
  query: votingSessionId ? { voting_session_id: votingSessionId } : undefined,
  ...options,
}))
export const listPublications = async (eventId, options) => unwrap(await api.get(`/api/v1/events/${eventId}/results/publications`, options)) || []
export const publishResults = async (eventId, votingSessionId) => unwrap(await api.post(`/api/v1/events/${eventId}/results/publish`, { voting_session_id: votingSessionId }))
export const revokeResults = async (eventId, reason) => unwrap(await api.post(`/api/v1/events/${eventId}/results/revoke`, { reason }))
// { data: publicação | null, meta: { official } } -> publicação ou null.
export const publishedResults = async (eventId, options) => unwrap(await api.get(`/api/v1/events/${eventId}/results/published`, options))
