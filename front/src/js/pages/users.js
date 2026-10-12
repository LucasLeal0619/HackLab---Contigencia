// Lógica da página Users.vue: usuários (/users), pessoas (/people) e perfis (/roles, com permissões reais).
// Person != User: a conta sempre aponta para uma Person (nova ou já cadastrada).
import { computed, ref } from 'vue'
import { usePaged, useAction, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { listSectors } from '@/js/services/sectors'
import * as usersApi from '@/js/services/users'
import { formatDateTime } from '@/js/utils/format'

const SECTOR_ROLES = ['MANAGER', 'EDITOR']

// Grupos da matriz de permissões (prefixo do código → módulo).
const MODULES = {
  people: 'Pessoas', users: 'Usuários', roles: 'Perfis', audit_logs: 'Auditoria', events: 'Evento', sectors: 'Setores',
  meetings: 'Reuniões', classes: 'Turmas', participants: 'Participantes', teams: 'Equipes', companies: 'Empresas',
  challenges: 'Desafios', tasks: 'Pendências', occurrences: 'Ocorrências', jurors: 'Jurados', evaluation_criteria: 'Critérios',
  evaluations: 'Avaliações', voting_sessions: 'Votação', voting_eligibility: 'Votação', votes: 'Votação', voting_aggregates: 'Votação',
  results: 'Resultados', integrations: 'Integração Even3', event_registrations: 'Inscrições', attendance: 'Presença',
}

function emptyForm() {
  return { personMode: 'new', person: null, full_name: '', phone: '', document: '', email: '', password: '', role: 'EDITOR', sector_id: '', status: 'ACTIVE' }
}

export function useUsers() {
  const canManage = computed(() => hasPermission('users.manage'))
  const canRoles = computed(() => hasPermission('roles.view'))
  const canPeople = computed(() => hasPermission('people.view'))

  const list = usePaged((query, options_) => usersApi.listUsers(query, options_), {
    filters: { search: '', role: '', status: '', sector_id: '' },
    perPage: 20,
  })

  const roles = useRequest(() => (canRoles.value ? usersApi.listRoles() : []), { initial: [] })
  const sectors = useRequest(() => (eventContext.activeEventId ? listSectors(eventContext.activeEventId) : []), {
    initial: [],
    watchSources: () => eventContext.activeEventId,
  })
  const activeSectors = computed(() => (sectors.data.value || []).filter((item) => item.active))

  const roleOptions = computed(() => (roles.data.value?.length ? roles.data.value.map((item) => [item.code, item.name]) : options('role')))

  // Matriz real: permissões de cada perfil (GET /roles).
  const matrix = computed(() => {
    const list_ = roles.data.value || []
    const codes = [...new Set(list_.flatMap((role) => role.permissions || []))].sort()
    return codes.map((code) => ({
      code,
      module: MODULES[code.split('.')[0]] || code.split('.')[0],
      cells: list_.map((role) => (role.permissions || []).includes(code)),
    }))
  })

  const modal = ref(null)
  const selected = ref(null)
  const form = ref(emptyForm())
  const showPassword = ref(false)

  // Busca de Person existente (sem conta) para vincular a um novo usuário.
  const personSearch = ref('')
  const people = useRequest(async () => {
    if (!personSearch.value.trim()) return []
    const payload = await usersApi.listPeople({ search: personSearch.value.trim(), per_page: 10 })
    return (payload?.data || []).filter((item) => item.has_account === false)
  }, { immediate: false, initial: [] })
  let searchTimer = 0
  function searchPeople(value) {
    personSearch.value = value
    window.clearTimeout(searchTimer)
    searchTimer = window.setTimeout(() => people.load(), 300)
  }

  const needsSector = computed(() => SECTOR_ROLES.includes(form.value.role))

  function openCreate() {
    form.value = emptyForm()
    personSearch.value = ''
    people.data.value = []
    showPassword.value = false
    save.clear()
    modal.value = 'create'
  }

  function openDetail(item) {
    selected.value = item
    modal.value = 'detail'
  }

  function openEdit(item) {
    selected.value = item
    form.value = {
      ...emptyForm(),
      full_name: item.person?.full_name || '',
      phone: item.person?.phone || '',
      document: item.person?.document || '',
      email: item.email,
      role: item.role?.code || '',
      sector_id: item.sector?.id || '',
      status: item.status,
    }
    showPassword.value = false
    update.clear()
    modal.value = 'edit'
  }

  const save = useAction(async () => {
    const body = {
      email: form.value.email.trim(),
      password: form.value.password,
      role: form.value.role,
      status: form.value.status,
    }
    if (needsSector.value) body.sector_id = form.value.sector_id ? Number(form.value.sector_id) : null
    if (form.value.personMode === 'existing') body.person_id = form.value.person?.id
    else body.person = { full_name: form.value.full_name.trim(), phone: form.value.phone.trim() || null, document: form.value.document.trim() || null }
    const created = await usersApi.createUser(body)
    modal.value = null
    await list.load()
    return created
  }, { success: 'Usuário cadastrado.' })

  // Edição: cada aspecto tem endpoint próprio (e auditoria própria no backend). Só chama o que mudou.
  const update = useAction(async () => {
    const item = selected.value
    const changes = []
    const email = form.value.email.trim()
    if (email !== item.email || form.value.password) {
      changes.push(() => usersApi.updateUser(item.id, { ...(email !== item.email ? { email } : {}), ...(form.value.password ? { password: form.value.password } : {}) }))
    }
    const person = item.person || {}
    const personBody = {}
    if (form.value.full_name.trim() !== (person.full_name || '')) personBody.full_name = form.value.full_name.trim()
    if (form.value.phone.trim() !== (person.phone || '')) personBody.phone = form.value.phone.trim() || null
    if (form.value.document.trim() !== (person.document || '')) personBody.document = form.value.document.trim() || null
    if (Object.keys(personBody).length && person.id) changes.push(() => usersApi.updatePerson(person.id, personBody))
    const sectorId = form.value.sector_id ? Number(form.value.sector_id) : null
    if (form.value.role !== item.role?.code) {
      changes.push(() => usersApi.changeUserRole(item.id, { role: form.value.role, ...(needsSector.value ? { sector_id: sectorId } : {}) }))
    } else if (needsSector.value && sectorId && sectorId !== item.sector?.id) {
      changes.push(() => usersApi.changeUserSector(item.id, sectorId))
    }
    if (form.value.status !== item.status) changes.push(() => usersApi.changeUserStatus(item.id, form.value.status))
    for (const change of changes) await change()
    modal.value = null
    await list.load()
    return changes.length
  }, { success: (count) => (count ? 'Alterações do usuário salvas.' : 'Nenhuma alteração para salvar.') })

  const toggleStatus = useAction(async (item, status) => {
    const updated = await usersApi.changeUserStatus(item.id, status)
    selected.value = { ...item, ...updated }
    modal.value = 'detail'
    await list.load()
    return updated
  }, { success: (_, __, status) => (status === 'ACTIVE' ? 'Usuário ativado.' : 'Usuário inativado.') })

  return {
    list,
    roles,
    sectors,
    activeSectors,
    roleOptions,
    matrix,
    modal,
    selected,
    form,
    showPassword,
    needsSector,
    personSearch,
    people,
    searchPeople,
    canManage,
    canRoles,
    canPeople,
    openCreate,
    openDetail,
    openEdit,
    save,
    update,
    toggleStatus,
    label,
    tone,
    options,
    formatDateTime,
  }
}
