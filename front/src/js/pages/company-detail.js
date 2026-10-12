// Lógica de CompanyDetail.vue: GET /companies/{id} (com representantes e resumo dos desafios),
// status da empresa (/status), representantes (POST /companies/{id}/representatives, PATCH /company-representatives/{r} e /status).
// Representante é uma Person ligada à empresa: não cria usuário nem jurado.
import { computed, ref } from 'vue'
import { useAction, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { useChallengeForm } from '@/js/pages/challenge-form'
import { useCompanyForm } from '@/js/pages/company-form'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import * as companiesApi from '@/js/services/companies'
import { listPeople } from '@/js/services/users'

export const DETAIL_TABS = [
  { id: 'geral', label: 'Visão geral' },
  { id: 'reps', label: 'Representantes' },
  { id: 'desafios', label: 'Desafios' },
]

const STATUS_HINT = {
  DRAFT: 'Cadastro em andamento.',
  CONFIRMED: 'Participação confirmada no evento.',
  INACTIVE: 'A empresa deixa de receber novos desafios e representantes. Desafios e representantes existentes são preservados.',
}

function emptyRep() {
  return { personMode: 'new', person: null, full_name: '', email: '', phone: '', document: '', title: '', notes: '', is_primary: false }
}

export function useCompanyDetail(props) {
  const id = computed(() => Number(props.params?.id) || null)
  const tab = ref(DETAIL_TABS.some((item) => item.id === props.params?.aba) ? props.params.aba : 'geral')
  const canManage = computed(() => hasPermission('companies.manage'))
  const canManageChallenges = computed(() => hasPermission('challenges.manage'))
  const canSearchPeople = computed(() => hasPermission('people.view'))

  const detail = useRequest(() => (id.value ? companiesApi.getCompany(id.value) : null))
  const company = computed(() => detail.data.value)
  const reps = computed(() => company.value?.representatives || [])
  const challenges = computed(() => company.value?.challenges || [])
  const primary = computed(() => reps.value.find((rep) => rep.active && rep.is_primary) || null)
  const inactive = computed(() => company.value?.status === 'INACTIVE')

  const reload = () => detail.load()

  const companyForm = useCompanyForm({ onSaved: reload })
  const challengeForm = useChallengeForm({ onSaved: reload })

  function addChallenge() {
    challengeForm.start(null, { company_id: company.value.id })
  }

  // --- Status da empresa ---
  const modal = ref(null)
  const nextStatus = ref('')
  function openStatus() {
    nextStatus.value = company.value.status === 'DRAFT' ? 'CONFIRMED' : company.value.status === 'INACTIVE' ? 'CONFIRMED' : 'INACTIVE'
    changeStatus.clear()
    modal.value = 'status'
  }
  const changeStatus = useAction(async () => {
    const updated = await companiesApi.changeCompanyStatus(company.value.id, nextStatus.value)
    modal.value = null
    await reload()
    return updated
  }, { success: (updated) => `Status da empresa: ${label('companyStatus', updated?.status)}.` })

  // --- Representantes ---
  const rep = ref(emptyRep())
  const selectedRep = ref(null)

  const personSearch = ref('')
  const people = useRequest(async () => {
    if (!personSearch.value.trim()) return []
    const payload = await listPeople({ search: personSearch.value.trim(), per_page: 10 })
    const linked = new Set(reps.value.map((item) => item.person?.id))
    return (payload?.data || []).filter((item) => !linked.has(item.id))
  }, { immediate: false, initial: [] })
  let searchTimer = 0
  function searchPeople(value) {
    personSearch.value = value
    window.clearTimeout(searchTimer)
    searchTimer = window.setTimeout(() => people.load(), 300)
  }

  function openAddRep() {
    rep.value = { ...emptyRep(), is_primary: !primary.value }
    personSearch.value = ''
    people.data.value = []
    addRep.clear()
    modal.value = 'rep-add'
  }

  const addRep = useAction(async () => {
    const value = rep.value
    const body = { title: value.title.trim() || null, notes: value.notes.trim() || null, is_primary: Boolean(value.is_primary) }
    if (value.personMode === 'existing') body.person_id = value.person?.id
    else {
      body.person = {
        full_name: value.full_name.trim(),
        email: value.email.trim() || null,
        phone: value.phone.trim() || null,
        document: value.document.trim() || null,
      }
    }
    const created = await companiesApi.createRepresentative(company.value.id, body)
    modal.value = null
    await reload()
    return created
  }, { success: 'Representante adicionado.' })

  function openEditRep(item) {
    selectedRep.value = item
    rep.value = { ...emptyRep(), title: item.title || '', notes: item.notes || '', is_primary: Boolean(item.is_primary) }
    editRep.clear()
    modal.value = 'rep-edit'
  }

  const editRep = useAction(async () => {
    const item = selectedRep.value
    const body = {}
    const title = rep.value.title.trim()
    const notes = rep.value.notes.trim()
    if (title !== (item.title || '')) body.title = title || null
    if (notes !== (item.notes || '')) body.notes = notes || null
    if (Boolean(rep.value.is_primary) !== Boolean(item.is_primary)) body.is_primary = Boolean(rep.value.is_primary)
    if (!Object.keys(body).length) {
      modal.value = null
      return 0
    }
    await companiesApi.updateRepresentative(item.id, body)
    modal.value = null
    await reload()
    return 1
  }, { success: (count) => (count ? 'Representante atualizado.' : 'Nenhuma alteração para salvar.') })

  // Principal: troca explícita. O backend recusa um segundo principal ativo (desmarque o atual antes).
  const togglePrimary = useAction(async (item) => {
    const updated = await companiesApi.updateRepresentative(item.id, { is_primary: !item.is_primary })
    await reload()
    return updated
  }, { success: (updated) => (updated?.is_primary ? 'Representante definido como principal.' : 'Marca de principal removida.') })

  function askDeactivate(item) {
    selectedRep.value = item
    repStatus.clear()
    modal.value = 'rep-off'
  }

  const repStatus = useAction(async (item, active) => {
    const updated = await companiesApi.changeRepresentativeStatus(item.id, active)
    modal.value = null
    await reload()
    return updated
  }, { success: (updated) => (updated?.active ? 'Representante reativado.' : 'Representante desativado.') })

  return {
    id,
    tab,
    detail,
    company,
    reps,
    challenges,
    primary,
    inactive,
    canManage,
    canManageChallenges,
    canSearchPeople,
    companyForm,
    challengeForm,
    addChallenge,
    modal,
    nextStatus,
    openStatus,
    changeStatus,
    STATUS_HINT,
    rep,
    selectedRep,
    personSearch,
    people,
    searchPeople,
    openAddRep,
    addRep,
    openEditRep,
    editRep,
    togglePrimary,
    askDeactivate,
    repStatus,
    go,
    label,
    tone,
    options,
  }
}
