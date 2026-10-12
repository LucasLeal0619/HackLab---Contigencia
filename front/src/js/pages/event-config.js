// Lógica da página EventConfig.vue: evento ativo (/events/{id}) e dias (/events/{id}/days). Sem exclusão.
import { computed, ref, watch } from 'vue'
import { useAction, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { hasPermission } from '@/js/stores/auth'
import { activeEvent, eventContext, loadEvents, replaceEvent, selectEvent } from '@/js/stores/event-context'
import * as eventsApi from '@/js/services/events'
import { formatDate } from '@/js/utils/format'

function eventForm(event) {
  return {
    name: event?.name || '',
    description: event?.description || '',
    location: event?.location || '',
    timezone: event?.timezone || 'America/Sao_Paulo',
    start_date: event?.start_date || '',
    end_date: event?.end_date || '',
    status: event?.status || 'PLANNED',
  }
}

function dayForm(day, next = 1) {
  return { id: day?.id || null, day_number: day?.day_number || next, date: day?.date || '', label: day?.label || `Dia ${day?.day_number || next}`, start_time: day?.start_time || '', end_time: day?.end_time || '' }
}

export function useEventConfig() {
  const canManage = computed(() => hasPermission('events.manage'))
  const creating = ref(false)
  const form = ref(eventForm(activeEvent.value))
  const dayModal = ref(null)

  watch(activeEvent, (event) => { if (!creating.value) form.value = eventForm(event) })

  const days = useRequest(({ signal }) => (eventContext.activeEventId ? eventsApi.listDays(eventContext.activeEventId, { signal }) : []), {
    initial: [],
    watchSources: () => eventContext.activeEventId,
  })
  const sortedDays = computed(() => [...(days.data.value || [])].sort((a, b) => a.day_number - b.day_number))

  // Fusos comuns no Brasil; o backend aceita qualquer fuso IANA.
  const timezones = ['America/Sao_Paulo', 'America/Manaus', 'America/Cuiaba', 'America/Belem', 'America/Fortaleza', 'America/Recife', 'America/Bahia', 'America/Rio_Branco', 'America/Noronha']

  function body() {
    return {
      name: form.value.name.trim(),
      description: form.value.description.trim() || null,
      location: form.value.location.trim() || null,
      timezone: form.value.timezone || null,
      start_date: form.value.start_date,
      end_date: form.value.end_date,
      status: form.value.status,
    }
  }

  const save = useAction(async () => {
    if (creating.value) {
      const created = await eventsApi.createEvent(body())
      creating.value = false
      await loadEvents({ force: true })
      selectEvent(created.id)
      return created
    }
    const updated = await eventsApi.updateEvent(eventContext.activeEventId, body())
    replaceEvent(updated)
    return updated
  }, { success: () => 'Evento salvo.' })

  function startCreate() {
    creating.value = true
    form.value = eventForm(null)
    save.clear()
  }

  function cancel() {
    creating.value = false
    form.value = eventForm(activeEvent.value)
    save.clear()
  }

  function openDay(day) {
    const next = (sortedDays.value.at(-1)?.day_number || 0) + 1
    dayModal.value = dayForm(day, next)
    saveDay.clear()
  }

  const saveDay = useAction(async () => {
    const value = dayModal.value
    const payload = { day_number: Number(value.day_number), date: value.date, label: value.label.trim(), start_time: value.start_time || null, end_time: value.end_time || null }
    const eventId = eventContext.activeEventId
    if (value.id) await eventsApi.updateDay(eventId, value.id, payload)
    else await eventsApi.createDay(eventId, payload)
    dayModal.value = null
    await days.load()
    // Mantém os dias do contexto (usados em outras telas) iguais aos da API.
    replaceEvent({ ...activeEvent.value, days: days.data.value })
  }, { success: 'Dia do evento salvo.' })

  return { canManage, creating, form, days, sortedDays, timezones, save, startCreate, cancel, dayModal, openDay, saveDay, activeEvent, eventContext, label, options, tone, formatDate }
}
