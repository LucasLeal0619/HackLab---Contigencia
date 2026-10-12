// Lógica de ChallengeDetail.vue: GET /challenges/{id}, edição (PATCH), status (PATCH /status) e equipe (PATCH /team).
// Regras do backend (ChallengeService): Distribuído/Em desenvolvimento exigem equipe; com equipe só Distribuído,
// Em desenvolvimento ou Finalizado; em Em desenvolvimento/Finalizado a equipe não é trocada nem retirada.
import { computed, ref } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { useAction, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { useChallengeForm } from '@/js/pages/challenge-form'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as challengesApi from '@/js/services/challenges'
import { listTeams } from '@/js/services/teams'

export const FLOW = ['DRAFT', 'RECEIVED', 'UNDER_REVIEW', 'APPROVED', 'DISTRIBUTED', 'IN_DEVELOPMENT', 'FINISHED']
const REQUIRES_TEAM = ['DISTRIBUTED', 'IN_DEVELOPMENT']
const ALLOWS_TEAM = ['DISTRIBUTED', 'IN_DEVELOPMENT', 'FINISHED']
const LOCKS_TEAM = ['IN_DEVELOPMENT', 'FINISHED']

// Próximo passo sugerido em cada status (o restante fica em "Alterar status").
const NEXT_STEP = {
  DRAFT: { status: 'RECEIVED', text: 'Marcar como recebido' },
  RECEIVED: { status: 'UNDER_REVIEW', text: 'Iniciar análise' },
  UNDER_REVIEW: { status: 'APPROVED', text: 'Aprovar' },
  DISTRIBUTED: { status: 'IN_DEVELOPMENT', text: 'Iniciar desenvolvimento' },
  IN_DEVELOPMENT: { status: 'FINISHED', text: 'Finalizar' },
}

// Equipes ativas sem desafio (as únicas que podem receber um), para seletores.
export function loadFreeTeams() {
  const eventId = eventContext.activeEventId
  if (!eventId) return []
  return fetchAll((query) => listTeams(eventId, { ...query, status: 'ACTIVE', has_challenge: 0 }))
}

export function allowedStatuses(challenge) {
  if (!challenge) return []
  const hasTeam = Boolean(challenge.team)
  return FLOW.filter((code) => (hasTeam ? ALLOWS_TEAM.includes(code) : !REQUIRES_TEAM.includes(code)))
}

export function useChallengeDetail(props) {
  const id = computed(() => Number(props.params?.id) || null)
  const canManage = computed(() => hasPermission('challenges.manage'))
  const canViewCompanies = computed(() => hasPermission('companies.view'))

  const detail = useRequest(() => (id.value ? challengesApi.getChallenge(id.value) : null))
  const challenge = computed(() => detail.data.value)
  const reload = () => detail.load()

  const form = useChallengeForm({ onSaved: reload })

  const statusOptions = computed(() => allowedStatuses(challenge.value))
  const nextStep = computed(() => {
    const step = NEXT_STEP[challenge.value?.status]
    return step && statusOptions.value.includes(step.status) ? step : null
  })
  const teamLocked = computed(() => LOCKS_TEAM.includes(challenge.value?.status))
  const canDistribute = computed(() => challenge.value?.status === 'APPROVED' && !challenge.value?.team)
  const canMove = computed(() => Boolean(challenge.value?.team) && !teamLocked.value)

  const modal = ref(null)

  // --- Status ---
  const nextStatus = ref('')
  function openStatus(status = '') {
    nextStatus.value = status || statusOptions.value.find((code) => code !== challenge.value.status) || ''
    changeStatus.clear()
    modal.value = 'status'
  }
  const changeStatus = useAction(async () => {
    const updated = await challengesApi.changeChallengeStatus(challenge.value.id, nextStatus.value)
    modal.value = null
    detail.data.value = updated
    return updated
  }, { success: (updated) => `Status do desafio: ${label('challengeStatus', updated?.status)}.` })

  // --- Equipe ---
  const teams = useRequest(() => loadFreeTeams(), { immediate: false, initial: [] })
  const teamId = ref('')
  function openTeam() {
    teamId.value = ''
    assign.clear()
    modal.value = 'team'
    teams.load()
  }
  const assign = useAction(async () => {
    const updated = await challengesApi.assignChallengeTeam(challenge.value.id, Number(teamId.value))
    modal.value = null
    detail.data.value = updated
    return updated
  }, { success: (updated) => `Desafio com a equipe ${updated?.team?.name || ''}.` })

  function askUnassign() {
    unassign.clear()
    modal.value = 'unassign'
  }
  const unassign = useAction(async () => {
    const updated = await challengesApi.assignChallengeTeam(challenge.value.id, null)
    modal.value = null
    detail.data.value = updated
    return updated
  }, { success: 'Equipe retirada do desafio.' })

  return {
    id,
    detail,
    challenge,
    canManage,
    canViewCompanies,
    form,
    FLOW,
    statusOptions,
    nextStep,
    teamLocked,
    canDistribute,
    canMove,
    modal,
    nextStatus,
    openStatus,
    changeStatus,
    teams,
    teamId,
    openTeam,
    assign,
    askUnassign,
    unassign,
    go,
    label,
    tone,
    options,
  }
}
