// Lógica da página Tasks.vue (Pendências): GET/POST /events/{event}/tasks, GET/PATCH /tasks/{task},
// comentários, encaminhamento, conclusão e reabertura. Não existe exclusão de pendência.
// Ações do detalhe só conforme meta.abilities; o backend aplica escopo setorial e revalida tudo.
import { computed, ref, watch } from 'vue'
import { dueInfo, dueRange, useDemandSupport } from '@/js/components/demand-activity'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { eventContext } from '@/js/stores/event-context'
import * as tasksApi from '@/js/services/tasks'
import { formatDateTime, fromLocalInput, toLocalInput } from '@/js/utils/format'

const DUE_FILTERS = [['overdue', 'Atrasadas'], ['today', 'Vencem hoje'], ['soon', 'Próximos 2 dias']]

function sameInstant(a, b) {
  if (!a && !b) return true
  if (!a || !b) return false
  return Date.parse(a) === Date.parse(b)
}

function sortedIds(list) {
  return [...(list || [])].map(Number).sort((a, b) => a - b).join(',')
}

export function useTasks(props) {
  const support = useDemandSupport('tasks')
  const { mySectorId, globalScope, canRoute, activeSectors, rememberSectors, loadAssignees } = support

  const list = usePaged(async (query, requestOptions) => {
    if (!eventContext.activeEventId) return { data: [] }
    const { prazo, ...rest } = query
    const payload = await tasksApi.listTasks(eventContext.activeEventId, { ...rest, ...dueRange(prazo) }, requestOptions)
    rememberSectors(payload?.data)
    return payload
  }, {
    filters: { search: '', responsible_sector_id: '', involved_sector_id: '', status: '', priority: '', prazo: '' },
    perPage: 25,
    watchSources: () => eventContext.activeEventId,
  })

  // Detalhe (drawer): { data, meta: { abilities } }.
  const detailId = ref(null)
  const detail = useRequest(async ({ signal }) => {
    if (!detailId.value) return null
    const payload = await tasksApi.getTask(detailId.value, { signal })
    rememberSectors([payload?.data])
    return payload
  }, { immediate: false })
  const task = computed(() => (detail.data.value?.data?.id === detailId.value ? detail.data.value.data : null))
  const abilities = computed(() => detail.data.value?.meta?.abilities || {})
  const closed = computed(() => task.value?.status === 'COMPLETED')
  watch(detailId, (id) => {
    if (id) detail.load()
    else detail.data.value = null
  })
  watch(() => eventContext.activeEventId, () => { detailId.value = null; form.value = null; acting.value = null })

  async function refresh() {
    if (detailId.value) await detail.load()
    await list.load()
  }

  // Formulário: criação e edição de conteúdo/estrutura (prioridade, prazo, responsável individual, envolvidos).
  const form = ref(null)

  function blank() {
    return {
      id: null,
      title: '',
      description: '',
      origin_sector_id: mySectorId.value || '',
      responsible_sector_id: mySectorId.value || '',
      involved_sector_ids: [],
      assigned_user_id: '',
      priority: 'MEDIUM',
      due_at: '',
    }
  }

  function openNew(extra = {}) {
    save.clear()
    form.value = { ...blank(), ...extra } // o watch do setor responsável carrega os responsáveis possíveis
  }

  function openEdit() {
    const item = task.value
    if (!item) return
    save.clear()
    form.value = {
      id: item.id,
      title: item.title,
      description: item.description || '',
      origin_sector_id: item.origin_sector?.id || '',
      responsible_sector_id: item.responsible_sector?.id || '',
      involved_sector_ids: (item.involved_sectors || []).map((sector) => sector.id),
      assigned_user_id: item.assigned_user?.id || '',
      priority: item.priority || 'MEDIUM',
      due_at: toLocalInput(item.due_at),
    }
    loadAssignees(form.value.responsible_sector_id, item.assigned_user)
  }

  // Na criação, mudar o setor responsável troca as opções de responsável individual.
  watch(() => form.value && !form.value.id && form.value.responsible_sector_id, (id, before) => {
    if (!form.value || form.value.id || id === before) return
    form.value.assigned_user_id = ''
    form.value.involved_sector_ids = form.value.involved_sector_ids.filter((sid) => sid !== Number(id))
    loadAssignees(id)
  })

  // Quem pode escolher origem (alcance global) e responsável (global ou com tasks.route).
  const chooseOrigin = computed(() => globalScope.value)
  const chooseResponsible = computed(() => globalScope.value || canRoute.value)
  const chooseInvolved = computed(() => canRoute.value)
  const involvedOptions = computed(() => {
    const f = form.value
    if (!f) return []
    return activeSectors.value.filter((sector) => sector.id !== Number(f.origin_sector_id) && sector.id !== Number(f.responsible_sector_id))
  })

  function toggleInvolved(id) {
    const current = form.value.involved_sector_ids
    form.value.involved_sector_ids = current.includes(id) ? current.filter((item) => item !== id) : [...current, id]
  }

  function sectorNameById(id) {
    return support.allSectors.value.find((sector) => sector.id === Number(id))?.name || '—'
  }

  const save = useAction(async () => {
    const f = form.value
    if (f.id) {
      const item = task.value
      const body = {}
      if (f.title.trim() !== item.title) body.title = f.title.trim()
      if (f.description.trim() !== (item.description || '')) body.description = f.description.trim()
      if (f.priority !== item.priority) body.priority = f.priority
      const due = fromLocalInput(f.due_at)
      if (!sameInstant(due, item.due_at)) body.due_at = due
      const assigned = f.assigned_user_id ? Number(f.assigned_user_id) : null
      if (assigned !== (item.assigned_user?.id ?? null)) body.assigned_user_id = assigned
      if (sortedIds(f.involved_sector_ids) !== sortedIds((item.involved_sectors || []).map((sector) => sector.id))) body.involved_sector_ids = f.involved_sector_ids.map(Number)
      if (!Object.keys(body).length) {
        form.value = null
        return { changed: 0 }
      }
      await tasksApi.updateTask(item.id, body)
      form.value = null
      await refresh()
      return { changed: 1 }
    }
    const body = {
      title: f.title.trim(),
      description: f.description.trim(),
      priority: f.priority,
    }
    if (chooseOrigin.value) body.origin_sector_id = f.origin_sector_id ? Number(f.origin_sector_id) : null
    if (chooseResponsible.value) body.responsible_sector_id = f.responsible_sector_id ? Number(f.responsible_sector_id) : null
    if (chooseInvolved.value && f.involved_sector_ids.length) body.involved_sector_ids = f.involved_sector_ids.map(Number)
    if (f.assigned_user_id) body.assigned_user_id = Number(f.assigned_user_id)
    if (f.due_at) body.due_at = fromLocalInput(f.due_at)
    const created = await tasksApi.createTask(eventContext.activeEventId, body)
    form.value = null
    await list.load()
    if (created?.data?.id) detailId.value = created.data.id
    return { created: created?.data }
  }, {
    success: (result) => {
      if (result?.created) return result.created.reference ? `Pendência ${result.created.reference} criada.` : 'Pendência criada.'
      return result?.changed ? 'Pendência atualizada.' : 'Nenhuma alteração para salvar.'
    },
  })

  // Status (PATCH), concluir e reabrir (rotas próprias). Concluir/reabrir pedem confirmação em modal.
  const acting = ref(null) // { type: 'complete' | 'reopen', message }

  const setStatus = useAction(async (status) => {
    await tasksApi.updateTask(task.value.id, { status })
    await refresh()
    return status
  }, { success: (status) => `Pendência marcada como ${label('taskStatus', status).toLowerCase()}.` })

  const runAction = useAction(async () => {
    const current = acting.value
    const message = current.message.trim() || null
    if (current.type === 'complete') await tasksApi.completeTask(task.value.id, { message })
    else await tasksApi.reopenTask(task.value.id, { message })
    acting.value = null
    await refresh()
    return current.type
  }, { success: (type) => (type === 'complete' ? 'Pendência concluída.' : 'Pendência reaberta.') })

  function openAction(type) {
    runAction.clear()
    acting.value = { type, message: '' }
  }

  // Links: ?ver=ID abre o detalhe; ?novo=1 abre a criação; ?responsible_sector_id=ID / ?involved_sector_id=ID (ou ?setor=ID) filtram por setor.
  watch(() => props.params, (params = {}) => {
    const id = (value) => (value && /^\d+$/.test(value) ? Number(value) : null)
    const responsible = id(params.responsible_sector_id) || id(params.setor)
    if (responsible) list.filters.responsible_sector_id = responsible
    if (id(params.involved_sector_id)) list.filters.involved_sector_id = id(params.involved_sector_id)
    if (params.ver && /^\d+$/.test(params.ver)) detailId.value = Number(params.ver)
    if (params.novo && support.canCreate.value) openNew()
    if (params.ver || params.novo || params.setor || params.responsible_sector_id || params.involved_sector_id) window.history.replaceState(null, '', '#/pendencias')
  }, { immediate: true })

  function openOccurrence(id) {
    go(`ocorrencias?ver=${id}`)
  }

  return {
    ...support,
    list,
    detailId,
    detail,
    task,
    abilities,
    closed,
    refresh,
    form,
    openNew,
    openEdit,
    chooseOrigin,
    chooseResponsible,
    chooseInvolved,
    involvedOptions,
    toggleInvolved,
    sectorNameById,
    save,
    acting,
    openAction,
    runAction,
    setStatus,
    openOccurrence,
    dueInfo,
    DUE_FILTERS,
    label,
    tone,
    options,
    formatDateTime,
  }
}
