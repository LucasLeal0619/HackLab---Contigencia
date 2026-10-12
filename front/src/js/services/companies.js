import { api, unwrap } from '@/js/api/client'

// Empresas e representantes. Não existe exclusão de empresa (inativar = status INACTIVE).
export const listCompanies = (eventId, query, options) => api.get(`/api/v1/events/${eventId}/companies`, { query, ...options })
export const getCompany = async (id) => unwrap(await api.get(`/api/v1/companies/${id}`))
export const createCompany = async (eventId, body) => unwrap(await api.post(`/api/v1/events/${eventId}/companies`, body))
export const updateCompany = async (id, body) => unwrap(await api.patch(`/api/v1/companies/${id}`, body))
export const changeCompanyStatus = async (id, status) => unwrap(await api.patch(`/api/v1/companies/${id}/status`, { status }))

export const listRepresentatives = async (companyId, query) => unwrap(await api.get(`/api/v1/companies/${companyId}/representatives`, { query })) || []
export const createRepresentative = async (companyId, body) => unwrap(await api.post(`/api/v1/companies/${companyId}/representatives`, body))
export const updateRepresentative = async (id, body) => unwrap(await api.patch(`/api/v1/company-representatives/${id}`, body))
export const changeRepresentativeStatus = async (id, active) => unwrap(await api.patch(`/api/v1/company-representatives/${id}/status`, { active }))
