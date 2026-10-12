import { api, unwrap } from '@/js/api/client'

// Usuários, pessoas e perfis. Person != User: a conta é sempre ligada a uma Person.
export const listRoles = async () => unwrap(await api.get('/api/v1/roles')) || []

export const listPeople = (query, options) => api.get('/api/v1/people', { query, ...options })
export const getPerson = async (id) => unwrap(await api.get(`/api/v1/people/${id}`))
export const createPerson = async (body) => unwrap(await api.post('/api/v1/people', body))
export const updatePerson = async (id, body) => unwrap(await api.patch(`/api/v1/people/${id}`, body))

export const listUsers = (query, options) => api.get('/api/v1/users', { query, ...options })
export const getUser = async (id) => unwrap(await api.get(`/api/v1/users/${id}`))
export const createUser = async (body) => unwrap(await api.post('/api/v1/users', body))
export const updateUser = async (id, body) => unwrap(await api.patch(`/api/v1/users/${id}`, body))
export const changeUserRole = async (id, body) => unwrap(await api.patch(`/api/v1/users/${id}/role`, body))
export const changeUserStatus = async (id, status) => unwrap(await api.patch(`/api/v1/users/${id}/status`, { status }))
export const changeUserSector = async (id, sectorId) => unwrap(await api.patch(`/api/v1/users/${id}/sector`, { sector_id: sectorId }))
