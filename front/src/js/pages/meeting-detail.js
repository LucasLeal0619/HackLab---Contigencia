// Lógica da página MeetingDetail.vue (#/reuniao?id=): detalhe real (GET /meetings/{id}), edição e mudança de status.
// Sem ata, decisões, manifestações, participantes ou documentos: o backend não tem esses recursos.
import { computed, ref, watch } from 'vue'
import { useAction, useRequest } from '@/js/composables/async'
import { ApiError } from '@/js/api/errors'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { getMeeting, updateMeeting } from '@/js/services/meetings'
import { formatDateTime } from '@/js/utils/format'
import { useMeetingAccess, useMeetingForm, useMeetingSectors } from './meetings'

export function useMeetingDetail(props) {
  const id = computed(() => Number(props.params?.id) || null)
  const access = useMeetingAccess()

  const meeting = useRequest(() => {
    if (!id.value) throw ApiError.of('not_found', 'Reunião não informada.')
    return getMeeting(id.value)
  }, { watchSources: () => id.value })
  // Troca de ?id= na mesma tela: não deixa a reunião anterior visível se a nova falhar (404/403).
  watch(id, () => { meeting.data.value = null })

  const canManage = computed(() => access.canManage(meeting.data.value))

  // Setores só são buscados quando o formulário abre (evita requisição a mais para quem só consulta).
  const { sectors, activeSectors } = useMeetingSectors({ immediate: false })

  const editor = useMeetingForm({
    access,
    onSaved: (updated) => { meeting.data.value = updated },
  })

  function openEdit() {
    if (!sectors.loaded.value) sectors.load()
    editor.openEdit(meeting.data.value)
  }

  // Mudança rápida de status (Realizada / Cancelada / Agendada), com confirmação.
  const confirming = ref(null)
  const changeStatus = useAction(async (status) => {
    const updated = await updateMeeting(meeting.data.value.id, { status })
    meeting.data.value = updated
    confirming.value = null
    return updated
  }, { success: (updated) => `Reunião marcada como ${label('meetingStatus', updated?.status).toLowerCase()}.` })

  return {
    id,
    access,
    meeting,
    canManage,
    sectors,
    activeSectors,
    editor,
    openEdit,
    confirming,
    changeStatus,
    go,
    label,
    tone,
    options,
    formatDateTime,
  }
}
