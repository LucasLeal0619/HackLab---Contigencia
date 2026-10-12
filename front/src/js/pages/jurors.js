// Lógica da página Jurors.vue: jurados do evento, atribuições EXPLÍCITAS jurado ↔ equipe e critérios de avaliação.
// Jurado != Usuário: cadastrar jurado não cria conta nem perfil (isso é feito em Usuários).
// Nenhuma atribuição é inferida por empresa, desafio, representante ou categoria externa.
import { computed, ref, watch } from 'vue'
import { fetchAll, toPage } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { listCompanies } from '@/js/services/companies'
import * as jurorsApi from '@/js/services/jurors'
import { listTeams } from '@/js/services/teams'
import { listPeople } from '@/js/services/users'
import { formatDateTime, formatNumber } from '@/js/utils/format'

function emptyJurorForm() {
  return { personMode: 'new', person: null, full_name: '', email: '', phone: '', document: '', company_id: '', notes: '' }
}

function emptyCriterionForm() {
  return { name: '', description: '', min_score: '0', max_score: '10', weight: '1', sort_order: '' }
}

const num = (value) => (value === '' || value === null || value === undefined ? null : Number(String(value).replace(',', '.')))

export function useJurors() {
  const canView = computed(() => hasPermission('jurors.view'))
  const canManage = computed(() => hasPermission('jurors.manage'))
  const canViewCriteria = computed(() => hasPermission('evaluation_criteria.view'))
  const canManageCriteria = computed(() => hasPermission('evaluation_criteria.manage'))
  const canPeople = computed(() => hasPermission('people.view'))

  const tabs = computed(() => [
    ...(canView.value ? [{ id: 'jurados', label: 'Jurados' }] : []),
    ...(canViewCriteria.value ? [{ id: 'criterios', label: 'Critérios de avaliação' }] : []),
  ])
  const tab = ref(tabs.value[0]?.id || 'jurados')

  const eventId = () => eventContext.activeEventId

  // ---------- Jurados ----------
  const list = usePaged((query, opts) => jurorsApi.listJurors(eventId(), query, opts), {
    filters: { search: '', status: '', company_id: '' },
    perPage: 20,
    immediate: canView.value,
    watchSources: () => (canView.value ? eventId() : null),
  })

  // Empresas do evento (contexto do jurado; não define equipes). Carregadas sob demanda.
  const companies = useRequest(async ({ signal }) => {
    if (!eventId()) return []
    return fetchAll((query) => listCompanies(eventId(), query, { signal }))
  }, { immediate: false, initial: [] })
  const selectableCompanies = computed(() => (companies.data.value || []).filter((item) => item.status !== 'INACTIVE'))
  function ensureCompanies() {
    if (!companies.loaded.value && !companies.loading.value) companies.load()
  }
  watch(() => eventId(), () => { companies.loaded.value = false; companies.data.value = [] })

  const modal = ref(null)
  const selected = ref(null)
  const form = ref(emptyJurorForm())

  // Busca de Person existente (ex.: representante de empresa) para virar jurado.
  const personSearch = ref('')
  const people = useRequest(async ({ signal }) => {
    const term = personSearch.value.trim()
    if (!term) return []
    const payload = await listPeople({ search: term, per_page: 10 }, { signal })
    return toPage(payload).items
  }, { immediate: false, initial: [] })
  let searchTimer = 0
  function searchPeople(value) {
    personSearch.value = value
    window.clearTimeout(searchTimer)
    searchTimer = window.setTimeout(() => people.load(), 300)
  }

  function openCreate() {
    form.value = emptyJurorForm()
    personSearch.value = ''
    people.data.value = []
    create.clear()
    ensureCompanies()
    modal.value = 'create'
  }

  function openEdit(item) {
    selected.value = item
    form.value = { ...emptyJurorForm(), company_id: item.company?.id || '', notes: item.notes || '' }
    update.clear()
    ensureCompanies()
    modal.value = 'edit'
  }

  const create = useAction(async () => {
    const body = {
      company_id: form.value.company_id ? Number(form.value.company_id) : null,
      notes: form.value.notes.trim() || null,
    }
    if (form.value.personMode === 'existing') body.person_id = form.value.person?.id ?? null
    else {
      body.person = {
        full_name: form.value.full_name.trim(),
        email: form.value.email.trim() || null,
        phone: form.value.phone.trim() || null,
        document: form.value.document.trim() || null,
      }
    }
    const created = await jurorsApi.createJuror(eventId(), body)
    modal.value = null
    await list.load()
    return created
  }, { success: 'Jurado cadastrado. Atribua as equipes que ele vai avaliar.' })

  const update = useAction(async () => {
    const item = selected.value
    const body = {}
    const companyId = form.value.company_id ? Number(form.value.company_id) : null
    if (companyId !== (item.company?.id ?? null)) body.company_id = companyId
    const notes = form.value.notes.trim() || null
    if (notes !== (item.notes || null)) body.notes = notes
    if (!Object.keys(body).length) {
      modal.value = null
      return 0
    }
    const updated = await jurorsApi.updateJuror(item.id, body)
    modal.value = null
    await list.load()
    return updated
  }, { success: (result) => (result ? 'Jurado atualizado.' : 'Nenhuma alteração para salvar.') })

  const changeStatus = useAction(async (item, status) => {
    const updated = await jurorsApi.changeJurorStatus(item.id, status)
    if (detail.value?.id === item.id) detail.value = { ...detail.value, ...updated }
    modal.value = null
    await list.load()
    return updated
  }, { success: (_, __, status) => (status === 'ACTIVE' ? 'Jurado ativado.' : 'Jurado inativado. As atribuições continuam ativas até serem revogadas.') })

  function askStatus(item) {
    selected.value = item
    if (item.status === 'ACTIVE') modal.value = 'off'
    else changeStatus.run(item, 'ACTIVE')
  }

  // Detalhe (drawer): dados da pessoa, conta de acesso (somente leitura) e histórico de atribuições.
  const detail = ref(null)
  const detailAssignments = useRequest(async ({ signal }) => {
    if (!detail.value) return []
    return toPage(await jurorsApi.listAssignments(detail.value.id, { signal })).items
  }, { immediate: false, initial: [] })
  function openDetail(item) {
    detail.value = item
    detailAssignments.data.value = []
    detailAssignments.load()
  }

  // ---------- Atribuições jurado ↔ equipe (PUT envia a lista final) ----------
  const assignJuror = ref(null)
  const assignSelection = ref([])
  const assignCurrent = useRequest(async ({ signal }) => {
    if (!assignJuror.value) return []
    return toPage(await jurorsApi.listAssignments(assignJuror.value.id, { signal })).items
  }, { immediate: false, initial: [] })
  const teams = useRequest(async ({ signal }) => {
    if (!eventId()) return []
    return fetchAll((query) => listTeams(eventId(), query, { signal }))
  }, { immediate: false, initial: [] })
  watch(() => eventId(), () => { teams.loaded.value = false; teams.data.value = [] })

  const activeAssignedIds = computed(() => (assignCurrent.data.value || []).filter((item) => item.status === 'ACTIVE').map((item) => item.team?.id))
  // Equipes ativas + as já atribuídas (mesmo se a equipe foi inativada depois), para poder revogar.
  const assignableTeams = computed(() => (teams.data.value || []).filter((team) => team.status === 'ACTIVE' || activeAssignedIds.value.includes(team.id)))
  const revokedHistory = computed(() => (assignCurrent.data.value || []).filter((item) => item.status === 'REVOKED'))
  const assignChanges = computed(() => {
    const before = new Set(activeAssignedIds.value)
    const after = new Set(assignSelection.value)
    return {
      added: [...after].filter((id) => !before.has(id)),
      removed: [...before].filter((id) => !after.has(id)),
    }
  })

  async function openAssignments(item) {
    assignJuror.value = item
    assignSelection.value = []
    syncAssignments.clear()
    modal.value = 'assign'
    if (!teams.loaded.value) teams.load()
    await assignCurrent.load()
    assignSelection.value = [...activeAssignedIds.value]
  }

  function toggleTeam(teamId) {
    const list_ = assignSelection.value
    assignSelection.value = list_.includes(teamId) ? list_.filter((id) => id !== teamId) : [...list_, teamId]
  }

  const syncAssignments = useAction(async () => {
    const { added, removed } = assignChanges.value
    if (!added.length && !removed.length) {
      modal.value = null
      return 0
    }
    await jurorsApi.syncAssignments(assignJuror.value.id, { team_ids: assignSelection.value })
    modal.value = null
    await list.load()
    if (detail.value?.id === assignJuror.value.id) detailAssignments.load()
    return added.length + removed.length
  }, { success: (count) => (count ? 'Atribuições salvas.' : 'Nenhuma alteração nas atribuições.') })

  function askSaveAssignments() {
    // Revogar atribuição é ação importante (a avaliação daquela equipe sai do conjunto atual): confirma antes.
    if (assignChanges.value.removed.length) modal.value = 'assign-confirm'
    else syncAssignments.run()
  }

  function teamName(id) {
    return (teams.data.value || []).find((team) => team.id === id)?.name
      || (assignCurrent.data.value || []).find((item) => item.team?.id === id)?.team?.name
      || `Equipe ${id}`
  }

  // ---------- Critérios ----------
  const criteria = useRequest(async ({ signal }) => {
    if (!eventId() || !canViewCriteria.value) return { items: [], locked: false }
    const page = toPage(await jurorsApi.listCriteria(eventId(), {}, { signal }))
    return { items: page.items, locked: Boolean(page.meta?.locked) }
  }, { initial: { items: [], locked: false }, immediate: false })
  // Critérios só carregam quando a aba é aberta (evita requisições paralelas na abertura).
  watch([tab, () => eventId()], ([current]) => {
    if (current === 'criterios') criteria.load()
    else criteria.loaded.value = false
  }, { immediate: true })

  const locked = computed(() => criteria.data.value?.locked === true)
  const criteriaItems = computed(() => criteria.data.value?.items || [])
  const totalWeight = computed(() => criteriaItems.value.filter((item) => item.active).reduce((sum, item) => sum + Number(item.weight || 0), 0))
  const criterionForm = ref(emptyCriterionForm())
  const editingCriterion = ref(null)

  function openCriterion(item = null) {
    editingCriterion.value = item
    criterionForm.value = item
      ? { name: item.name, description: item.description || '', min_score: String(item.min_score), max_score: String(item.max_score), weight: String(item.weight), sort_order: String(item.sort_order ?? '') }
      : emptyCriterionForm()
    saveCriterion.clear()
    modal.value = 'criterion'
  }

  const saveCriterion = useAction(async () => {
    const f = criterionForm.value
    const item = editingCriterion.value
    if (!item) {
      const body = { name: f.name.trim(), description: f.description.trim() || null, min_score: num(f.min_score), max_score: num(f.max_score), weight: num(f.weight) }
      if (f.sort_order !== '') body.sort_order = Number(f.sort_order)
      const created = await jurorsApi.createCriterion(eventId(), body)
      modal.value = null
      await criteria.load()
      return created
    }
    // PATCH só com o que mudou. Travado: só nome e descrição.
    const body = {}
    if (f.name.trim() !== item.name) body.name = f.name.trim()
    if ((f.description.trim() || null) !== (item.description || null)) body.description = f.description.trim() || null
    if (!locked.value) {
      if (num(f.min_score) !== Number(item.min_score)) body.min_score = num(f.min_score)
      if (num(f.max_score) !== Number(item.max_score)) body.max_score = num(f.max_score)
      if (num(f.weight) !== Number(item.weight)) body.weight = num(f.weight)
      if (f.sort_order !== '' && Number(f.sort_order) !== Number(item.sort_order)) body.sort_order = Number(f.sort_order)
    }
    if (!Object.keys(body).length) {
      modal.value = null
      return 0
    }
    const updated = await jurorsApi.updateCriterion(item.id, body)
    modal.value = null
    await criteria.load()
    return updated
  }, { success: (result) => (result === 0 ? 'Nenhuma alteração para salvar.' : editingCriterion.value ? 'Critério atualizado.' : 'Critério criado.') })

  const criterionTarget = ref(null)
  const toggleCriterion = useAction(async (item, active) => {
    const updated = await jurorsApi.changeCriterionStatus(item.id, { active })
    modal.value = null
    await criteria.load()
    return updated
  }, { success: (_, __, active) => (active ? 'Critério ativado.' : 'Critério inativado.') })

  function askToggleCriterion(item) {
    criterionTarget.value = item
    if (item.active) modal.value = 'criterion-off'
    else toggleCriterion.run(item, true)
  }

  function scaleOf(item) {
    return `${formatNumber(item.min_score, Number.isInteger(item.min_score) ? 0 : 2)} a ${formatNumber(item.max_score, Number.isInteger(item.max_score) ? 0 : 2)}`
  }

  function weightOf(item) {
    return formatNumber(item.weight, Number.isInteger(item.weight) ? 0 : 2)
  }

  function accessLabel(account) {
    if (!account?.has_account) return 'Sem conta de acesso'
    if (account.access_ready) return 'Conta Jurado ativa'
    if (account.account_status !== 'ACTIVE') return 'Conta inativa'
    return `Conta com perfil ${label('role', account.account_role)}`
  }

  return {
    tabs, tab, canView, canManage, canViewCriteria, canManageCriteria, canPeople,
    list, companies, selectableCompanies, ensureCompanies,
    modal, selected, form, personSearch, people, searchPeople,
    openCreate, openEdit, create, update, changeStatus, askStatus,
    detail, detailAssignments, openDetail,
    assignJuror, assignSelection, assignCurrent, teams, assignableTeams, revokedHistory, assignChanges,
    openAssignments, toggleTeam, syncAssignments, askSaveAssignments, teamName,
    criteria, criteriaItems, locked, totalWeight, criterionForm, editingCriterion, openCriterion, saveCriterion,
    criterionTarget, toggleCriterion, askToggleCriterion, scaleOf, weightOf, accessLabel,
    label, tone, options, formatDateTime,
  }
}
