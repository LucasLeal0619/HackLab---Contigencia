// Lógica da página Presentation.vue: apresentação em tela cheia SOMENTE do resultado publicado (/results/published).
// Sem prévia, sem dados locais e sem premiações (não há backend de premiações).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRequest } from '@/js/composables/async'
import { go } from '@/js/router'
import { eventContext } from '@/js/stores/event-context'
import { publishedResults } from '@/js/services/results'
import { formatNumber, formatScore } from '@/js/utils/format'

const PRESENT_STEPS = ['abertura', 'jurados', 'publico', 'fim']

export function usePresentation() {
  const published = useRequest(({ signal }) => publishedResults(eventContext.activeEventId, { signal }))
  const current = computed(() => (published.data.value?.status === 'PUBLISHED' ? published.data.value : null))
  const snapshot = computed(() => current.value?.snapshot || null)
  const technical = computed(() => snapshot.value?.technical?.teams || [])
  const popular = computed(() => snapshot.value?.popular || null)
  const eventName = computed(() => snapshot.value?.event?.name || 'HackLab')

  const step = ref(0)
  const stepId = computed(() => PRESENT_STEPS[step.value])

  function goStep(next) {
    step.value = Math.min(PRESENT_STEPS.length - 1, Math.max(0, next))
  }

  function onKey(event) {
    if (event.target?.closest?.('input, textarea, select')) return
    if (event.key === 'ArrowRight' || event.key === 'PageDown' || event.key === ' ') goStep(step.value + 1)
    if (event.key === 'ArrowLeft' || event.key === 'PageUp') goStep(step.value - 1)
    if (event.key === 'Escape') go('painel')
  }

  onMounted(() => window.addEventListener('keydown', onKey))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

  function percent(value) {
    return value === null || value === undefined ? '—' : `${formatNumber(value, 2)}%`
  }

  return { PRESENT_STEPS, published, current, technical, popular, eventName, step, stepId, goStep, percent, formatScore, go }
}
