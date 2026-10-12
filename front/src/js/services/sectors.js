import { api, unwrap } from '@/js/api/client'

export const listSectors = async (eventId, query, options) => unwrap(await api.get(`/api/v1/events/${eventId}/sectors`, { query, ...options })) || []
export const getSector = async (id) => unwrap(await api.get(`/api/v1/sectors/${id}`))
export const createSector = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/sectors`, body))
export const updateSector = async (id, body) => unwrap(await api.patch(`/api/v1/sectors/${id}`, body))
export const changeSectorStatus = async (id, active) => unwrap(await api.patch(`/api/v1/sectors/${id}/status`, { active }))
