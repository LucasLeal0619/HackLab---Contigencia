// Lógica da página Audit.vue: auditoria global gravada pelo backend (somente leitura, audit_logs.view).
// Lista paginada (sem before/after); o detalhe (GET /audit-logs/{id}) traz before/after/changes, IP e User-Agent.
import { computed, ref } from 'vue'
import { unwrap } from '@/js/api/client'
import { fetchAll } from '@/js/api/pagination'
import { usePaged, useRequest } from '@/js/composables/async'
import { label, options } from '@/js/labels'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as auditApi from '@/js/services/audit'
import { listSectors } from '@/js/services/sectors'
import { listUsers } from '@/js/services/users'
import { formatDateTime, fromLocalInput } from '@/js/utils/format'

const FILTERS = {
  search: '', event: '', module: '', action: '', actor_user_id: '', actor_role: '', actor_sector_id: '',
  entity_type: '', entity_id: '', ip_address: '', date_from: '', date_to: '',
}

// Filtros da tela → query do backend (datas no fuso do evento; "event" vira event_id ou has_event).
function toQuery(query) {
  const { event, date_from: from, date_to: to, ...rest } = query
  const out = { ...rest }
  if (event === 'none') out.has_event = 'false'
  else if (event === 'any') out.has_event = 'true'
  else if (event) out.event_id = event
  if (from) out.date_from = fromLocalInput(`${from}T00:00`)
  if (to) {
    const end = fromLocalInput(`${to}T23:59`)
    out.date_to = end ? new Date(Date.parse(end) + 59999).toISOString() : undefined
  }
  for (const key of ['entity_type', 'entity_id', 'ip_address']) {
    if (typeof out[key] === 'string') out[key] = out[key].trim() || undefined
  }
  return out
}

export function useAuditPage() {
  const meta = useRequest(({ signal }) => auditApi.auditMeta({ signal }).then(unwrap), { initial: { modules: [], actions: [] } })

  const list = usePaged((query, opts) => auditApi.listAuditLogs(toQuery(query), opts), {
    filters: { ...FILTERS },
    perPage: 50,
    debounced: ['search', 'entity_type', 'entity_id', 'ip_address'],
  })
  const filters = list.filters

  const modules = computed(() => meta.data.value?.modules || [])
  const actions = computed(() => (meta.data.value?.actions || []).filter((item) => !filters.module || item.module === filters.module))
  function setModule(code) {
    filters.module = code
    if (filters.action && !actions.value.some((item) => item.code === filters.action)) filters.action = ''
  }

  // Seletores de usuário e setor: só quando a pessoa abre o filtro (backend lento) e se tiver permissão.
  const canUsers = computed(() => hasPermission('users.view'))
  const canSectors = computed(() => hasPermission('sectors.view'))
  const users = useRequest(() => (canUsers.value ? fetchAll((query) => listUsers(query)) : []), { immediate: false, initial: [] })
  const sectors = useRequest(() => (canSectors.value && eventContext.activeEventId ? listSectors(eventContext.activeEventId).then((payload) => unwrap(payload) || []) : []), { immediate: false, initial: [] })
  function loadUsers() {
    if (!users.loaded.value && !users.loading.value) users.load()
  }
  function loadSectors() {
    if (!sectors.loaded.value && !sectors.loading.value) sectors.load()
  }

  const advancedActive = computed(() => Object.keys(FILTERS).filter((key) => key !== 'search' && filters[key] !== FILTERS[key]).length)

  const detailId = ref(null)
  const detail = useRequest(({ signal }) => (detailId.value ? auditApi.getAuditLog(detailId.value, { signal }).then(unwrap) : null), { immediate: false })
  const summary = ref(null)
  function openDetail(item) {
    summary.value = item
    detailId.value = item.id
    detail.data.value = null
    detail.load()
  }
  function closeDetail() {
    detailId.value = null
  }

  function who(entry) {
    const role = entry.actor?.role?.name || (entry.actor?.role?.code ? label('role', entry.actor.role.code) : '')
    return [role, entry.actor?.sector_name].filter(Boolean).join(' · ') || '—'
  }

  function actorName(entry) {
    if (!entry.actor) return 'Sistema'
    return entry.actor.name || entry.actor.email || `Usuário #${entry.actor.user_id}`
  }

  function show(value, exists = true) {
    if (!exists) return '(ausente)'
    if (value === null || value === undefined) return '(vazio)'
    if (typeof value === 'object') return JSON.stringify(value)
    if (typeof value === 'boolean') return value ? 'sim' : 'não'
    return String(value)
  }

  function pretty(value) {
    return value === null || value === undefined ? '' : JSON.stringify(value, null, 2)
  }

  function clear() {
    Object.keys(FILTERS).forEach((key) => { if (key !== 'search') filters[key] = FILTERS[key] })
  }

  return {
    meta,
    list,
    filters,
    modules,
    actions,
    setModule,
    canUsers,
    canSectors,
    users,
    sectors,
    loadUsers,
    loadSectors,
    advancedActive,
    roleOptions: options('role'),
    events: computed(() => eventContext.events),
    detailId,
    detail,
    summary,
    openDetail,
    closeDetail,
    who,
    actorName,
    show,
    pretty,
    clear,
    formatDateTime: (value) => formatDateTime(value),
  }
}
