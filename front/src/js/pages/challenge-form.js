// Formulário de desafio (criar/editar), usado em Companies.vue (modo desafios), CompanyDetail.vue e ChallengeDetail.vue.
// Status só na criação (Rascunho → Aprovado); depois, pela rota /status. Equipe só pela rota /team (distribuição).
import { computed, ref } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { useAction, useRequest } from '@/js/composables/async'
import { eventContext } from '@/js/stores/event-context'
import { createChallenge, updateChallenge } from '@/js/services/challenges'
import { listCompanies } from '@/js/services/companies'

const FIELDS = ['title', 'problem', 'objective', 'requirements', 'restrictions', 'expected_outcome', 'notes']
export const CREATABLE_STATUSES = ['DRAFT', 'RECEIVED', 'UNDER_REVIEW', 'APPROVED']

function emptyForm() {
  return { company_id: '', title: '', problem: '', objective: '', requirements: '', restrictions: '', expected_outcome: '', notes: '', status: 'DRAFT' }
}

export function useChallengeForm({ onSaved } = {}) {
  const open = ref(false)
  const editing = ref(null)
  const form = ref(emptyForm())

  // Empresas do evento para o seletor (carregadas só quando o modal abre).
  const companies = useRequest(
    () => (eventContext.activeEventId ? fetchAll((query) => listCompanies(eventContext.activeEventId, query)) : []),
    { immediate: false, initial: [] },
  )
  // Nova empresa precisa estar ativa; a empresa atual do desafio (mesmo inativa) pode ser mantida.
  const companyOptions = computed(() => (companies.data.value || [])
    .filter((item) => item.status !== 'INACTIVE' || item.id === editing.value?.company?.id))

  function start(challenge = null, preset = {}) {
    editing.value = challenge
    form.value = challenge
      ? { ...emptyForm(), ...Object.fromEntries(FIELDS.map((key) => [key, challenge[key] ?? ''])), company_id: challenge.company?.id ?? '' }
      : { ...emptyForm(), ...preset }
    save.clear()
    open.value = true
    if (!companies.loaded.value) companies.load()
  }

  function close() {
    if (!save.pending.value) open.value = false
  }

  const save = useAction(async () => {
    const values = Object.fromEntries(FIELDS.map((key) => [key, String(form.value[key] ?? '').trim()]))
    const companyId = form.value.company_id ? Number(form.value.company_id) : null
    let result
    if (editing.value) {
      const body = {}
      for (const key of FIELDS) {
        if (values[key] !== (editing.value[key] ?? '')) body[key] = key === 'title' || key === 'problem' ? values[key] : (values[key] || null)
      }
      if (companyId !== (editing.value.company?.id ?? null)) body.company_id = companyId
      result = Object.keys(body).length ? await updateChallenge(editing.value.id, body) : editing.value
    } else {
      const body = { title: values.title, problem: values.problem, status: form.value.status, company_id: companyId }
      for (const key of FIELDS) if (key !== 'title' && key !== 'problem' && values[key]) body[key] = values[key]
      result = await createChallenge(eventContext.activeEventId, body)
    }
    open.value = false
    await onSaved?.(result, !editing.value)
    return result
  }, { success: () => (editing.value ? 'Desafio atualizado.' : 'Desafio cadastrado.') })

  return { open, editing, form, companies, companyOptions, start, close, save }
}
