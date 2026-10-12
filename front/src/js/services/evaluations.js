import { api } from '@/js/api/client'

// Área do jurado (Person autenticada) e visão administrativa/agregada das avaliações.
export const myEvaluations = (eventId, options) => api.get(`/api/v1/events/${eventId}/my-evaluations`, options)
export const saveEvaluation = (assignmentId, body) => api.put(`/api/v1/juror-assignments/${assignmentId}/evaluation`, body)
export const submitEvaluation = (assignmentId, body) => api.post(`/api/v1/juror-assignments/${assignmentId}/evaluation/submit`, body)

export const listEvaluations = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/evaluations`, { query, ...options })
export const getEvaluation = (id, options) => api.get(`/api/v1/evaluations/${id}`, options)
export const requestRevision = (id, body) => api.post(`/api/v1/evaluations/${id}/request-revision`, body)
export const evaluationProgress = (eventId, options) => api.get(`/api/v1/events/${eventId}/evaluation-progress`, options)
export const technicalResults = (eventId, options) => api.get(`/api/v1/events/${eventId}/technical-results`, options)
