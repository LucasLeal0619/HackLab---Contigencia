// Revisão dos dados JÁ sincronizados da Even3, embutida na página de cada assunto (Complemento 11.2).
// Nada aqui chama a Even3 nem sincroniza; tudo vira decisão interna do Administrador.
//   Jurados (página Jurados)            → Cadastrar como Jurado (JurorService) · Ignorar · Restaurar
//   Organização (Usuários e Permissões) → Liberar acesso (perfil/setor, sem senha) · Revogar · Ignorar · Restaurar
//   Empresa/Convidado (Empresas)        → Vincular a empresa existente · Criar empresa (formulário real) · Ignorar
//   Evento (página Evento)              → aplicar nome/datas da Even3 campo a campo (valores do backend)
// Participantes não têm fila: são criados automaticamente ao sincronizar.
import { computed, ref, watch } from 'vue'
import { unwrap } from '@/js/api/client'
import { fetchAll } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext, replaceEvent } from '@/js/stores/event-context'
import { even3Changed, even3Context } from '@/js/stores/even3-context'
import { useCompanyForm } from '@/js/pages/company-form'
import * as integrationsApi from '@/js/services/integrations'
import { listCompanies } from '@/js/services/companies'
import { listSectors } from '@/js/services/sectors'
import { formatDate, formatDateTime } from '@/js/utils/format'

// fila (rota) → chave do resumo, fila da API, permissão do domínio.
export const SECTIONS = [
  { id: 'jurados', label: 'Jurados', title: 'Inscritos como Jurado na Even3', key: 'jurors', api: 'jurors', permission: 'jurors.manage' },
  { id: 'organizacao', label: 'Organização', title: 'Inscritos como Organização sem acesso', key: 'organization', api: 'organization', permission: 'users.manage' },
  { id: 'empresas', label: 'Empresa / Convidado', title: 'Inscritos como Empresa / Convidado', key: 'company_guests', api: 'company-guests', permission: 'companies.manage' },
  { id: 'evento', label: 'Evento', title: 'Dados do evento: HackLab × Even3', key: 'event_differences', permission: 'events.manage' },
]

export function sectionFor(fila) {
  return SECTIONS.find((item) => item.id === fila) || null
}

// A fila aparece na página só para quem pode agir e quando há algo para revisar (pendente ou ignorado).
export function queueVisible(fila) {
  const section = sectionFor(fila)
  if (!section || !hasPermission('integrations.manage') || !hasPermission(section.permission)) return false
  return Number(even3Context.pending?.[section.key] || 0) > 0 || Number(even3Context.ignored?.[section.key] || 0) > 0
}

export const GRANT_ROLES = [['MANAGER', 'Gestor'], ['EDITOR', 'Editor'], ['CONSULTANT', 'Consultor']]
const SECTOR_ROLES = ['MANAGER', 'EDITOR']
const FIELD_LABELS = { name: 'Nome', start_date: 'Data inicial', end_date: 'Data final' }

export function useReconciliation(props) {
  const eventId = () => eventContext.activeEventId
  const canManage = computed(() => hasPermission('integrations.manage'))
  const section = computed(() => sectionFor(props.fila) || SECTIONS[0])
  const canAct = computed(() => canManage.value && (!section.value.permission || hasPermission(section.value.permission)))
  const count = (key) => Number(even3Context.pending?.[key] || 0)

  // ---------- Filas com lista (Jurados, Organização, Empresa/Convidado) ----------
  const candidates = usePaged((query, opts) => (section.value.api && canAct.value && eventId()
    ? integrationsApi.listCandidates(eventId(), section.value.api, query, opts)
    : { data: [], meta: { current_page: 1, last_page: 1, total: 0 } }), {
    filters: { view: 'pending', search: '' },
    perPage: 20,
    immediate: false,
  })

  watch([() => section.value.id, () => eventContext.activeEventId], () => {
    candidates.filters.view = 'pending'
    candidates.filters.search = ''
    if (section.value.api) candidates.load()
    if (section.value.id === 'evento') metadata.load()
  })

  // Sincronização ou reconciliação em outro lugar: recarrega o que esta seção mostra.
  watch(() => even3Context.revision, () => {
    if (section.value.api) candidates.load()
    if (section.value.id === 'evento') metadata.load()
  })

  async function afterChange() {
    await Promise.all([candidates.load(), even3Changed()])
  }

  const modal = ref(null)
  const selected = ref(null)
  const note = ref('')

  function stateLabel(item) {
    if (item.state === 'IGNORED') return 'Ignorado'
    if (section.value.id === 'jurados' && item.internal?.juror) return `Já é jurado (${label('jurorStatus', item.internal.juror.status)})`
    if (section.value.id === 'organizacao' && item.internal?.user) return `Já possui acesso: ${label('role', item.internal.user.role)}`
    if (section.value.id === 'organizacao' && item.internal?.grant) {
      const grant = item.internal.grant
      return `Acesso liberado: ${label('role', grant.role)}${grant.sector ? ` · ${grant.sector.name}` : ''} (aguardando primeiro acesso)`
    }
    if (section.value.id === 'empresas') {
      const active = (item.internal?.representatives || []).filter((r) => r.active)
      if (active.length) return `Representa: ${active.map((r) => r.company.name).join(', ')}`
    }
    return 'Pendente'
  }

  // Ignorar / restaurar
  function openIgnore(item) {
    selected.value = item
    note.value = ''
    ignore.clear()
    modal.value = 'ignore'
  }
  const ignore = useAction(async () => {
    await integrationsApi.ignoreCandidate(eventId(), section.value.api, selected.value.person.id, { note: note.value.trim() || null })
    modal.value = null
    await afterChange()
  }, { success: 'Candidato ignorado. Ele aparece em "Ignorados" e pode ser restaurado.' })

  const restore = useAction(async (item) => {
    await integrationsApi.restoreCandidate(eventId(), section.value.api, item.person.id)
    await afterChange()
  }, { success: 'Candidato restaurado para revisão.' })

  // Jurado
  const companies = useRequest(async ({ signal }) => (eventId() ? fetchAll((query) => listCompanies(eventId(), query, { signal })) : []), { immediate: false, initial: [] })
  const jurorForm = ref({ company_id: '', notes: '' })
  function openJuror(item) {
    selected.value = item
    jurorForm.value = { company_id: '', notes: '' }
    confirmJuror.clear()
    if (!companies.loaded.value) companies.load()
    modal.value = 'juror'
  }
  const confirmJuror = useAction(async () => {
    await integrationsApi.confirmJurorCandidate(eventId(), selected.value.person.id, {
      company_id: jurorForm.value.company_id ? Number(jurorForm.value.company_id) : null,
      notes: jurorForm.value.notes.trim() || null,
    })
    modal.value = null
    await afterChange()
  }, { success: 'Jurado cadastrado. A pessoa ativa a conta pelo primeiro acesso.' })

  // Organização → liberar acesso
  const sectors = useRequest(async ({ signal }) => (eventId() ? (await listSectors(eventId(), {}, { signal })).filter((s) => s.active !== false) : []), { immediate: false, initial: [] })
  const grantForm = ref({ role: 'MANAGER', sector_id: '' })
  const grantNeedsSector = computed(() => SECTOR_ROLES.includes(grantForm.value.role))
  function openGrant(item) {
    selected.value = item
    grantForm.value = { role: 'MANAGER', sector_id: '' }
    grant.clear()
    if (!sectors.loaded.value) sectors.load()
    modal.value = 'grant'
  }
  const grant = useAction(async () => {
    await integrationsApi.grantOrganizationAccess(eventId(), selected.value.person.id, {
      role: grantForm.value.role,
      sector_id: grantNeedsSector.value && grantForm.value.sector_id ? Number(grantForm.value.sector_id) : null,
    })
    modal.value = null
    await afterChange()
  }, { success: 'Acesso liberado. A pessoa cria a própria senha pelo primeiro acesso.' })

  const revokeGrant = useAction(async (item) => {
    await integrationsApi.revokeAccessGrant(item.internal.grant.id)
    await afterChange()
  }, { success: 'Liberação revogada.' })

  // Empresa / Convidado → vincular
  const linkForm = ref({ company_id: '', title: '', is_primary: false })
  function openLink(item, companyId = '') {
    selected.value = item
    linkForm.value = { company_id: companyId ? String(companyId) : '', title: '', is_primary: false }
    link.clear()
    if (!companies.loaded.value || companyId) companies.load()
    modal.value = 'link'
  }
  const link = useAction(async () => {
    await integrationsApi.linkCompanyGuest(eventId(), selected.value.person.id, {
      company_id: Number(linkForm.value.company_id),
      title: linkForm.value.title.trim() || null,
      is_primary: Boolean(linkForm.value.is_primary),
    })
    modal.value = null
    await afterChange()
  }, { success: 'Representante vinculado à empresa.' })

  // "Criar empresa": formulário real de empresa; ao salvar, volta para o vínculo com a nova empresa escolhida.
  const pendingLink = ref(null)
  const companyForm = useCompanyForm({
    onSaved: async (company) => {
      if (pendingLink.value) openLink(pendingLink.value, company.id)
      pendingLink.value = null
    },
  })
  function createCompanyFor(item) {
    pendingLink.value = item
    modal.value = null
    companyForm.start()
  }

  // Evento → divergências
  const metadata = useRequest(({ signal }) => (eventId() ? integrationsApi.eventMetadata(eventId(), { signal }).then(unwrap) : null), { immediate: false })
  const chosenFields = ref([])
  const metadataRows = computed(() => (metadata.data.value?.fields || []).map((row) => ({ ...row, label: FIELD_LABELS[row.field] || row.field })))
  watch(() => metadata.data.value, () => {
    chosenFields.value = metadataRows.value.filter((row) => row.different).map((row) => row.field).slice(0, 1)
  })
  const applyMetadata = useAction(async () => {
    const event = unwrap(await integrationsApi.applyEventMetadata(eventId(), chosenFields.value))
    if (event) replaceEvent(event)
    await Promise.all([metadata.load(), even3Changed()])
    return event
  }, { success: 'Dados da Even3 aplicados ao evento.' })

  // Carga inicial da seção pedida.
  if (section.value.api) candidates.load()
  if (section.value.id === 'evento') metadata.load()

  return {
    SECTIONS,
    GRANT_ROLES,
    section,
    count,
    canManage,
    canAct,
    candidates,
    stateLabel,
    modal,
    selected,
    note,
    openIgnore,
    ignore,
    restore,
    companies,
    jurorForm,
    openJuror,
    confirmJuror,
    sectors,
    grantForm,
    grantNeedsSector,
    openGrant,
    grant,
    revokeGrant,
    linkForm,
    openLink,
    link,
    companyForm,
    createCompanyFor,
    metadata,
    metadataRows,
    chosenFields,
    applyMetadata,
    even3: even3Context,
    go,
    label,
    formatDate,
    formatDateTime,
  }
}
