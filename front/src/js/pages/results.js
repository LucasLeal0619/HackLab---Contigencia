// Lógica da página Results.vue (Painel de Resultados): só o resultado PUBLICADO (/results/published).
// Sem publicação, estado vazio. Nada é calculado no front.
import { computed } from 'vue'
import { useRequest } from '@/js/composables/async'
import { canAccess } from '@/js/config/access'
import { go } from '@/js/router'
import { auth } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { publishedResults } from '@/js/services/results'
import { formatDateTime } from '@/js/utils/format'

export function useResults() {
  const published = useRequest(({ signal }) => publishedResults(eventContext.activeEventId, { signal }))
  const current = computed(() => (published.data.value?.status === 'PUBLISHED' ? published.data.value : null))

  // Jurado/Votante veem o painel sem os atalhos para a gestão e a apresentação.
  const can = (route) => canAccess(auth.user, route)

  return { published, current, go, can, formatDateTime }
}
