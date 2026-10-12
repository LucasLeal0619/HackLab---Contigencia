// Lógica da página Meetings.vue (e do formulário reaproveitado em MeetingDetail.vue).
// Reuniões GERAIS (sem setor) são vistas por quem tem meetings.view e criadas/editadas só com escopo global;
// reuniões SETORIAIS exigem alcançar o setor (MeetingPolicy). Pendências ficam na rota própria #/pendencias.
import { computed, ref } from 'vue'
import { ApiError } from '@/js/api/errors'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { auth, hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { createMeeting, listMeetings, updateMeeting } from '@/js/services/meetings'
import { listSectors } from '@/js/services/sectors'
import { formatDateTime, fromLocalInput, toLocalInput } from '@/js/utils/format'

// Permissões e escopo do usuário para reuniões.
export function useMeetingAccess() {
  const ownSectorId = computed(() => auth.user?.sector?.id || null)
  const globalScope = computed(() => !ownSectorId.value)
  const canManageAny = computed(() => hasPermission('meetings.manage'))
  // Geral: só escopo global. Setorial: alcançar o setor (o próprio, para Gestor).
  const canManageIn = (sectorId) => canManageAny.value && (sectorId ? globalScope.value || sectorId === ownSectorId.value : globalScope.value)
  const canManage = (meeting) => Boolean(meeting) && canManageIn(meeting.sector?.id || null)
  return { ownSectorId, globalScope, canManageAny, canManageIn, canManage }
}

// Setores do evento (escopo do usuário) para filtros e formulário. Uma requisição só.
// `immediate: false` adia a busca até `sectors.load()` (ex.: só ao abrir o formulário).
export function useMeetingSectors({ immediate = true } = {}) {
  const sectors = useRequest(() => (eventContext.activeEventId ? listSectors(eventContext.activeEventId) : []), {
    initial: [],
    immediate,
    watchSources: () => eventContext.activeEventId,
  })
  const activeSectors = computed(() => (sectors.data.value || []).filter((item) => item.active))
  return { sectors, activeSectors }
}

function emptyForm(access) {
  return {
    scope: access.globalScope.value ? 'GENERAL' : 'SECTOR',
    sector_id: access.ownSectorId.value || '',
    title: '',
    description: '',
    scheduled_at: '',
    location: '',
    status: 'SCHEDULED',
  }
}

// Formulário de criação/edição. `onSaved(meeting, created)` atualiza a tela que usa o formulário.
export function useMeetingForm({ access, onSaved }) {
  const open = ref(false)
  const editing = ref(null)
  const form = ref(emptyForm(access))

  function openCreate() {
    editing.value = null
    form.value = emptyForm(access)
    save.clear()
    open.value = true
  }

  function openEdit(meeting) {
    editing.value = meeting
    form.value = {
      scope: meeting.scope || (meeting.sector ? 'SECTOR' : 'GENERAL'),
      sector_id: meeting.sector?.id || '',
      title: meeting.title || '',
      description: meeting.description || '',
      scheduled_at: toLocalInput(meeting.scheduled_at),
      location: meeting.location || '',
      status: meeting.status || 'SCHEDULED',
    }
    save.clear()
    open.value = true
  }

  function close() {
    open.value = false
  }

  // Escopos que o usuário pode escolher (Geral só com escopo global).
  const scopeChoices = computed(() => (access.globalScope.value ? ['GENERAL', 'SECTOR'] : ['SECTOR']))

  const save = useAction(async () => {
    const values = form.value
    const sectorId = values.scope === 'SECTOR' ? (values.sector_id ? Number(values.sector_id) : null) : null
    const body = {
      title: values.title.trim(),
      description: values.description.trim() || null,
      scheduled_at: fromLocalInput(values.scheduled_at),
      location: values.location.trim() || null,
    }
    if (values.scope === 'SECTOR' && !sectorId) {
      // Validação local mínima (o backend aceitaria sem setor e criaria uma reunião geral).
      throw new ApiError({ kind: 'validation', message: 'Selecione o setor da reunião.', errors: { sector_id: ['Selecione o setor da reunião.'] } })
    }
    const current = editing.value
    if (!current) {
      if (sectorId) body.sector_id = sectorId
      const created = await createMeeting(eventContext.activeEventId, body)
      open.value = false
      await onSaved?.(created, true)
      return { created }
    }
    // Edição: só manda o setor se mudou (setor atual pode ter sido inativado) e o status se mudou.
    if (sectorId !== (current.sector?.id || null)) body.sector_id = sectorId
    if (values.status !== current.status) body.status = values.status
    const updated = await updateMeeting(current.id, body)
    open.value = false
    await onSaved?.(updated, false)
    return { updated }
  }, { success: (result) => (result?.created ? 'Reunião agendada.' : 'Reunião atualizada.') })

  return { open, editing, form, scopeChoices, openCreate, openEdit, close, save }
}

export function useMeetings() {
  const access = useMeetingAccess()
  const { sectors, activeSectors } = useMeetingSectors()

  // Filtro único "Tipo/Setor": o backend aceita scope=general ou sector_id.
  const list = usePaged((query, options_) => {
    const { place, ...rest } = query
    if (place === 'general') rest.scope = 'general'
    else if (place) rest.sector_id = place
    return listMeetings(eventContext.activeEventId, rest, options_)
  }, {
    filters: { place: '' },
    perPage: 25,
    watchSources: () => eventContext.activeEventId,
  })

  const editor = useMeetingForm({
    access,
    onSaved: async (meeting, created) => {
      if (created) go(`reuniao?id=${meeting.id}`)
      else await list.load()
    },
  })

  return {
    access,
    sectors,
    activeSectors,
    list,
    editor,
    go,
    label,
    tone,
    options,
    formatDateTime,
  }
}
