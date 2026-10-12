import { api, unwrap } from '@/js/api/client'

export const listEvents = (query) => api.get('/api/v1/events', { query })
export const getEvent = async (id) => unwrap(await api.get(`/api/v1/events/${id}`))
export const createEvent = async (body) => unwrap(await api.post('/api/v1/events', body))
export const updateEvent = async (id, body) => unwrap(await api.patch(`/api/v1/events/${id}`, body))

export const listDays = async (eventId, options) => unwrap(await api.get(`/api/v1/events/${eventId}/days`, options)) || []
export const createDay = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/days`, body))
export const updateDay = async (eventId, dayId, body) => unwrap(await api.patch(`/api/v1/events/${eventId}/days/${dayId}`, body))
