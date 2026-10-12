import { api, unwrap } from '@/js/api/client'

export const listMeetings = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/meetings`, { query, ...options })
export const getMeeting = async (id) => unwrap(await api.get(`/api/v1/meetings/${id}`))
export const createMeeting = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/meetings`, body))
export const updateMeeting = async (id, body) => unwrap(await api.patch(`/api/v1/meetings/${id}`, body))
