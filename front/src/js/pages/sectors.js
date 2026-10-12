// Lógica da página Sectors.vue: setores do evento (GET /events/{e}/sectors, já no escopo do usuário)
// e a área de trabalho do setor — resumo real, somente leitura, de pendências, ocorrências e reuniões.
// Gestor/Editor só alcançam o próprio setor; criar e ativar/inativar setores é exclusivo de quem tem escopo global.
import { computed, ref, watch } from 'vue'
import { toPage } from '@/js/api/pagination'
import { useAction, useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { go } from '@/js/router'
import { auth, hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { listMeetings } from '@/js/services/meetings'
import { listOccurrences } from '@/js/services/occurrences'
import { changeSectorStatus, createSector, listSectors, updateSector } from '@/js/services/sectors'
import { listTasks } from '@/js/services/tasks'
import { listUsers } from '@/js/services/users'
import { formatDate, formatDateTime } from '@/js/utils/format'

// Quantos itens a área de trabalho busca por bloco (máximo do backend). Os contadores dizem quando há mais.
const SUMMARY_SIZE = 100
const PREVIEW = 6

function emptyForm() {
  return { name: '', description: '' }
}

export function useSectors(props) {
  const ownSectorId = computed(() => auth.user?.sector?.id || null)
  const globalScope = computed(() => !ownSectorId.value)
  const canManage = computed(() => hasPermission('sectors.manage'))
  // SectorPolicy: criar e ativar/inativar exigem escopo global; editar, alcançar o setor.
  const canCreate = computed(() => canManage.value && globalScope.value)
  const canChangeStatus = canCreate
  const canEdit = (sector) => canManage.value && (globalScope.value || sector?.id === ownSectorId.value)
  const canTasks = computed(() => hasPermission('tasks.view'))
  const canOccurrences = computed(() => hasPermission('occurrences.view'))
  const canMeetings = computed(() => hasPermission('meetings.view'))
  const canUsers = computed(() => hasPermission('users.view'))

  const statusFilter = ref('')
  const sectors = useRequest(() => (eventContext.activeEventId ? listSectors(eventContext.activeEventId) : []), {
    initial: [],
    watchSources: () => eventContext.activeEventId,
  })
  const visibleSectors = computed(() => {
    const list = sectors.data.value || []
    if (statusFilter.value === 'ACTIVE') return list.filter((item) => item.active)
    if (statusFilter.value === 'INACTIVE') return list.filter((item) => !item.active)
    return list
  })
  const mySectors = computed(() => visibleSectors.value.filter((item) => item.id === ownSectorId.value))
  const otherSectors = computed(() => visibleSectors.value.filter((item) => item.id !== ownSectorId.value))

  // Setor aberto: ?setor=<id>. Quem só alcança um setor cai direto nele.
  const openedId = computed(() => {
    const fromRoute = Number(props.params?.setor) || null
    if (fromRoute) return fromRoute
    const list = sectors.data.value || []
    return ownSectorId.value && list.length === 1 ? list[0].id : null
  })
  const opened = computed(() => (openedId.value ? (sectors.data.value || []).find((item) => item.id === openedId.value) || null : null))
  const showBack = computed(() => globalScope.value || (sectors.data.value || []).length > 1)

  function selectSector(item) {
    go(`setores?setor=${item.id}`)
  }

  // ---- Área de trabalho do setor -------------------------------------------------------------
  // Pendências/ocorrências: setor responsável ou envolvido (filtros do backend).
  const relation = ref('responsible')
  const sectorQuery = (id) => (relation.value === 'responsible' ? { responsible_sector_id: id } : { involved_sector_id: id })
  const eventId = () => eventContext.activeEventId

  const tasks = useRequest(async ({ signal }) => {
    if (!opened.value || !canTasks.value) return null
    return toPage(await listTasks(eventId(), { ...sectorQuery(opened.value.id), per_page: SUMMARY_SIZE }, { signal }))
  }, { immediate: false })
  const occurrences = useRequest(async ({ signal }) => {
    if (!opened.value || !canOccurrences.value) return null
    return toPage(await listOccurrences(eventId(), { ...sectorQuery(opened.value.id), per_page: SUMMARY_SIZE }, { signal }))
  }, { immediate: false })
  const meetings = useRequest(async ({ signal }) => {
    if (!opened.value || !canMeetings.value) return null
    return toPage(await listMeetings(eventId(), { sector_id: opened.value.id, per_page: SUMMARY_SIZE }, { signal }))
  }, { immediate: false })
  // Usuários do setor: só sob demanda e só para quem tem users.view.
  const usersOpen = ref(false)
  const users = useRequest(async ({ signal }) => {
    if (!opened.value || !canUsers.value) return null
    return toPage(await listUsers({ sector_id: opened.value.id, per_page: SUMMARY_SIZE }, { signal }))
  }, { immediate: false })

  watch(() => opened.value?.id, (id) => {
    usersOpen.value = false
    // Não mostra números do setor anterior enquanto o novo carrega.
    for (const request of [users, tasks, occurrences, meetings]) {
      request.data.value = null
      request.loaded.value = false
    }
    if (!id) return
    tasks.load()
    occurrences.load()
    meetings.load()
  }, { immediate: true })
  watch(relation, () => {
    if (!opened.value) return
    tasks.load()
    occurrences.load()
  })

  function toggleUsers() {
    usersOpen.value = !usersOpen.value
    if (usersOpen.value && !users.loaded.value) users.load()
  }

  const summarize = (page, openCodes) => {
    const items = page?.items || []
    return {
      items,
      total: page?.meta?.total ?? items.length,
      open: items.filter((item) => openCodes.includes(item.status)),
      partial: (page?.meta?.total ?? items.length) > items.length,
    }
  }
  const taskSummary = computed(() => summarize(tasks.data.value, ['PENDING', 'IN_PROGRESS']))
  const occurrenceSummary = computed(() => summarize(occurrences.data.value, ['OPEN', 'IN_PROGRESS']))
  const meetingSummary = computed(() => {
    const result = summarize(meetings.data.value, ['SCHEDULED'])
    const now = Date.now()
    result.upcoming = result.open.filter((item) => !item.scheduled_at || new Date(item.scheduled_at).getTime() >= now)
    return result
  })
  const overdueTasks = computed(() => taskSummary.value.open.filter((item) => item.overdue).length)
  const urgentOccurrences = computed(() => occurrenceSummary.value.open.filter((item) => item.priority === 'URGENT' || item.priority === 'HIGH').length)
  const countText = (summary, count) => (summary.partial ? `${count}+` : String(count))

  // Prévia: abertas primeiro (já vêm da mais recente para a mais antiga).
  const preview = (summary) => [...summary.open, ...summary.items.filter((item) => !summary.open.includes(item))].slice(0, PREVIEW)
  const taskPreview = computed(() => preview(taskSummary.value))
  const occurrencePreview = computed(() => preview(occurrenceSummary.value))
  const meetingPreview = computed(() => {
    const summary = meetingSummary.value
    const past = summary.items.filter((item) => !summary.upcoming.includes(item)).reverse()
    return [...summary.upcoming, ...past].slice(0, PREVIEW)
  })

  // Links para as telas próprias (o filtro por setor na URL é uma sugestão; a tela de destino decide).
  const openTasks = () => go(`pendencias?${relation.value === 'responsible' ? 'responsible_sector_id' : 'involved_sector_id'}=${opened.value.id}`)
  const openOccurrences = () => go(`ocorrencias?${relation.value === 'responsible' ? 'responsible_sector_id' : 'involved_sector_id'}=${opened.value.id}`)

  // ---- Cadastro / edição / status ------------------------------------------------------------
  const modal = ref(null)
  const selected = ref(null)
  const form = ref(emptyForm())

  function openCreate() {
    form.value = emptyForm()
    selected.value = null
    save.clear()
    modal.value = 'form'
  }

  function openEdit(item) {
    selected.value = item
    form.value = { name: item.name || '', description: item.description || '' }
    save.clear()
    modal.value = 'form'
  }

  function askStatus(item) {
    selected.value = item
    modal.value = 'status'
  }

  function replaceSector(updated) {
    sectors.data.value = (sectors.data.value || []).map((item) => (item.id === updated.id ? { ...item, ...updated } : item))
  }

  const save = useAction(async () => {
    const body = { name: form.value.name.trim(), description: form.value.description.trim() || null }
    if (selected.value) {
      const updated = await updateSector(selected.value.id, body)
      replaceSector(updated)
      modal.value = null
      return { updated }
    }
    const created = await createSector(eventContext.activeEventId, body)
    modal.value = null
    await sectors.load()
    return { created }
  }, { success: (result) => (result?.created ? 'Setor cadastrado.' : 'Setor atualizado.') })

  const toggleStatus = useAction(async (item) => {
    const updated = await changeSectorStatus(item.id, !item.active)
    replaceSector(updated)
    modal.value = null
    return updated
  }, { success: (updated) => (updated?.active ? 'Setor ativado.' : 'Setor inativado.') })

  return {
    sectors,
    statusFilter,
    visibleSectors,
    mySectors,
    otherSectors,
    opened,
    openedId,
    showBack,
    globalScope,
    canCreate,
    canChangeStatus,
    canEdit,
    canTasks,
    canOccurrences,
    canMeetings,
    canUsers,
    selectSector,
    relation,
    tasks,
    occurrences,
    meetings,
    users,
    usersOpen,
    toggleUsers,
    taskSummary,
    occurrenceSummary,
    meetingSummary,
    overdueTasks,
    urgentOccurrences,
    countText,
    taskPreview,
    occurrencePreview,
    meetingPreview,
    openTasks,
    openOccurrences,
    modal,
    selected,
    form,
    openCreate,
    openEdit,
    askStatus,
    save,
    toggleStatus,
    go,
    label,
    tone,
    formatDate,
    formatDateTime,
  }
}
