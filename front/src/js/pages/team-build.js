// Lógica da página TeamBuild.vue (#/montar?id=<teamId>): monta a composição de UMA equipe pelos endpoints reais.
// Adicionar: POST /teams/{t}/members. Remover: DELETE /teams/{t}/members/{p}. Trazer de outra equipe: PATCH /participants/{p}/team.
// Sem sorteio/sugestão automática: cada alteração é um participante, confirmada pelo backend.
import { computed, ref, watch } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as participantsApi from '@/js/services/participants'
import * as teamsApi from '@/js/services/teams'
import { classMix, countText } from '@/js/pages/teams'

export function useTeamBuild(props) {
  const teamId = computed(() => Number(props.params?.id) || null)
  const canClasses = computed(() => hasPermission('classes.view'))
  const canParticipants = computed(() => hasPermission('participants.view'))

  const team = useRequest(() => (teamId.value ? teamsApi.getTeam(teamId.value) : null), { watchSources: () => teamId.value, immediate: true })
  const members = computed(() => team.data.value?.members || [])
  const active = computed(() => members.value.filter((item) => item.participant?.status === 'AVAILABLE'))
  const paused = computed(() => members.value.filter((item) => item.participant?.status !== 'AVAILABLE'))
  const mix = computed(() => classMix(members.value))
  // Equipe de outro evento (link antigo / troca de evento) não é montada aqui.
  const wrongEvent = computed(() => team.data.value && eventContext.activeEventId && team.data.value.event_id !== eventContext.activeEventId)
  const editable = computed(() => team.data.value?.status === 'ACTIVE' && !wrongEvent.value)

  // Turmas para as abas (seletor pequeno).
  const classes = useRequest(() => (canClasses.value && eventContext.activeEventId
    ? fetchAll((query) => participantsApi.listClasses(eventContext.activeEventId, query))
    : []), { initial: [], watchSources: () => eventContext.activeEventId })
  const tabs = computed(() => [{ id: '', label: 'Todos' }, ...(classes.data.value || []).map((item) => ({ id: String(item.id), label: item.name }))])

  // Participantes disponíveis: sem equipe (adicionar) ou em outra equipe (transferir).
  const pool = usePaged((query, opts) => {
    const eventId = team.data.value?.event_id
    if (!eventId || !canParticipants.value) return { data: [] }
    const { mode, ...rest } = query
    return participantsApi.listParticipants(eventId, { ...rest, status: 'AVAILABLE', has_team: mode === 'others' }, opts)
  }, {
    filters: { search: '', class_id: '', mode: 'free' },
    perPage: 20,
    immediate: false,
  })
  const poolItems = computed(() => pool.items.value.filter((item) => item.team?.id !== teamId.value))
  watch(() => team.data.value?.event_id, (eventId, before) => {
    if (eventId && eventId !== before) {
      pool.page.value = 1
      pool.load()
    }
  })

  async function refresh() {
    await Promise.all([team.load(), pool.load()])
  }

  const add = useAction(async (item) => {
    await teamsApi.addMember(teamId.value, item.id)
    await refresh()
    return item
  }, { success: (item) => `${item.person?.full_name || 'Participante'} entrou na equipe.` })

  // Remoção e transferência pedem confirmação.
  const confirm = ref(null) // { kind: 'remove' | 'transfer', item }
  function askRemove(member) {
    confirm.value = { kind: 'remove', id: member.participant?.id, name: member.participant?.full_name }
  }
  function askTransfer(item) {
    confirm.value = { kind: 'transfer', id: item.id, name: item.person?.full_name, from: item.team?.name }
  }

  const remove = useAction(async () => {
    const target = confirm.value
    await teamsApi.removeMember(teamId.value, target.id)
    confirm.value = null
    await refresh()
    return target
  }, { success: (target) => `${target.name || 'Participante'} saiu da equipe. O histórico foi preservado.` })

  const transfer = useAction(async () => {
    const target = confirm.value
    await participantsApi.changeParticipantTeam(target.id, teamId.value)
    confirm.value = null
    await refresh()
    return target
  }, { success: (target) => `${target.name || 'Participante'} foi transferido para a ${team.data.value?.name || 'equipe'}.` })

  const busy = computed(() => add.pending.value || remove.pending.value || transfer.pending.value)

  return {
    teamId,
    team,
    members,
    active,
    paused,
    mix,
    wrongEvent,
    editable,
    tabs,
    pool,
    poolItems,
    add,
    confirm,
    askRemove,
    askTransfer,
    remove,
    transfer,
    busy,
    canParticipants,
    countText,
    label,
    tone,
    go,
  }
}
