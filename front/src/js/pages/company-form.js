// Formulário de empresa (criar/editar), usado em Companies.vue e CompanyDetail.vue.
// Status só vai na criação (DRAFT ou CONFIRMED); depois muda pela rota /status.
import { ref } from 'vue'
import { useAction } from '@/js/composables/async'
import { eventContext } from '@/js/stores/event-context'
import { createCompany, updateCompany } from '@/js/services/companies'

const FIELDS = ['name', 'legal_name', 'document', 'segment', 'type', 'description', 'email', 'phone', 'website']

function emptyForm() {
  return { name: '', legal_name: '', document: '', segment: '', type: 'PARTICIPANT', description: '', email: '', phone: '', website: '', status: 'DRAFT' }
}

export function useCompanyForm({ onSaved } = {}) {
  const open = ref(false)
  const editing = ref(null)
  const form = ref(emptyForm())

  function start(company = null) {
    editing.value = company
    form.value = company
      ? { ...emptyForm(), ...Object.fromEntries(FIELDS.map((key) => [key, company[key] ?? ''])) }
      : emptyForm()
    save.clear()
    open.value = true
  }

  function close() {
    if (!save.pending.value) open.value = false
  }

  const clean = (value) => (typeof value === 'string' ? value.trim() : value)

  const save = useAction(async () => {
    const values = Object.fromEntries(FIELDS.map((key) => [key, clean(form.value[key])]))
    let result
    if (editing.value) {
      // Só envia o que mudou (o backend audita antes/depois).
      const body = {}
      for (const key of FIELDS) {
        const before = editing.value[key] ?? ''
        if (values[key] !== before) body[key] = key === 'name' || key === 'type' ? values[key] : (values[key] || null)
      }
      result = Object.keys(body).length ? await updateCompany(editing.value.id, body) : editing.value
    } else {
      const body = { status: form.value.status }
      for (const key of FIELDS) {
        if (key === 'name' || key === 'type') body[key] = values[key]
        else if (values[key]) body[key] = values[key]
      }
      result = await createCompany(eventContext.activeEventId, body)
    }
    open.value = false
    await onSaved?.(result, !editing.value)
    return result
  }, { success: () => (editing.value ? 'Empresa atualizada.' : 'Empresa cadastrada. Adicione os representantes.') })

  return { open, editing, form, start, close, save }
}
