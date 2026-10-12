// Colaboração entre setores dentro de uma Pendência ou Ocorrência (dados reais da API):
// setores (origem, responsável, envolvidos), histórico (DemandInteraction), comentários e encaminhamento.
// Não é um chat genérico: tudo existe só no contexto da demanda. O backend revalida cada ação.
import { computed, ref, watch } from 'vue'
import { useAction, useRequest } from '@/js/composables/async'
import { label } from '@/js/labels'
import { auth, hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { listSectors } from '@/js/services/sectors'
import { listUsers } from '@/js/services/users'
import { commentOccurrence, forwardOccurrence, listRoutingSectors as occurrenceRoutingSectors } from '@/js/services/occurrences'
import { commentTask, forwardTask, listRoutingSectors as taskRoutingSectors } from '@/js/services/tasks'
import { formatDateTime, fromLocalInput, toLocalInput } from '@/js/utils/format'

const KINDS = {
  task: { noun: 'pendência', statusGroup: 'taskStatus', comment: commentTask, forward: forwardTask },
  occurrence: { noun: 'ocorrência', statusGroup: 'occurrenceStatus', comment: commentOccurrence, forward: forwardOccurrence },
}

// Campos editados (interação UPDATED) em português.
const FIELDS = {
  title: 'título', description: 'descrição', category: 'categoria', event_day_id: 'dia', occurred_at: 'data/horário',
  location: 'local', team_id: 'equipe', notes: 'observação', priority: 'prioridade', due_at: 'prazo',
}

export function useDemandActivity(props, emit) {
  const kind = computed(() => KINDS[props.kind] || KINDS.task)
  const demand = computed(() => props.demand)
  const involved = computed(() => (demand.value?.involved_sectors || []).map((sector) => sector.name))
  // Mais recentes primeiro (como no protótipo).
  const history = computed(() => [...(demand.value?.interactions || [])].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)) || b.id - a.id))
  const canComment = computed(() => Boolean(props.abilities?.comment))
  const canForward = computed(() => Boolean(props.abilities?.forward) && !props.closed)
  const message = ref('')
  const forwarding = ref(null)
  const targets = computed(() => (props.sectors || []).filter((sector) => sector.active && sector.id !== demand.value?.responsible_sector?.id))

  function sectorName(sector) {
    return sector ? `${sector.name}${sector.active === false ? ' (inativo)' : ''}` : '—'
  }

  function userName(id) {
    if (!id) return 'ninguém'
    if (demand.value?.assigned_user?.id === id) return demand.value.assigned_user.name || demand.value.assigned_user.email
    return 'outro usuário'
  }

  // Texto de cada item do histórico a partir do tipo + metadados (sem inventar nada além do que a API registrou).
  function describe(entry) {
    const meta = entry.metadata || {}
    const status = (code) => label(kind.value.statusGroup, code)
    switch (entry.type) {
      case 'CREATED':
        return meta.source_occurrence_reference ? `Criou a ${kind.value.noun} a partir da ocorrência ${meta.source_occurrence_reference}.` : `Registrou a ${kind.value.noun}.`
      case 'UPDATED':
        return `Editou ${(meta.fields || []).map((field) => FIELDS[field] || field).join(', ') || 'a demanda'}.`
      case 'COMMENT':
        return `“${entry.message}”`
      case 'STATUS_CHANGED':
        return `Status: ${status(meta.from)} → ${status(meta.to)}.`
      case 'FORWARDED':
        return `Encaminhou de ${meta.from_sector_name || '—'} para ${meta.to_sector_name || '—'}.${entry.message ? ` Motivo: ${entry.message}` : ''}`
      case 'ASSIGNEE_CHANGED':
        return meta.to ? `Responsável individual: ${userName(meta.to)}.` : 'Removeu o responsável individual.'
      case 'PRIORITY_CHANGED':
        return `Prioridade: ${label('priority', meta.from)} → ${label('priority', meta.to)}.`
      case 'DUE_CHANGED':
        return `Prazo: ${meta.from ? formatDateTime(meta.from) : 'sem prazo'} → ${meta.to ? formatDateTime(meta.to) : 'sem prazo'}.`
      case 'SECTOR_ADDED':
        return `Envolveu o setor ${meta.sector_name || '—'}.`
      case 'SECTOR_REMOVED':
        return `Removeu o setor ${meta.sector_name || '—'} dos envolvidos.`
      case 'COMPLETED':
        return entry.message ? `Concluiu a pendência. Observação: ${entry.message}` : 'Concluiu a pendência.'
      case 'RESOLVED':
        return `Resolveu a ocorrência. Solução: ${entry.message || '—'}`
      case 'REOPENED':
        return `Reabriu a ${kind.value.noun}.${entry.message ? ` Motivo: ${entry.message}` : ''}${meta.cleared_assigned_user_id ? ' O responsável individual deixou de pertencer ao setor e foi removido.' : ''}`
      case 'TASK_GENERATED':
        return `Gerou a pendência ${meta.task_reference || ''}.`.replace(' .', '.')
      default:
        return entry.message || ''
    }
  }

  const send = useAction(async () => {
    const text = message.value.trim()
    if (!text) return undefined
    await kind.value.comment(demand.value.id, { message: text })
    message.value = ''
    emit('changed')
    return true
  }, { success: (sent) => (sent ? 'Atualização registrada.' : '') })

  function openForward() {
    forward.clear()
    forwarding.value = { sector_id: '', reason: '' }
  }

  const forward = useAction(async () => {
    const current = forwarding.value
    const target = targets.value.find((sector) => sector.id === Number(current.sector_id))
    await kind.value.forward(demand.value.id, { sector_id: current.sector_id ? Number(current.sector_id) : null, reason: current.reason.trim() })
    forwarding.value = null
    emit('changed')
    return target?.name || ''
  }, { success: (name) => (name ? `Encaminhada para ${name}.` : 'Encaminhada.') })

  return {
    kind,
    involved,
    history,
    canComment,
    canForward,
    message,
    forwarding,
    targets,
    sectorName,
    describe,
    send,
    openForward,
    forward,
    label,
    formatDateTime,
  }
}

// Apoio comum às telas de Pendências e Ocorrências: setores reais do evento, escopo do usuário
// e responsáveis individuais possíveis (usuário ativo do setor responsável).
export function useDemandSupport(prefix) {
  const me = computed(() => auth.user)
  const mySectorId = computed(() => auth.user?.sector?.id || null)
  // Sem setor = alcance global (Administrador). Com setor = escopo setorial (Gestor/Editor/Consultor).
  const globalScope = computed(() => !mySectorId.value)
  const canCreate = computed(() => hasPermission(`${prefix}.create`))
  const canRoute = computed(() => hasPermission(`${prefix}.route`))
  const canListUsers = computed(() => hasPermission('users.view'))

  const sectors = useRequest(() => (eventContext.activeEventId ? listSectors(eventContext.activeEventId) : []), {
    initial: [],
    watchSources: () => eventContext.activeEventId,
  })
  // Destinos (encaminhar/criar para outro setor): contrato próprio do backend, só para quem tem "route":
  // GET /events/{e}/{tasks|occurrences}/routing-sectors → setores ativos do evento. A listagem normal de
  // setores continua escopada ao próprio setor do Gestor/Editor.
  const routing = useRequest(({ signal }) => {
    const eventId = eventContext.activeEventId
    if (!eventId || !canRoute.value) return []
    return (prefix === 'occurrences' ? occurrenceRoutingSectors : taskRoutingSectors)(eventId, { signal })
  }, { initial: [], watchSources: () => eventContext.activeEventId })

  // Para os filtros e nomes, somam-se os setores já vistos nas demandas carregadas (inclui inativos).
  const seen = ref({})
  function rememberSectors(items) {
    const next = { ...seen.value }
    let changed = false
    ;(items || []).filter(Boolean).forEach((item) => {
      [item.origin_sector, item.responsible_sector, ...(item.involved_sectors || [])].filter(Boolean).forEach((sector) => {
        const before = next[sector.id]
        if (!before || before.name !== sector.name || before.active !== sector.active) {
          next[sector.id] = { id: sector.id, name: sector.name, active: sector.active !== false }
          changed = true
        }
      })
    })
    if (changed) seen.value = next
  }
  watch(() => eventContext.activeEventId, () => { seen.value = {} })
  const allSectors = computed(() => {
    const byId = { ...seen.value }
    ;(routing.data.value || []).forEach((item) => { byId[item.id] = { ...item, active: true } })
    ;(sectors.data.value || []).forEach((item) => { byId[item.id] = item })
    return Object.values(byId).sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'))
  })
  // Destinos válidos: com "route", a lista oficial do backend; sem (Editor), só o próprio setor ativo.
  const activeSectors = computed(() => (canRoute.value
    ? [...(routing.data.value || [])].map((item) => ({ ...item, active: true }))
    : (sectors.data.value || []).filter((item) => item.active)))
  // Lista incompleta só para quem tem setor e não pode encaminhar (não escolhe destino).
  const partialSectors = computed(() => !globalScope.value && !canRoute.value)

  // Responsáveis individuais: com users.view, lista real do setor; sem, só o próprio usuário (se for do setor).
  const assignees = ref([])
  const assigneesLoading = ref(false)
  let ticket = 0
  async function loadAssignees(sectorId, current = null) {
    const mine = ++ticket
    const id = Number(sectorId) || null
    const base = current ? [{ id: current.id, name: current.name || current.email }] : []
    if (!id) {
      assignees.value = base
      return
    }
    let list = []
    if (canListUsers.value) {
      assigneesLoading.value = true
      try {
        const payload = await listUsers({ sector_id: id, status: 'ACTIVE', per_page: 100 })
        list = (payload?.data || []).map((user) => ({ id: user.id, name: user.person?.full_name || user.email }))
      } catch {
        list = []
      } finally {
        if (mine === ticket) assigneesLoading.value = false
      }
    } else if (mySectorId.value === id && me.value) {
      list = [{ id: me.value.id, name: `${me.value.person?.full_name || me.value.email} (eu)` }]
    }
    if (mine !== ticket) return
    const merged = [...list]
    base.forEach((item) => { if (!merged.some((user) => user.id === item.id)) merged.unshift(item) })
    assignees.value = merged
  }

  return { me, mySectorId, globalScope, canCreate, canRoute, canListUsers, sectors, routing, allSectors, activeSectors, partialSectors, rememberSectors, assignees, assigneesLoading, loadAssignees }
}

// Converte um filtro de prazo ("hoje", "próximos 2 dias") em limites ISO no fuso do evento.
export function dueRange(code) {
  const today = toLocalInput(new Date()).slice(0, 10)
  if (!today) return {}
  const plus = (days) => {
    const [y, m, d] = today.split('-').map(Number)
    const date = new Date(Date.UTC(y, m - 1, d + days))
    return date.toISOString().slice(0, 10)
  }
  if (code === 'today') return { due_after: fromLocalInput(`${today}T00:00`), due_before: fromLocalInput(`${today}T23:59`) }
  if (code === 'soon') return { due_after: new Date().toISOString(), due_before: fromLocalInput(`${plus(2)}T23:59`) }
  if (code === 'overdue') return { overdue: 1 }
  return {}
}

// Situação do prazo de uma pendência (rótulo + classe .due do protótipo), no fuso do evento.
export function dueInfo(task) {
  if (!task) return { label: '—', tone: '' }
  if (task.status === 'COMPLETED') return { label: 'Concluída', tone: 'done' }
  if (!task.due_at) return { label: 'Sem prazo', tone: '' }
  if (task.overdue) return { label: 'Atrasada', tone: 'late' }
  const day = (value) => toLocalInput(value).slice(0, 10)
  const today = day(new Date())
  const due = day(task.due_at)
  if (due === today) return { label: 'Vence hoje', tone: 'today' }
  const diff = Math.round((Date.parse(`${due}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86400000)
  if (diff <= 2) return { label: 'Próximo do prazo', tone: 'soon' }
  return { label: 'Dentro do prazo', tone: 'ok' }
}
