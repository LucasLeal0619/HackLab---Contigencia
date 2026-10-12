// Evento ativo. A lista vem de GET /api/v1/events; o id escolhido é só preferência local (hacklab.ui),
// sempre validada contra a lista da API. Nenhum id de evento fixo no código.
import { computed, reactive } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { listEvents } from '@/js/services/events'
import { onSessionEnd } from '@/js/stores/auth'
import { setActiveEventPreference, ui } from '@/js/stores/ui'

export const eventContext = reactive({
  status: 'idle', // idle | loading | ready | error
  events: [],
  activeEventId: null,
  error: null,
  // Muda a cada troca de evento: telas usam como chave para recarregar.
  version: 0,
})

let loading = null

function pick(events, preferred) {
  if (!events.length) return null
  if (preferred && events.some((item) => item.id === preferred)) return preferred
  if (events.length === 1) return events[0].id
  // Mais de um: o ativo (status ACTIVE) mais recente; senão o primeiro da lista (ordenada pela API).
  return (events.find((item) => item.status === 'ACTIVE') || events[0]).id
}

export async function loadEvents({ force = false } = {}) {
  if (loading && !force) return loading
  eventContext.status = 'loading'
  eventContext.error = null
  loading = (async () => {
    try {
      const events = await fetchAll((query) => listEvents(query))
      eventContext.events = events
      const next = pick(events, eventContext.activeEventId || ui.activeEventId)
      if (next !== eventContext.activeEventId) eventContext.version += 1
      eventContext.activeEventId = next
      setActiveEventPreference(next)
      eventContext.status = 'ready'
    } catch (error) {
      eventContext.error = error
      eventContext.status = 'error'
    } finally {
      loading = null
    }
  })()
  return loading
}

export function selectEvent(id) {
  const found = eventContext.events.find((item) => item.id === Number(id))
  if (!found || found.id === eventContext.activeEventId) return
  eventContext.activeEventId = found.id
  eventContext.version += 1
  setActiveEventPreference(found.id)
}

// Atualiza um evento da lista depois de editar (Config do evento) sem recarregar tudo.
export function replaceEvent(event) {
  const index = eventContext.events.findIndex((item) => item.id === event.id)
  if (index >= 0) eventContext.events.splice(index, 1, { ...eventContext.events[index], ...event })
  else eventContext.events.push(event)
}

export function resetEventContext() {
  eventContext.status = 'idle'
  eventContext.events = []
  eventContext.activeEventId = null
  eventContext.error = null
  eventContext.version += 1
}

onSessionEnd(resetEventContext)

export const activeEvent = computed(() => eventContext.events.find((item) => item.id === eventContext.activeEventId) || null)
export const activeDays = computed(() => [...(activeEvent.value?.days || [])].sort((a, b) => a.day_number - b.day_number))
export const eventTimezone = computed(() => activeEvent.value?.timezone || undefined)

export function useEventContext() {
  return { context: eventContext, event: activeEvent, days: activeDays, timezone: eventTimezone }
}
