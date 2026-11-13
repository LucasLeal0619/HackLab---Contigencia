import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/js/services/sectors', () => ({ listSectors: vi.fn() }))
vi.mock('@/js/services/users', () => ({
  listUsers: vi.fn(),
  listRoles: vi.fn(),
  listPeople: vi.fn(),
  createUser: vi.fn(),
  updateUser: vi.fn(),
  updatePerson: vi.fn(),
  changeUserRole: vi.fn(),
  changeUserSector: vi.fn(),
  changeUserStatus: vi.fn(),
}))

import { listSectors } from '@/js/services/sectors'
import { listUsers } from '@/js/services/users'
import { auth } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import Users from '@/pages/Users.vue'

describe('setores disponíveis no cadastro de usuários', () => {
  let wrapper

  beforeEach(() => {
    auth.permissions = ['users.manage', 'roles.view']
    eventContext.activeEventId = 1
    listUsers.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    listSectors
      .mockResolvedValueOnce([{ id: 1, name: 'Produção', active: true }])
      .mockResolvedValueOnce([
        { id: 1, name: 'Produção', active: true },
        { id: 2, name: 'Setor Financeiro', active: true },
      ])

    wrapper = mount(Users)
  })

  afterEach(() => {
    wrapper.unmount()
    auth.permissions = []
    eventContext.activeEventId = null
    vi.clearAllMocks()
  })

  it('abre o formulário ao clicar e atualiza os setores ativos', async () => {
    await flushPromises()

    const newUserButton = wrapper.findAll('button').find((button) => button.text() === 'Novo usuário')
    expect(newUserButton).toBeDefined()
    await newUserButton.trigger('click')
    await flushPromises()

    expect(listSectors).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[role="dialog"]').attributes('aria-label')).toBe('Novo usuário')
    expect(wrapper.get('[role="dialog"]').text()).toContain('Setor Financeiro')
  })
})
