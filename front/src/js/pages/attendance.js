// Lógica da página Attendance.vue ("Presença"): a tela da integração Even3 (simplificação pré-deploy).
// Indicadores, "Precisa de atenção" (só exceções), lista de inscritos com presença e, discreta, a Configuração
// Even3 (vínculo, entradas, histórico, divergência do evento). Sincronizar: mesmo disparo central, sem confirmação.
// O HackLab não credencia ninguém: check-ins vêm da Even3. Sem check-in = "Sem registro" (nunca "Ausente").
import { computed, ref, watch } from 'vue'
import { unwrap } from '@/js/api/client'
import { fetchAll } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { activeDays, eventContext } from '@/js/stores/event-context'
import { canSyncEven3, even3Context, loadEven3, syncEven3 } from '@/js/stores/even3-context'
import { listCompanies } from '@/js/services/companies'
import * as integrationsApi from '@/js/services/integrations'
import { dayLabel, formatDate, formatDateTime, formatNumber } from '@/js/utils/format'
import { relativeTime } from '@/js/components/even3-center'

export const ATTENTION = {
  PARTICIPANT_NOT_FOUND: { title: 'Participante não localizado', hint: 'Inscrito como participante na Even3, mas não há participante com esta pessoa no HackLab (cadastro faltando, e-mail/documento diferente ou inscrição errada).' },
  IDENTITY_CONFLICT: { title: 'Identidade divergente', hint: 'O e-mail ou documento da Even3 é diferente do cadastro no HackLab. Nada foi aplicado.' },
  COMPANY_UNLINKED: { title: 'Representante sem empresa', hint: 'Escolha a empresa desta pessoa.' },
  UNMAPPED_TICKET: { title: 'Entrada sem mapeamento', hint: 'Diga o que esta entrada da Even3 significa na Configuração.' },
}

export function useAttendance(props) {
  const eventId = () => eventContext.activeEventId
  const canView = computed(() => hasPermission('integrations.view'))
  const canManage = computed(() => hasPermission('integrations.manage'))
  const canSync = computed(() => canSyncEven3())
  const canRegistrations = computed(() => hasPermission('event_registrations.view'))
  const canLink = computed(() => canManage.value && hasPermission('companies.manage'))

  // ---------- Estado e sincronização (store compartilhado com o cabeçalho) ----------
  if (canView.value && !even3Context.loaded) loadEven3()
  const busy = computed(() => even3Context.syncing || even3Context.running)
  const lastSync = computed(() => (even3Context.lastSyncAt ? `Atualizado ${relativeTime(even3Context.lastSyncAt)}` : 'Nunca sincronizado'))
  const sync = useAction(async () => syncEven3('ALL'), { success: (run) => `Sincronização ${label('syncStatus', run?.status).toLowerCase()}.` })

  // ---------- Precisa de atenção ----------
  const attention = useRequest(async ({ signal }) => {
    if (!canView.value || !canRegistrations.value || !eventId()) return []
    return unwrap(await integrationsApi.listAttention(eventId(), { signal })) || []
  }, { initial: [] })
  const companies = useRequest(async ({ signal }) => (eventId() ? fetchAll((query) => listCompanies(eventId(), query, { signal })) : []), { immediate: false, initial: [] })
  watch(() => attention.data.value, (items) => {
    if (canLink.value && items.some((item) => item.type === 'COMPANY_UNLINKED') && !companies.loaded.value) companies.load()
  })
  const chosenCompany = ref({})
  const link = useAction(async (item) => {
    await integrationsApi.linkCompanyGuest(eventId(), item.person.id, { company_id: Number(chosenCompany.value[item.person.id]) })
    await Promise.all([attention.load(), loadEven3()])
  }, { success: 'Representante vinculado à empresa.' })

  // ---------- Inscritos com presença ----------
  const people = usePaged((query, opts) => (canRegistrations.value && eventId() ? integrationsApi.listRegistrations(eventId(), query, opts) : { data: [] }), {
    filters: { search: '', presence: '', confirmed: 'true' },
    perPage: 25,
  })

  const registrationId = ref(null)
  const registrationDetail = useRequest(({ signal }) => (registrationId.value ? integrationsApi.getRegistration(registrationId.value, { signal }).then(unwrap) : null), { immediate: false })
  function openRegistration(id) {
    registrationId.value = id
    registrationDetail.data.value = null
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

  // ---------- Configuração Even3 (painel discreto) ----------
  const settingsOpen = ref(Boolean(props.params?.config))
  const status = useRequest(({ signal }) => (canView.value && eventId() ? integrationsApi.even3Status(eventId(), { signal }).then(unwrap) : null), { immediate: false })
  const tickets = useRequest(async ({ signal }) => (canView.value && eventId() && even3Context.configured ? (await integrationsApi.listTickets(eventId(), {}, { signal }))?.data || [] : []), { immediate: false, initial: [] })
  const runs = useRequest(async ({ signal }) => (canView.value && eventId() && even3Context.configured ? unwrap(await integrationsApi.listSyncRuns(eventId(), { per_page: 10 }, { signal })) || [] : []), { immediate: false, initial: [] })
  const metadata = useRequest(({ signal }) => (canView.value && eventId() ? integrationsApi.eventMetadata(eventId(), { signal }).then(unwrap) : null), { immediate: false })
  const divergences = computed(() => (metadata.data.value?.fields || []).filter((row) => row.different))

  function loadSettings() {
    status.load()
    metadata.load()
    tickets.load()
    runs.load()
  }
  function openSettings() {
    settingsOpen.value = true
    loadSettings()
  }
  function closeSettings() {
    settingsOpen.value = false
    if (props.params?.config) go('presenca')
  }
  if (settingsOpen.value) loadSettings()

  const modal = ref(null)
  const configForm = ref({ external_event_id: '', status: 'ACTIVE' })
  const configWarnings = ref([])
  function openConfigure() {
    configForm.value = { external_event_id: status.data.value?.external_event_id || '', status: status.data.value?.status || 'ACTIVE' }
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
    await loadEven3()
    loadSettings()
    return payload
  }, { success: 'Integração Even3 salva.' })

  const checkConnection = useAction(async () => {
    const data = unwrap(await integrationsApi.even3Status(eventId(), { query: { check: 1 } }))
    status.data.value = data
    return data
  }, { success: (data) => (data?.connection_error ? `A Even3 respondeu com erro: ${data.connection_error}.` : 'Conexão com a Even3 verificada.') })

  // Mapeamento inline: escolher a categoria já salva (auditado no backend).
  const categoryOptions = computed(() => options('ticketCategory'))
  const mapping = useAction(async (ticket, category) => {
    await integrationsApi.mapTicket(ticket.id, { internal_category: category || null })
    await Promise.all([tickets.load(), attention.load(), loadEven3()])
  }, { success: 'Entrada mapeada.' })

  // Depois de sincronizar (aqui ou no cabeçalho): recarrega o que a tela mostra.
  watch(() => even3Context.revision, () => {
    attention.load()
    people.load()
    if (settingsOpen.value) loadSettings()
  })

  return {
    ATTENTION,
    canView,
    canManage,
    canSync,
    canRegistrations,
    canLink,
    even3: even3Context,
    busy,
    lastSync,
    sync,
    attention,
    companies,
    chosenCompany,
    link,
    people,
    registrationId,
    registrationDetail,
    openRegistration,
    closeRegistration,
    attendanceDay,
    settingsOpen,
    openSettings,
    closeSettings,
    status,
    tickets,
    runs,
    metadata,
    divergences,
    modal,
    configForm,
    configWarnings,
    openConfigure,
    configure,
    checkConnection,
    categoryOptions,
    mapping,
    go,
    label,
    tone,
    options,
    formatDate,
    formatDateTime,
    formatNumber,
  }
}
