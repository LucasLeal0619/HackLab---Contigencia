// Lógica do componente Shell.vue (o template fica no .vue).
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { navFor, navState } from '@/js/config/access'
import { go } from '@/js/router'
import { auth, hasPermission } from '@/js/stores/auth'
import { activeEvent, eventContext, selectEvent } from '@/js/stores/event-context'
import { resetA11y, setA11y, ui } from '@/js/stores/ui'
import { listOccurrences } from '@/js/services/occurrences'
import { listTasks } from '@/js/services/tasks'

export function useShell(props) {
  const open = ref(false)
  const panel = ref('')
  const expanded = ref([])
  const alerts = ref({ loading: false, error: null, urgent: [], overdue: [], urgentTotal: 0, overdueTotal: 0 })

  function isOpen(id) {
    return expanded.value.includes(id)
  }

  function toggleGroup(id) {
    expanded.value = isOpen(id) ? expanded.value.filter((item) => item !== id) : [...expanded.value, id]
  }

  const nav = computed(() => navFor(auth.user))
  const current = computed(() => navState(props.path, auth.user))
  const user = computed(() => auth.user)
  const alertCount = computed(() => alerts.value.urgentTotal + alerts.value.overdueTotal)
  const canAlerts = computed(() => hasPermission('occurrences.view') || hasPermission('tasks.view'))

  // Alertas reais: ocorrências urgentes em aberto e pendências atrasadas do evento ativo (no escopo do usuário).
  async function loadAlerts() {
    const eventId = eventContext.activeEventId
    if (!eventId || !canAlerts.value) return
    alerts.value = { ...alerts.value, loading: true, error: null }
    try {
      const [occurrences, tasks] = await Promise.all([
        hasPermission('occurrences.view') ? listOccurrences(eventId, { status: 'OPEN', priority: 'URGENT', per_page: 5 }) : null,
        hasPermission('tasks.view') ? listTasks(eventId, { overdue: 1, per_page: 5 }) : null,
      ])
      alerts.value = {
        loading: false,
        error: null,
        urgent: occurrences?.data || [],
        urgentTotal: occurrences?.meta?.total || 0,
        overdue: tasks?.data || [],
        overdueTotal: tasks?.meta?.total || 0,
      }
    } catch (error) {
      alerts.value = { ...alerts.value, loading: false, error }
    }
  }

  function togglePanel(name) {
    panel.value = panel.value === name ? '' : name
    if (panel.value === 'alerts') loadAlerts()
  }

  const spacingOptions = [['padrao', 'Padrão'], ['confortavel', 'Confortável'], ['amplo', 'Amplo']]

  watch(() => props.path, () => {
    panel.value = ''
    open.value = false
    const parent = current.value.group
    if (parent && !expanded.value.includes(parent)) expanded.value = [...expanded.value, parent]
  }, { immediate: true })

  watch(() => eventContext.activeEventId, () => loadAlerts())

  function onPointer(event) {
    if (!panel.value) return
    if (event.target.closest('.top-tools')) return
    panel.value = ''
  }

  function onKey(event) {
    if (event.key !== 'Escape') return
    if (panel.value) panel.value = ''
    else if (open.value) open.value = false
  }

  onMounted(() => {
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
    loadAlerts()
  })
  onUnmounted(() => {
    document.removeEventListener('mousedown', onPointer)
    document.removeEventListener('keydown', onKey)
  })

  return {
    ui,
    open,
    panel,
    nav,
    isOpen,
    toggleGroup,
    togglePanel,
    current,
    user,
    alerts,
    alertCount,
    canAlerts,
    spacingOptions,
    setA11y,
    resetA11y,
    eventContext,
    activeEvent,
    selectEvent,
    go,
  }
}
