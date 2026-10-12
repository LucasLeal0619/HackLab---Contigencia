import { api } from '@/js/api/client'

// Auditoria global, somente leitura (não existe POST/PATCH/DELETE).
export const listAuditLogs = (query, options) => api.get('/api/v1/audit-logs', { query, ...options })
export const auditMeta = (options) => api.get('/api/v1/audit-logs/meta', options)
export const getAuditLog = (id, options) => api.get(`/api/v1/audit-logs/${id}`, options)
