import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { MANAGER, clearCookies, response } from './helpers'

const EDITOR = { ...MANAGER, id: 9, role: { code: 'EDITOR', name: 'Editor' }, permissions: ['tasks.view', 'tasks.comment', 'tasks.create', 'tasks.operate', 'sectors.view'] }
const MANAGER_ROUTE = { ...MANAGER, permissions: [...MANAGER.permissions, 'tasks.create', 'tasks.operate', 'tasks.route'] }

async function support(user) {
  vi.resetModules()
  clearCookies()
  const calls = []
  const fetch = vi.fn(async (url) => {
    const path = new URL(url).pathname
    calls.push(path)
    if (path === '/api/v1/events/1/sectors') return response(200, { data: [{ id: 3, name: 'Tecnologia', active: true }] })
    if (path === '/api/v1/events/1/tasks/routing-sectors') return response(200, { data: [{ id: 2, name: 'Produção' }, { id: 3, name: 'Tecnologia' }, { id: 4, name: 'Logística' }] })
    return response(404, {})
  })
  const client = await import('@/js/api/client')
  client.configureClient({ baseUrl: 'http://api.test', fetch })
  const { auth, AUTH } = await import('@/js/stores/auth')
  Object.assign(auth, { status: AUTH.AUTHENTICATED, user, permissions: user.permissions })
  const { eventContext } = await import('@/js/stores/event-context')
  Object.assign(eventContext, { status: 'ready', events: [{ id: 1, name: 'Evento', days: [] }], activeEventId: 1 })
  const { useDemandSupport } = await import('@/js/components/demand-activity')
  let result
  mount(defineComponent({ setup() { result = useDemandSupport('tasks'); return () => h('div') } }))
  await flushPromises()
  return { result, calls }
}

describe('destinos de encaminhamento', () => {
  it('Gestor (tasks.route) recebe os setores ativos do evento pelo contrato próprio', async () => {
    const { result, calls } = await support(MANAGER_ROUTE)
    expect(calls).toContain('/api/v1/events/1/tasks/routing-sectors')
    expect(result.activeSectors.value.map((item) => item.name)).toEqual(['Produção', 'Tecnologia', 'Logística'])
  })

  it('Editor (sem tasks.route) não chama o endpoint e só vê o próprio setor', async () => {
    const { result, calls } = await support(EDITOR)
    expect(calls).not.toContain('/api/v1/events/1/tasks/routing-sectors')
    expect(result.activeSectors.value.map((item) => item.name)).toEqual(['Tecnologia'])
  })
})
