import { vi } from 'vitest'

// Resposta HTTP mínima para o client (fetch).
export function response(status, body, headers = {}) {
  const text = body === undefined || body === null ? '' : JSON.stringify(body)
  return {
    status,
    ok: status >= 200 && status < 300,
    headers,
    text: async () => text,
  }
}

// fetch falso: cada chamada consome a próxima resposta da fila (ou chama a função).
export function fakeFetch(...queue) {
  const fn = vi.fn(async (url, init) => {
    const next = queue.length > 1 ? queue.shift() : queue[0]
    if (typeof next === 'function') return next(url, init)
    if (next instanceof Error) throw next
    return next
  })
  return fn
}

export function clearCookies() {
  document.cookie.split(';').forEach((item) => {
    const name = item.split('=')[0].trim()
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  })
}

export const ADMIN = {
  id: 1,
  email: 'admin@hacklab.local',
  status: 'ACTIVE',
  role: { code: 'ADMINISTRATOR', name: 'Administrador' },
  sector: null,
  person: { id: 1, full_name: 'Administrador HackLab' },
  permissions: ['users.view', 'users.manage', 'audit_logs.view', 'events.view', 'events.manage', 'participants.view', 'teams.view', 'companies.view', 'challenges.view', 'tasks.view', 'occurrences.view', 'sectors.view', 'meetings.view', 'voting_sessions.view', 'results.preview', 'jurors.view', 'evaluations.all.view', 'integrations.view', 'evaluations.own'],
}

export const MANAGER = {
  id: 2,
  email: 'gestor@hacklab.local',
  status: 'ACTIVE',
  role: { code: 'MANAGER', name: 'Gestor' },
  sector: { id: 3, event_id: 1, name: 'Tecnologia' },
  person: { id: 2, full_name: 'Gestor' },
  permissions: ['people.view', 'events.view', 'sectors.view', 'sectors.manage', 'meetings.view', 'tasks.view', 'tasks.route', 'occurrences.view', 'participants.view', 'teams.view', 'evaluations.progress.view', 'results.view_published'],
}

export const JUROR = {
  id: 3,
  email: 'jurado@hacklab.local',
  status: 'ACTIVE',
  role: { code: 'JUROR', name: 'Jurado' },
  sector: null,
  person: { id: 3, full_name: 'Jurado' },
  permissions: ['events.view', 'evaluation_criteria.view', 'evaluations.own', 'results.view_published'],
}

export const VOTER = {
  id: 4,
  email: 'votante01@hacklab.local',
  status: 'ACTIVE',
  role: { code: 'VOTER', name: 'Votante' },
  sector: null,
  person: { id: 4, full_name: 'Votante' },
  permissions: ['events.view', 'votes.cast', 'results.view_published'],
}
