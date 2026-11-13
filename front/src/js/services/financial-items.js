import { api, unwrap } from '@/js/api/client'

export const listFinancialItems = async (eventId, options) => unwrap(await api.get(`/api/v1/events/${eventId}/financial-items`, options)) || []
export const createFinancialItem = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/financial-items`, body))
export const updateFinancialItem = async (id, body) => unwrap(await api.patch(`/api/v1/financial-items/${id}`, body))
export const deleteFinancialItem = (id) => api.delete(`/api/v1/financial-items/${id}`)
