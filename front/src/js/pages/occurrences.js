// Lógica da página Occurrences.vue: GET/POST /events/{event}/occurrences, GET/PATCH /occurrences/{occurrence},
// comentários, encaminhamento, resolução (com solução obrigatória), reabertura e geração de pendência.
// Não existe exclusão de ocorrência. Dia (EventDay) e equipe vêm do evento real.
import { computed, ref, shallowRef, watch } from 'vue'
import { useDemandSupport } from '@/js/components/demand-activity'
import { fetchAll } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { activeDays, eventContext } from '@/js/stores/event-context'
import * as occurrencesApi from '@/js/services/occurrences'
import { listTeams } from '@/js/services/teams'
import { dayLabel, formatDateTime, fromLocalInput, toLocalInput } from '@/js/utils/format'

const DETAIL_FIELDS = ['category', 'location', 'notes']

function sameInstant(a, b) {
  if (!a && !b) return true
  if (!a || !b) return false
  return Date.parse(a) === Date.parse(b)
}

function sortedIds(list) {
  return [...(list || [])].map(Number).sort((a, b) => a - b).join(',')
}

export function useOccurrences(props) {
  const support = useDemandSupport('occurrences')
  const { mySectorId, globalScope, canRoute, activeSectors, rememberSectors, loadAssignees } = support

  const list = usePaged(async (query, requestOptions) => {
    if (!eventContext.activeEventId) return { data: [] }
    const payload = await occurrencesApi.listOccurrences(eventContext.activeEventId, query, requestOptions)
    rememberSectors(payload?.data)
    return payload
  }, {
    filters: { search: '', category: '', responsible_sector_id: '', involved_sector_id: '', priority: '', status: '', event_day_id: '', team_id: '' },
    perPage: 25,
    watchSources: () => eventContext.activeEventId,
  })

  // Equipes do evento: carregadas sob demanda (formulário ou filtro), para não pesar a abertura da tela.
  const teams = shallowRef([])
  const teamsState = ref('idle') // idle | loading | ready | error
  async function ensureTeams() {
    if (!eventContext.activeEventId || teamsState.value === 'loading' || teamsState.value === 'ready') return
    teamsState.value = 'loading'
    try {
      const eventId = eventContext.activeEventId
      teams.value = await fetchAll((query) => listTeams(eventId, query))
      teamsState.value = 'ready'
    } catch {
      teamsState.value = 'error'
    }
  }

  const days = computed(() => activeDays.value)

  // Detalhe (drawer): { data, meta: { abilities } }.
  const detailId = ref(null)
  const detail = useRequest(async ({ signal }) => {
    if (!detailId.value) return null
    const payload = await occurrencesApi.getOccurrence(detailId.value, { signal })
    rememberSectors([payload?.data])
    return payload
  }, { immediate: false })
  const occurrence = computed(() => (detail.data.value?.data?.id === detailId.value ? detail.data.value.data : null))
  const abilities = computed(() => detail.data.value?.meta?.abilities || {})
  const closed = computed(() => occurrence.value?.status === 'RESOLVED')
  watch(detailId, (id) => {
    if (id) detail.load()
    else detail.data.value = null
  })
  watch(() => eventContext.activeEventId, () => {
    detailId.value = null
    form.value = null
    acting.value = null
    generating.value = null
    teams.value = []
    teamsState.value = 'idle'
  })

  async function refresh() {
    if (detailId.value) await detail.load()
    await list.load()
  }

  // Formulário: registro e edição (conteúdo/estrutura exige gestão do setor responsável).
  const form = ref(null)

  function blank() {
    return {
      id: null,
      title: '',
      description: '',
      category: 'OTHER',
      origin_sector_id: mySectorId.value || '',
      responsible_sector_id: mySectorId.value || '',
      involved_sector_ids: [],
      assigned_user_id: '',
      priority: 'MEDIUM',
      event_day_id: '',
      occurred_at: '',
      location: '',
      team_id: '',
      notes: '',
    }
  }

  function openNew(extra = {}) {
    save.clear()
    form.value = { ...blank(), ...extra } // o watch do setor responsável carrega os responsáveis possíveis
    ensureTeams()
  }

  function openEdit() {
    const item = occurrence.value
    if (!item) return
    save.clear()
    form.value = {
      id: item.id,
      title: item.title,
      description: item.description || '',
      category: item.category || 'OTHER',
      origin_sector_id: item.origin_sector?.id || '',
      responsible_sector_id: item.responsible_sector?.id || '',
      involved_sector_ids: (item.involved_sectors || []).map((sector) => sector.id),
      assigned_user_id: item.assigned_user?.id || '',
      priority: item.priority || 'MEDIUM',
      event_day_id: item.event_day?.id || '',
      occurred_at: toLocalInput(item.occurred_at),
      location: item.location || '',
      team_id: item.team?.id || '',
      notes: item.notes || '',
    }
    loadAssignees(form.value.responsible_sector_id, item.assigned_user)
    ensureTeams()
  }

  watch(() => form.value && !form.value.id && form.value.responsible_sector_id, (id, before) => {
    if (!form.value || form.value.id || id === before) return
    form.value.assigned_user_id = ''
    form.value.involved_sector_ids = form.value.involved_sector_ids.filter((sid) => sid !== Number(id))
    loadAssignees(id)
  })

  // Ao escolher a data/horário, sugere o dia do evento correspondente (no fuso do evento).
  watch(() => form.value?.occurred_at, (value) => {
    if (!form.value || !value || form.value.event_day_id) return
    const day = days.value.find((item) => item.date === value.slice(0, 10))
    if (day) form.value.event_day_id = day.id
  })

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

  const nullableId = (value) => (value ? Number(value) : null)
  const nullableText = (value) => (String(value || '').trim() || null)

  const save = useAction(async () => {
    const f = form.value
    if (f.id) {
      const item = occurrence.value
      const body = {}
      if (f.title.trim() !== item.title) body.title = f.title.trim()
      if (f.description.trim() !== (item.description || '')) body.description = f.description.trim()
      if (f.priority !== item.priority) body.priority = f.priority
      DETAIL_FIELDS.forEach((key) => {
        const value = key === 'category' ? f.category : nullableText(f[key])
        if (value !== (item[key] ?? null)) body[key] = value
      })
      if (nullableId(f.event_day_id) !== (item.event_day?.id ?? null)) body.event_day_id = nullableId(f.event_day_id)
      if (nullableId(f.team_id) !== (item.team?.id ?? null)) body.team_id = nullableId(f.team_id)
      const at = fromLocalInput(f.occurred_at)
      if (!sameInstant(at, item.occurred_at)) body.occurred_at = at
      const assigned = nullableId(f.assigned_user_id)
      if (assigned !== (item.assigned_user?.id ?? null)) body.assigned_user_id = assigned
      if (sortedIds(f.involved_sector_ids) !== sortedIds((item.involved_sectors || []).map((sector) => sector.id))) body.involved_sector_ids = f.involved_sector_ids.map(Number)
      if (!Object.keys(body).length) {
        form.value = null
        return { changed: 0 }
      }
      await occurrencesApi.updateOccurrence(item.id, body)
      form.value = null
      await refresh()
      return { changed: 1 }
    }
    const body = {
      title: f.title.trim(),
      description: f.description.trim(),
      category: f.category,
      priority: f.priority,
      event_day_id: nullableId(f.event_day_id),
      occurred_at: fromLocalInput(f.occurred_at),
      location: nullableText(f.location),
      team_id: nullableId(f.team_id),
      notes: nullableText(f.notes),
    }
    if (chooseOrigin.value) body.origin_sector_id = nullableId(f.origin_sector_id)
    if (chooseResponsible.value) body.responsible_sector_id = nullableId(f.responsible_sector_id)
    if (chooseInvolved.value && f.involved_sector_ids.length) body.involved_sector_ids = f.involved_sector_ids.map(Number)
    if (f.assigned_user_id) body.assigned_user_id = Number(f.assigned_user_id)
    const created = await occurrencesApi.createOccurrence(eventContext.activeEventId, body)
    form.value = null
    await list.load()
    if (created?.data?.id) detailId.value = created.data.id
    return { created: created?.data }
  }, {
    success: (result) => {
      if (result?.created) return result.created.reference ? `Ocorrência ${result.created.reference} registrada.` : 'Ocorrência registrada.'
      return result?.changed ? 'Ocorrência atualizada.' : 'Nenhuma alteração para salvar.'
    },
  })

  // Status (PATCH entre OPEN e IN_PROGRESS), resolver (solução obrigatória) e reabrir.
  const acting = ref(null) // { type: 'resolve' | 'reopen', text }

  const setStatus = useAction(async (status) => {
    await occurrencesApi.updateOccurrence(occurrence.value.id, { status })
    await refresh()
    return status
  }, { success: (status) => `Ocorrência marcada como ${label('occurrenceStatus', status).toLowerCase()}.` })

  const runAction = useAction(async () => {
    const current = acting.value
    if (current.type === 'resolve') await occurrencesApi.resolveOccurrence(occurrence.value.id, { resolution: current.text.trim() })
    else await occurrencesApi.reopenOccurrence(occurrence.value.id, { message: current.text.trim() || null })
    acting.value = null
    await refresh()
    return current.type
  }, { success: (type) => (type === 'resolve' ? 'Ocorrência resolvida.' : 'Ocorrência reaberta.') })

  function openAction(type) {
    runAction.clear()
    acting.value = { type, text: '' }
  }

  // Gerar pendência a partir da ocorrência (Administrador ou Gestor de setor relacionado).
  const generating = ref(null)
  const generateOrigin = computed(() => globalScope.value)

  function openGenerate() {
    const item = occurrence.value
    generate.clear()
    generating.value = {
      title: '',
      description: `Originada da ocorrência ${item.reference}: ${item.title}.`,
      origin_sector_id: mySectorId.value || item.responsible_sector?.id || '',
      responsible_sector_id: item.responsible_sector?.active ? item.responsible_sector.id : '',
      assigned_user_id: '',
      priority: item.priority || 'MEDIUM',
      due_at: '',
    } // o watch do setor responsável carrega os responsáveis possíveis
  }

  watch(() => generating.value?.responsible_sector_id, (id, before) => {
    if (!generating.value || id === before) return
    generating.value.assigned_user_id = ''
    loadAssignees(id)
  })

  const generate = useAction(async () => {
    const g = generating.value
    const body = {
      title: g.title.trim(),
      description: g.description.trim(),
      responsible_sector_id: nullableId(g.responsible_sector_id),
      priority: g.priority,
    }
    if (generateOrigin.value) body.origin_sector_id = nullableId(g.origin_sector_id)
    if (g.assigned_user_id) body.assigned_user_id = Number(g.assigned_user_id)
    if (g.due_at) body.due_at = fromLocalInput(g.due_at)
    const created = await occurrencesApi.generateOccurrenceTask(occurrence.value.id, body)
    generating.value = null
    await refresh()
    return created?.data
  }, { success: (task) => (task?.reference ? `Pendência ${task.reference} criada.` : 'Pendência criada.') })

  // Links: ?ver=ID abre o detalhe; ?novo=1 abre o registro; ?responsible_sector_id=ID / ?involved_sector_id=ID (ou ?setor=ID) filtram por setor.
  watch(() => props.params, (params = {}) => {
    const id = (value) => (value && /^\d+$/.test(value) ? Number(value) : null)
    const responsible = id(params.responsible_sector_id) || id(params.setor)
    if (responsible) list.filters.responsible_sector_id = responsible
    if (id(params.involved_sector_id)) list.filters.involved_sector_id = id(params.involved_sector_id)
    if (params.ver && /^\d+$/.test(params.ver)) detailId.value = Number(params.ver)
    if (params.novo && support.canCreate.value) {
      openNew({
        title: params.titulo || '',
        category: options('occurrenceCategory').some(([code]) => code === params.categoria) ? params.categoria : 'OTHER',
        location: params.local || '',
      })
    }
    if (params.ver || params.novo || params.setor || params.responsible_sector_id || params.involved_sector_id) window.history.replaceState(null, '', '#/ocorrencias')
  }, { immediate: true })

  function openTask(id) {
    go(`pendencias?ver=${id}`)
  }

  // EventDay da ocorrência: rótulo completo se o dia está no evento ativo; senão o que a API mandou.
  function shortDay(eventDay) {
    return eventDay ? eventDay.label || `Dia ${eventDay.day_number}` : ''
  }

  function dayText(eventDay) {
    if (!eventDay) return '—'
    const day = days.value.find((item) => item.id === eventDay.id)
    return day ? dayLabel(day) : shortDay(eventDay)
  }

  function teamName(id) {
    return teams.value.find((team) => team.id === Number(id))?.name || ''
  }

  return {
    ...support,
    list,
    teams,
    teamsState,
    ensureTeams,
    teamName,
    days,
    detailId,
    detail,
    occurrence,
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
    generating,
    generateOrigin,
    openGenerate,
    generate,
    openTask,
    dayLabel,
    dayText,
    shortDay,
    label,
    tone,
    options,
    formatDateTime,
  }
}
