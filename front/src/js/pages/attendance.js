// Lógica da página Attendance.vue ("Inscrições e Presença"): integração Even3 pelo backend.
// O HackLab não credencia ninguém: inscrições, entradas e check-ins vêm da Even3 via sincronização/webhook.
// Sem scanner, câmera, QR, credencial local ou check-in manual. O front nunca vê token, segredo ou header do webhook.
//
// Complemento 11.2: uma seção por item do submenu (Integração, Entradas, Inscrições, Presença). Sincronizar e o
// histórico ficam no Centro Even3 do cabeçalho; os participantes são criados automaticamente ao sincronizar.
import { computed, ref, watch } from 'vue'
import { unwrap } from '@/js/api/client'
import { usePaged, useAction, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { hasPermission } from '@/js/stores/auth'
import { activeDays, eventContext } from '@/js/stores/event-context'
import { even3Changed, even3Context } from '@/js/stores/even3-context'
import * as integrationsApi from '@/js/services/integrations'
import { dayLabel, formatDate, formatDateTime, formatNumber } from '@/js/utils/format'

const BOOL_OPTIONS = [['true', 'Sim'], ['false', 'Não']]

export const SECTIONS = {
  integracao: { title: 'Integração Even3', subtitle: 'Vínculo deste evento com a Even3. Sincronize pelo botão "Sincronizar Even3" no topo da tela.', permission: 'integrations.view' },
  entradas: { title: 'Entradas', subtitle: 'Entradas (categorias) do evento na Even3 e o mapeamento para as categorias do HackLab.', permission: 'integrations.view' },
  inscricoes: { title: 'Inscrições', subtitle: 'Inscrições vindas da Even3. Os participantes confirmados entram no HackLab ao sincronizar.', permission: 'event_registrations.view' },
  presenca: { title: 'Presença', subtitle: 'Check-ins feitos na Even3. O credenciamento é feito na Even3.', permission: 'attendance.view' },
}

export function useAttendance(props) {
  const canView = computed(() => hasPermission('integrations.view'))
  const canManage = computed(() => hasPermission('integrations.manage'))
  const canRegistrations = computed(() => hasPermission('event_registrations.view'))
  const canAttendance = computed(() => hasPermission('attendance.view'))
  const readOnly = computed(() => !canManage.value)

  const eventId = () => eventContext.activeEventId
  const tab = computed(() => (SECTIONS[props.section] ? props.section : 'presenca'))
  const section = computed(() => SECTIONS[tab.value])
  const allowed = computed(() => hasPermission(section.value.permission))

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
    await Promise.all([status.load(), even3Changed()])
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
  // Mapear muda quem é candidato nas filas: o Centro Even3 recalcula as pendências.
  const mapping = useAction(async () => {
    const category = mappingForm.value.internal_category || null
    const updated = unwrap(await integrationsApi.mapTicket(selectedTicket.value.id, { internal_category: category }))
    modal.value = null
    await Promise.all([tickets.load(), status.load(), even3Changed()])
    return updated
  }, { success: (updated) => (updated?.internal_category ? `Entrada mapeada como ${label('ticketCategory', updated.internal_category)}.` : 'Mapeamento removido.') })

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

  // ---------- Presença (somente leitura) ----------
  const attendance = usePaged((query, opts) => (canAttendance.value ? integrationsApi.listAttendance(eventId(), query, opts) : { data: [] }), {
    filters: { search: '', event_day_id: '', internal_category: '', status: '' },
    perPage: 25,
    immediate: false,
  })
  const dayTabs = computed(() => [{ id: '', label: 'Todos os dias' }, ...activeDays.value.map((day) => ({ id: String(day.id), label: day.label || `Dia ${day.day_number}` }))])

  // ---------- Carregamento da seção (o backend é lento: só busca o que a seção mostra) ----------
  // Listas que não dependem da integração carregam já; as que dependem esperam o status.
  function loadLists() {
    if (!allowed.value) return
    if (tab.value === 'inscricoes') registrations.load()
    if (tab.value === 'presenca') attendance.load()
  }
  function loadIntegrationParts() {
    if (!allowed.value || !configured.value) return
    if (tab.value === 'entradas') tickets.load()
    if (tab.value === 'inscricoes') ticketChoices.load()
  }
  loadLists()
  watch([() => status.loaded.value, configured], () => loadIntegrationParts(), { immediate: true })

  // Sincronização ou reconciliação feita pelo Centro Even3: recarrega o estado e a seção aberta.
  watch(() => even3Context.revision, () => {
    if (canView.value) status.load()
    loadLists()
    loadIntegrationParts()
  })

  function yesNo(value) {
    return value ? 'Sim' : 'Não'
  }

  return {
    BOOL_OPTIONS,
    canView,
    canManage,
    canRegistrations,
    canAttendance,
    readOnly,
    tab,
    section,
    allowed,
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
    registrations,
    registrationId,
    registrationDetail,
    openRegistration,
    closeRegistration,
    attendanceDay,
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
