import { computed, ref, watch } from 'vue'
import { useAction, useRequest } from '@/js/composables/async'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { createFinancialItem, deleteFinancialItem, listFinancialItems, updateFinancialItem } from '@/js/services/financial-items'
import { go } from '@/js/router'

const moneyFormatter = new Intl.NumberFormat('pt-BR')
const quantityFormatter = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 4 })
const quotedStatus = (status) => String(status || '').trim().toLocaleLowerCase('pt-BR').startsWith('cotado')
const pendingStatus = (status) => String(status || '').trim().toLocaleLowerCase('pt-BR').startsWith('a cotar')

function scaled(value) {
  const text = String(value ?? '').trim().replace(',', '.')
  if (!/^\d+(?:\.\d{0,4})?$/.test(text)) return null
  const [whole, fraction = ''] = text.split('.')
  return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'))
}

function centsFromDecimal(value) {
  const text = String(value ?? '').trim().replace(',', '.')
  const match = /^(\d+)(?:\.(\d+))?$/.exec(text)
  if (!match) return null
  const fraction = match[2] || ''
  let cents = BigInt(match[1]) * 100n + BigInt((fraction + '00').slice(0, 2))
  if (fraction.length > 2 && Number(fraction[2]) >= 5) cents += 1n
  return cents
}

function formatCents(cents) {
  const whole = cents / 100n
  const fraction = String(cents % 100n).padStart(2, '0')
  return `R$ ${moneyFormatter.format(whole)},${fraction}`
}

export function calculateTotalCents(quantityValue, unitPriceValue) {
  if (unitPriceValue === '' || unitPriceValue === null || unitPriceValue === undefined) return null
  const quantity = scaled(quantityValue)
  const price = scaled(unitPriceValue)
  if (quantity === null || price === null) return null
  return (quantity * price + 500000n) / 1000000n
}

export function formatMoney(value) {
  if (value === null || value === undefined || value === '') return '—'
  const cents = centsFromDecimal(value)
  return cents === null ? '—' : formatCents(cents)
}

export function formatQuantity(value) {
  const quantity = Number(value)
  return Number.isFinite(quantity) ? quantityFormatter.format(quantity) : '—'
}

function emptyForm() {
  return {
    category: '',
    item: '',
    specification: '',
    quantity: '',
    unit: '',
    unit_price: '',
    supplier: '',
    status: '',
    notes: '',
  }
}

export function useFinance() {
  const records = useRequest(
    () => (eventContext.activeEventId ? listFinancialItems(eventContext.activeEventId) : []),
    { initial: [], watchSources: () => eventContext.activeEventId },
  )
  const form = ref(emptyForm())
  const selected = ref(null)
  const deleting = ref(null)
  const search = ref('')
  const categoryFilter = ref('')
  const statusFilter = ref('')
  const sortBy = ref('category')
  const canManage = computed(() => hasPermission('finance.manage'))

  const previewCents = computed(() => {
    return calculateTotalCents(form.value.quantity, form.value.unit_price)
  })

  const categories = computed(() => [...new Set((records.data.value || []).map((item) => item.category))].sort((a, b) => a.localeCompare(b, 'pt-BR')))
  const statuses = computed(() => [...new Set((records.data.value || []).map((item) => item.status))].sort((a, b) => a.localeCompare(b, 'pt-BR')))
  const visibleItems = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('pt-BR')
    const items = (records.data.value || []).filter((item) => {
      const matchesName = !query || item.item.toLocaleLowerCase('pt-BR').includes(query)
      const matchesCategory = !categoryFilter.value || item.category === categoryFilter.value
      const matchesStatus = !statusFilter.value || item.status === statusFilter.value
      return matchesName && matchesCategory && matchesStatus
    })
    return items.sort((a, b) => {
      if (sortBy.value === 'category') return a.category.localeCompare(b.category, 'pt-BR') || a.item.localeCompare(b.item, 'pt-BR')
      if (sortBy.value === 'item') return a.item.localeCompare(b.item, 'pt-BR')
      const aTotal = centsFromDecimal(a.total_amount)
      const bTotal = centsFromDecimal(b.total_amount)
      if (aTotal === null) return bTotal === null ? 0 : 1
      if (bTotal === null) return -1
      return sortBy.value === 'total_desc' ? (aTotal > bTotal ? -1 : aTotal < bTotal ? 1 : 0) : (aTotal < bTotal ? -1 : aTotal > bTotal ? 1 : 0)
    })
  })

  const summary = computed(() => {
    const items = records.data.value || []
    let total = 0n
    for (const item of items) {
      const amount = centsFromDecimal(item.total_amount)
      if (amount !== null) total += amount
    }
    return {
      total: formatCents(total),
      registered: items.length,
      quoted: items.filter((item) => item.unit_price !== null && quotedStatus(item.status)).length,
      pending: items.filter((item) => item.unit_price === null || pendingStatus(item.status)).length,
    }
  })

  const categoryTotals = computed(() => {
    const totals = new Map()
    for (const item of records.data.value || []) {
      if (!totals.has(item.category)) totals.set(item.category, 0n)
      const amount = centsFromDecimal(item.total_amount)
      if (amount !== null) totals.set(item.category, totals.get(item.category) + amount)
    }
    return [...totals.entries()]
      .sort(([a], [b]) => a.localeCompare(b, 'pt-BR'))
      .map(([category, cents]) => ({ category, total: formatCents(cents) }))
  })

  function resetForm() {
    form.value = emptyForm()
    selected.value = null
    save.clear()
  }

  function edit(item) {
    selected.value = item
    form.value = {
      category: item.category || '',
      item: item.item || '',
      specification: item.specification || '',
      quantity: item.quantity || '',
      unit: item.unit || '',
      unit_price: item.unit_price ?? '',
      supplier: item.supplier || '',
      status: item.status || '',
      notes: item.notes || '',
    }
    save.clear()
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const save = useAction(async () => {
    const body = {
      ...form.value,
      quantity: String(form.value.quantity),
      unit_price: form.value.unit_price === '' ? null : String(form.value.unit_price),
      supplier: form.value.supplier.trim() || null,
      notes: form.value.notes.trim() || null,
    }
    if (selected.value) await updateFinancialItem(selected.value.id, body)
    else await createFinancialItem(eventContext.activeEventId, body)
    await records.load()
    const updated = Boolean(selected.value)
    resetForm()
    return { updated }
  }, { success: (result) => (result?.updated ? 'Item financeiro atualizado.' : 'Item financeiro cadastrado.') })

  const remove = useAction(async () => {
    if (!deleting.value) return
    await deleteFinancialItem(deleting.value.id)
    deleting.value = null
    await records.load()
  }, { success: 'Item financeiro excluído.' })

  const formatStatus = (status) => {
    const value = String(status || '').trim().toLocaleLowerCase('pt-BR')
    if (value.startsWith('cotado') || value.startsWith('aprovado') || value.startsWith('comprado')) return 'success'
    if (value.startsWith('a cotar')) return 'warning'
    if (value.startsWith('estimado')) return 'info'
    return 'neutral'
  }

  return {
    records, form, selected, deleting, search, categoryFilter, statusFilter, sortBy, canManage,
    previewTotal: computed(() => (previewCents.value === null ? 'Aguardando cotação' : formatCents(previewCents.value))),
    categories, statuses, visibleItems, summary, categoryTotals,
    save, remove, resetForm, edit, formatMoney, formatQuantity, formatStatus, go,
  }
}
