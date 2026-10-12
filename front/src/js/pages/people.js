// Lógica da página People.vue: participantes do evento (/events/{e}/participants, /participants/{p}),
// turmas (/events/{e}/classes, /classes/{c}) e vínculo com equipe (/participants/{p}/team, /teams/{t}/members).
// Não existe exclusão de participante: desistência é o status WITHDRAWN, com confirmação.
// Mudar o status NÃO tira o participante da equipe (regra do backend); remover da equipe é ação explícita.
import { computed, onUnmounted, ref, watch } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as participantsApi from '@/js/services/participants'
import * as teamsApi from '@/js/services/teams'
import { listPeople, updatePerson } from '@/js/services/users'
import { formatDateTime } from '@/js/utils/format'

function emptyForm() {
  return { personMode: 'new', person: null, full_name: '', email: '', phone: '', document: '', class_id: '', status: 'AVAILABLE', notes: '' }
}

function noteFor(item) {
  if (item.status === 'AVAILABLE') return item.team ? 'Pode participar e já está em uma equipe.' : 'Pode participar normalmente.'
  if (item.status === 'UNAVAILABLE') return item.team ? 'Indisponível, mas ainda vinculado a uma equipe.' : 'Está cadastrado, mas não participará neste momento.'
  return item.team ? 'Desistente, mas ainda vinculado a uma equipe.' : 'Não participará mais do Hackathon.'
}

export function usePeople() {
  const canManage = computed(() => hasPermission('participants.manage'))
  const canClasses = computed(() => hasPermission('classes.view'))
  const canManageClasses = computed(() => hasPermission('classes.manage'))
  const canTeams = computed(() => hasPermission('teams.view'))
  const canManageTeams = computed(() => hasPermission('teams.manage'))
  const canPeople = computed(() => hasPermission('people.view'))
  const canEditPerson = computed(() => hasPermission('people.manage'))

  const eventId = () => eventContext.activeEventId
  const tab = ref('participantes')
  const tabs = computed(() => [
    { id: 'participantes', label: 'Participantes' },
    ...(canClasses.value ? [{ id: 'turmas', label: 'Turmas' }] : []),
  ])

  // ---------- Participantes ----------
  // O filtro "Equipe" da tela vira has_team (sem equipe / com equipe) ou team_id na query.
  const list = usePaged((query, opts) => {
    if (!eventId()) return { data: [] }
    const { team, ...rest } = query
    if (team === 'none') rest.has_team = false
    else if (team === 'any') rest.has_team = true
    else if (team) rest.team_id = team
    return participantsApi.listParticipants(eventId(), rest, opts)
  }, {
    filters: { search: '', class_id: '', status: '', team: '' },
    perPage: 25,
    watchSources: () => eventContext.activeEventId,
  })

  // Seletores pequenos (turmas e equipes do evento), carregados uma vez por evento.
  const classes = useRequest(() => (canClasses.value && eventId()
    ? fetchAll((query) => participantsApi.listClasses(eventId(), query))
    : []), { initial: [], watchSources: () => eventContext.activeEventId })
  const teams = useRequest(() => (canTeams.value && eventId()
    ? fetchAll((query) => teamsApi.listTeams(eventId(), query))
    : []), { initial: [], watchSources: () => eventContext.activeEventId })
  // Seletores que falharam (ex.: timeout com o backend ocupado) são recarregados quando a tela precisa deles.
  function ensureSelectors() {
    if (canClasses.value && !classes.loading.value && (classes.error.value || !classes.loaded.value)) classes.load()
    if (canTeams.value && !teams.loading.value && (teams.error.value || !teams.loaded.value)) teams.load()
  }
  const activeClasses = computed(() => (classes.data.value || []).filter((item) => item.active))
  const activeTeams = computed(() => (teams.data.value || []).filter((item) => item.status === 'ACTIVE'))

  // ---------- Menu de linha ----------
  const menu = ref('')
  function closeMenu() { menu.value = '' }
  watch(menu, (value) => { if (value) document.addEventListener('mousedown', closeMenu) }, { flush: 'sync' })
  onUnmounted(() => document.removeEventListener('mousedown', closeMenu))

  // ---------- Detalhe (drawer) ----------
  const viewingId = ref(null)
  const detail = useRequest(() => (viewingId.value ? participantsApi.getParticipant(viewingId.value) : null), { immediate: false })
  const history = computed(() => [...(detail.data.value?.team_history || [])].sort((a, b) => String(b.joined_at).localeCompare(String(a.joined_at))))
  function openDetail(item) {
    menu.value = ''
    viewingId.value = item.id
    detail.data.value = item
    moveTeamId.value = ''
    ensureSelectors()
    detail.load()
  }
  function closeDetail() {
    viewingId.value = null
    detail.data.value = null
  }
  async function refresh() {
    await list.load()
    if (viewingId.value) await detail.load()
  }

  // ---------- Cadastro / edição ----------
  const modal = ref(null) // create | edit | status | removeTeam
  const form = ref(emptyForm())
  const editing = ref(null)

  const personSearch = ref('')
  const people = useRequest(async () => {
    if (!personSearch.value.trim()) return []
    const payload = await listPeople({ search: personSearch.value.trim(), per_page: 10 })
    return payload?.data || []
  }, { immediate: false, initial: [] })
  let searchTimer = 0
  function searchPeople(value) {
    personSearch.value = value
    window.clearTimeout(searchTimer)
    searchTimer = window.setTimeout(() => people.load(), 300)
  }
  onUnmounted(() => window.clearTimeout(searchTimer))

  function openCreate() {
    form.value = emptyForm()
    personSearch.value = ''
    people.data.value = []
    ensureSelectors()
    save.clear()
    modal.value = 'create'
  }

  function openEdit(item) {
    menu.value = ''
    editing.value = item
    form.value = {
      ...emptyForm(),
      full_name: item.person?.full_name || '',
      email: item.person?.email || '',
      phone: item.person?.phone || '',
      class_id: item.class?.id || '',
      notes: item.notes || '',
    }
    ensureSelectors()
    update.clear()
    modal.value = 'edit'
  }

  const save = useAction(async () => {
    const body = {
      class_id: form.value.class_id ? Number(form.value.class_id) : null,
      status: form.value.status,
      notes: form.value.notes.trim() || null,
    }
    if (form.value.personMode === 'existing') body.person_id = form.value.person?.id
    else {
      body.person = {
        full_name: form.value.full_name.trim(),
        email: form.value.email.trim() || null,
        phone: form.value.phone.trim() || null,
        document: form.value.document.trim() || null,
      }
    }
    const created = await participantsApi.createParticipant(eventId(), body)
    modal.value = null
    await Promise.all([list.load(), classes.load()])
    return created
  }, { success: 'Participante cadastrado.' })

  // Turma e observações vão em PATCH /participants/{p}; dados da pessoa em PATCH /people/{id} (people.manage).
  const update = useAction(async () => {
    const item = editing.value
    const changes = []
    const body = {}
    const classId = form.value.class_id ? Number(form.value.class_id) : null
    if (classId !== (item.class?.id || null)) body.class_id = classId
    const notes = form.value.notes.trim() || null
    if (notes !== (item.notes || null)) body.notes = notes
    if (Object.keys(body).length) changes.push(() => participantsApi.updateParticipant(item.id, body))
    if (canEditPerson.value && item.person?.id) {
      const person = item.person
      const personBody = {}
      if (form.value.full_name.trim() !== (person.full_name || '')) personBody.full_name = form.value.full_name.trim()
      if (form.value.email.trim() !== (person.email || '')) personBody.email = form.value.email.trim() || null
      if (form.value.phone.trim() !== (person.phone || '')) personBody.phone = form.value.phone.trim() || null
      if (Object.keys(personBody).length) changes.push(() => updatePerson(person.id, personBody))
    }
    for (const change of changes) await change()
    modal.value = null
    if (changes.length) await Promise.all([refresh(), classes.load()])
    return changes.length
  }, { success: (count) => (count ? 'Participante atualizado.' : 'Nenhuma alteração para salvar.') })

  // ---------- Status (sempre com confirmação) ----------
  const statusTarget = ref(null)
  const statusAlsoRemove = ref(false)
  function askStatus(item, status) {
    menu.value = ''
    statusTarget.value = { item, status }
    statusAlsoRemove.value = false
    modal.value = 'status'
  }
  const changeStatus = useAction(async () => {
    const { item, status } = statusTarget.value
    const updated = await participantsApi.updateParticipant(item.id, { status })
    let removed = false
    if (statusAlsoRemove.value && item.team?.id && canManageTeams.value) {
      await teamsApi.removeMember(item.team.id, item.id)
      removed = true
    }
    modal.value = null
    statusTarget.value = null
    await Promise.all([refresh(), removed ? teams.load() : null])
    return { updated, removed }
  }, { success: ({ removed }) => (removed ? 'Status atualizado e participante removido da equipe.' : 'Status do participante atualizado.') })

  // ---------- Equipe do participante ----------
  const moveTeamId = ref('')
  const assignTeam = useAction(async () => {
    const item = detail.data.value
    const updated = await participantsApi.changeParticipantTeam(item.id, Number(moveTeamId.value))
    moveTeamId.value = ''
    await Promise.all([refresh(), teams.load()])
    return updated
  }, { success: (updated) => `${updated?.person?.full_name || 'Participante'} agora está na equipe ${updated?.team?.name || ''}.` })

  function askRemoveTeam() {
    modal.value = 'removeTeam'
  }
  const removeFromTeam = useAction(async () => {
    const item = detail.data.value
    await teamsApi.removeMember(item.team.id, item.id)
    modal.value = null
    await Promise.all([refresh(), teams.load()])
  }, { success: 'Participante removido da equipe. O histórico foi preservado.' })

  function openTeam(item) {
    menu.value = ''
    if (item.team && canManageTeams.value) go(`montar?id=${item.team.id}`)
    else go('equipes')
  }

  // ---------- Turmas ----------
  const classList = usePaged((query, opts) => (eventId() ? participantsApi.listClasses(eventId(), query, opts) : { data: [] }), {
    filters: { active: '' },
    perPage: 25,
    immediate: false,
  })
  let classesLoadedFor = null
  watch([tab, () => eventContext.activeEventId], ([current, event]) => {
    if (current === 'turmas' && classesLoadedFor !== event) {
      classesLoadedFor = event
      classList.page.value = 1
      classList.load()
    }
  })

  const classForm = ref({ name: '' })
  const classEditing = ref(null)
  const classTarget = ref(null)
  function openClassCreate() {
    classEditing.value = null
    classForm.value = { name: '' }
    saveClass.clear()
    modal.value = 'class'
  }
  function openClassEdit(item) {
    classEditing.value = item
    classForm.value = { name: item.name }
    saveClass.clear()
    modal.value = 'class'
  }
  const saveClass = useAction(async () => {
    const name = classForm.value.name.trim()
    const result = classEditing.value
      ? await participantsApi.updateClass(classEditing.value.id, { name })
      : await participantsApi.createClass(eventId(), { name })
    const created = !classEditing.value
    modal.value = null
    await Promise.all([classList.load(), classes.load()])
    return { result, created }
  }, { success: ({ created }) => (created ? 'Turma cadastrada.' : 'Turma renomeada.') })

  function askClassStatus(item) {
    classTarget.value = item
    modal.value = 'classStatus'
  }
  const toggleClass = useAction(async () => {
    const item = classTarget.value
    const updated = await participantsApi.changeClassStatus(item.id, !item.active)
    modal.value = null
    classTarget.value = null
    await Promise.all([classList.load(), classes.load()])
    return updated
  }, { success: (updated) => (updated?.active ? 'Turma ativada.' : 'Turma inativada.') })

  function closeModal() {
    modal.value = null
  }

  return {
    tab,
    tabs,
    list,
    classes,
    teams,
    activeClasses,
    activeTeams,
    ensureSelectors,
    menu,
    viewingId,
    detail,
    history,
    openDetail,
    closeDetail,
    modal,
    form,
    editing,
    personSearch,
    people,
    searchPeople,
    openCreate,
    openEdit,
    save,
    update,
    statusTarget,
    statusAlsoRemove,
    askStatus,
    changeStatus,
    moveTeamId,
    assignTeam,
    askRemoveTeam,
    removeFromTeam,
    openTeam,
    classList,
    classForm,
    classEditing,
    classTarget,
    openClassCreate,
    openClassEdit,
    saveClass,
    askClassStatus,
    toggleClass,
    closeModal,
    canManage,
    canClasses,
    canManageClasses,
    canTeams,
    canManageTeams,
    canPeople,
    canEditPerson,
    noteFor,
    label,
    tone,
    options,
    formatDateTime,
  }
}
