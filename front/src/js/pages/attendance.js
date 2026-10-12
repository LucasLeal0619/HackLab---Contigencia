// Lógica da página Attendance.vue ("Inscrições e Presença"): integração Even3 pelo backend.
// O HackLab não credencia ninguém: inscrições, entradas e check-ins vêm da Even3 via sincronização/webhook.
// Sem scanner, câmera, QR, credencial local ou check-in manual. O front nunca vê token, segredo ou header do webhook.
import { computed, ref, watch } from 'vue'
import { unwrap } from '@/js/api/client'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { activeDays, eventContext } from '@/js/stores/event-context'
import * as integrationsApi from '@/js/services/integrations'
import { dayLabel, formatDate, formatDateTime, formatNumber } from '@/js/utils/format'

// Contadores do sync (IntegrationSyncService). Chave desconhecida aparece com o próprio código.
const COUNTER_LABELS = {
  event_checked: 'Evento consultado',
  tickets_seen: 'Entradas lidas',
  tickets_created: 'Entradas novas',
  tickets_updated: 'Entradas atualizadas',
  tickets_unchanged: 'Entradas sem mudança',
  tickets_deactivated: 'Entradas desativadas',
  tickets_invalid: 'Entradas inválidas',
  attendee_pages: 'Páginas de inscritos',
  attendees_seen: 'Inscrições lidas',
  attendees_created: 'Inscrições novas',
  attendees_updated: 'Inscrições atualizadas',
  attendees_unchanged: 'Inscrições sem mudança',
  attendees_rejected: 'Inscrições rejeitadas',
  attendees_conflicts: 'Conflitos de identidade',
  attendees_failed: 'Falhas de processamento',
  persons_created: 'Pessoas criadas',
}

const BOOL_OPTIONS = [['true', 'Sim'], ['false', 'Não']]

export function useAttendance(props) {
  const canView = computed(() => hasPermission('integrations.view'))
  const canManage = computed(() => hasPermission('integrations.manage'))
  const canSync = computed(() => hasPermission('integrations.sync'))
  const canRegistrations = computed(() => hasPermission('event_registrations.view'))
  const canAttendance = computed(() => hasPermission('attendance.view'))
  const canMaterialize = computed(() => canManage.value && hasPermission('participants.manage'))
  const readOnly = computed(() => !canManage.value && !canSync.value)

  const eventId = () => eventContext.activeEventId

  // Abas visíveis conforme as permissões (consulta). Ações dentro de cada aba têm permissão própria.
  const sectionTabs = computed(() => [
    ...(canView.value ? [{ id: 'integracao', label: 'Integração' }, { id: 'entradas', label: 'Entradas' }, { id: 'sincronizacao', label: 'Sincronização' }] : []),
    ...(canRegistrations.value ? [{ id: 'inscricoes', label: 'Inscrições' }] : []),
    ...(canAttendance.value ? [{ id: 'presenca', label: 'Presença' }] : []),
  ])
  const tab = computed(() => {
    const ids = sectionTabs.value.map((item) => item.id)
    return ids.includes(props.params?.aba) ? props.params.aba : ids[0] || ''
  })
  function setTab(id) {
    go(`presenca?aba=${id}`)
  }

  // ---------- Integração (status) ----------
  const status = useRequest(({ signal }) => (canView.value && eventId() ? integrationsApi.even3Status(eventId(), { signal }).then(unwrap) : null))
  const integration = computed(() => status.data.value)
  const configured = computed(() => Boolean(integration.value?.configured))

  const modal = ref(null)
  const configForm = ref({ external_event_id: '', status: 'ACTIVE' })
  const configWarnings = ref([])

  function openConfigure() {
    configForm.value = { external_event_id: integration.value?.external_event_id || '', status: integration.value?.status || 'ACTIVE' }
    configure.clear()
    modal.value = 'configure'
  }

  const configure = useAction(async () => {
    const payload = await integrationsApi.configureEven3(eventId(), {
      external_event_id: String(configForm.value.external_event_id || '').trim(),
      status: configForm.value.status,
    })
    configWarnings.value = payload?.meta?.warnings || []
    modal.value = null
    await status.load()
    return payload
  }, { success: 'Integração Even3 salva.' })

  // Consulta a Even3 agora pelo servidor (somente leitura) e atualiza a data da última verificação.
  const checkConnection = useAction(async () => {
    const data = unwrap(await integrationsApi.even3Status(eventId(), { query: { check: 1 } }))
    status.data.value = data
    return data
  }, { success: (data) => (data?.connection_error ? `A Even3 respondeu com erro: ${data.connection_error}.` : 'Conexão com a Even3 verificada.') })

  // ---------- Entradas ----------
  const ticketFilters = ref({ active: '', mapped: '' })
  const tickets = useRequest(async ({ signal }) => {
    if (!canView.value || !configured.value) return { items: [], categories: [] }
    const query = Object.fromEntries(Object.entries(ticketFilters.value).filter(([, value]) => value !== ''))
    const payload = await integrationsApi.listTickets(eventId(), query, { signal })
    return { items: payload?.data || [], categories: payload?.meta?.categories || [] }
  }, { immediate: false, initial: { items: [], categories: [] } })
  watch(ticketFilters, () => tickets.load(), { deep: true })
  const categoryOptions = computed(() => {
    const codes = tickets.data.value?.categories?.length ? tickets.data.value.categories : options('ticketCategory').map(([code]) => code)
    return codes.map((code) => [code, label('ticketCategory', code)])
  })

  // Seletor de entradas para o filtro de inscrições (todas, inclusive inativas).
  const ticketChoices = useRequest(async ({ signal }) => {
    if (!canView.value || !configured.value) return []
    return (await integrationsApi.listTickets(eventId(), {}, { signal }))?.data || []
  }, { immediate: false, initial: [] })

  const selectedTicket = ref(null)
  const mappingForm = ref({ internal_category: '' })
  function openMapping(ticket) {
    selectedTicket.value = ticket
    mappingForm.value = { internal_category: ticket.internal_category || '' }
    mapping.clear()
    modal.value = 'mapping'
  }
  const mapping = useAction(async () => {
    const category = mappingForm.value.internal_category || null
    const updated = unwrap(await integrationsApi.mapTicket(selectedTicket.value.id, { internal_category: category }))
    modal.value = null
    await Promise.all([tickets.load(), status.load()])
    return updated
  }, { success: (updated) => (updated?.internal_category ? `Entrada mapeada como ${label('ticketCategory', updated.internal_category)}.` : 'Mapeamento removido.') })

  // ---------- Sincronização ----------
  const runs = usePaged((query, opts) => (canView.value && configured.value ? integrationsApi.listSyncRuns(eventId(), query, opts) : { data: [] }), {
    filters: { status: '' },
    perPage: 20,
    immediate: false,
  })
  const syncForm = ref({ scope: 'ALL' })
  function openSync() {
    syncForm.value = { scope: 'ALL' }
    sync.clear()
    modal.value = 'sync'
  }
  const sync = useAction(async () => {
    const run = unwrap(await integrationsApi.syncEven3(eventId(), { scope: syncForm.value.scope }))
    modal.value = null
    runDetail.data.value = run
    runDetailId.value = run?.id || null
    await Promise.all([runs.load(), status.load()])
    return run
  }, { success: (run) => `Sincronização ${label('syncStatus', run?.status).toLowerCase()}.` })

  const runDetailId = ref(null)
  const runDetail = useRequest(({ signal }) => (runDetailId.value ? integrationsApi.getSyncRun(runDetailId.value, { signal }).then(unwrap) : null), { immediate: false })
  function openRun(run) {
    runDetailId.value = run.id
    runDetail.data.value = run
    runDetail.load()
  }
  function closeRun() {
    runDetailId.value = null
  }
  const counterRows = computed(() => Object.entries(runDetail.data.value?.counters || {}).map(([key, value]) => ({ key, label: COUNTER_LABELS[key] || key, value })))
  const warningCodeRows = computed(() => Object.entries(runDetail.data.value?.warning_codes || {}).map(([code, count]) => ({ code, count })))
  function warningTotal(run) {
    return Object.values(run?.warning_codes || {}).reduce((sum, value) => sum + Number(value || 0), 0)
  }
  function duration(ms) {
    if (ms === null || ms === undefined) return '—'
    if (ms < 1000) return `${ms} ms`
    const seconds = Math.round(ms / 100) / 10
    return seconds < 60 ? `${formatNumber(seconds, 1)} s` : `${Math.floor(seconds / 60)} min ${Math.round(seconds % 60)} s`
  }

  // ---------- Inscrições ----------
  const registrations = usePaged((query, opts) => (canRegistrations.value ? integrationsApi.listRegistrations(eventId(), query, opts) : { data: [] }), {
    filters: { search: '', status: '', confirmed: '', internal_category: '', external_ticket_type_id: '', has_participant: '', identity_mismatch: '', unmapped: '' },
    perPage: 25,
    immediate: false,
  })
  const registrationId = ref(null)
  const registrationDetail = useRequest(({ signal }) => (registrationId.value ? integrationsApi.getRegistration(registrationId.value, { signal }).then(unwrap) : null), { immediate: false })
  function openRegistration(item) {
    registrationId.value = item.id
    registrationDetail.data.value = item
    registrationDetail.load()
  }
  function closeRegistration() {
    registrationId.value = null
  }
  const daysById = computed(() => Object.fromEntries(activeDays.value.map((day) => [day.id, day])))
  function attendanceDay(record) {
    const day = daysById.value[record.event_day_id]
    return day ? dayLabel(day) : record.event_day_id ? `Dia #${record.event_day_id}` : 'Fora dos dias do evento'
  }

  const materializeResult = ref(null)
  function openMaterialize() {
    materializeResult.value = null
    modal.value = 'materialize'
  }
  const materialize = useAction(async () => {
    const result = unwrap(await integrationsApi.materializeParticipants(eventId()))
    materializeResult.value = result
    await registrations.load()
    return result
  }, { success: (result) => `Materialização concluída: ${result?.created ?? 0} participante(s) novo(s).` })

  // ---------- Presença (somente leitura) ----------
  const attendance = usePaged((query, opts) => (canAttendance.value ? integrationsApi.listAttendance(eventId(), query, opts) : { data: [] }), {
    filters: { search: '', event_day_id: '', internal_category: '', status: '' },
    perPage: 25,
    immediate: false,
  })
  const dayTabs = computed(() => [{ id: '', label: 'Todos os dias' }, ...activeDays.value.map((day) => ({ id: String(day.id), label: day.label || `Dia ${day.day_number}` }))])

  // ---------- Carregamento por aba (o backend é lento: só busca o que a aba mostra) ----------
  // Entradas e histórico dependem da integração existir: recarregam se "configurada" mudar.
  const loadedTabs = new Map()
  function loadTab(id) {
    if (!id) return
    const needsStatus = ['entradas', 'sincronizacao'].includes(id)
    if (needsStatus && !status.loaded.value) return
    const key = needsStatus ? configured.value : true
    if (loadedTabs.get(id) === key) {
      if (id === 'inscricoes' && configured.value && !ticketChoices.loaded.value && !ticketChoices.loading.value) ticketChoices.load()
      return
    }
    loadedTabs.set(id, key)
    if (id === 'entradas') tickets.load()
    if (id === 'sincronizacao') runs.load()
    if (id === 'inscricoes') {
      registrations.load()
      if (configured.value) ticketChoices.load()
    }
    if (id === 'presenca') attendance.load()
  }
  watch([tab, () => status.loaded.value, configured], () => loadTab(tab.value), { immediate: true })

  function yesNo(value) {
    return value ? 'Sim' : 'Não'
  }

  return {
    BOOL_OPTIONS,
    canView,
    canManage,
    canSync,
    canRegistrations,
    canAttendance,
    canMaterialize,
    readOnly,
    sectionTabs,
    tab,
    setTab,
    status,
    integration,
    configured,
    modal,
    configForm,
    configWarnings,
    openConfigure,
    configure,
    checkConnection,
    ticketFilters,
    tickets,
    categoryOptions,
    ticketChoices,
    selectedTicket,
    mappingForm,
    openMapping,
    mapping,
    runs,
    syncForm,
    openSync,
    sync,
    runDetailId,
    runDetail,
    openRun,
    closeRun,
    counterRows,
    warningCodeRows,
    warningTotal,
    duration,
    registrations,
    registrationId,
    registrationDetail,
    openRegistration,
    closeRegistration,
    attendanceDay,
    materializeResult,
    openMaterialize,
    materialize,
    attendance,
    dayTabs,
    activeDays,
    yesNo,
    label,
    tone,
    options,
    formatDate,
    formatDateTime,
    formatNumber,
  }
}
