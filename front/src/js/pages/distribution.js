// Lógica de Distribution.vue: desafios × equipes do evento ativo.
// Leitura: /events/{e}/challenges e /events/{e}/teams. Atribuição real: PATCH /challenges/{c}/team (null retira).
// Nenhum vínculo é calculado nem guardado no navegador: tudo vem da API e é recarregado após cada ação.
import { computed, ref } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { useAction, useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { assignChallengeTeam, listChallenges } from '@/js/services/challenges'
import { listTeams } from '@/js/services/teams'

const LOCKS_TEAM = ['IN_DEVELOPMENT', 'FINISHED']

export function useDistribution() {
  const canManage = computed(() => hasPermission('challenges.manage'))
  const eventId = () => eventContext.activeEventId

  const data = useRequest(async () => {
    const id = eventId()
    if (!id) return { challenges: [], teams: [] }
    const [challenges, teams] = await Promise.all([
      fetchAll((query) => listChallenges(id, query)),
      fetchAll((query) => listTeams(id, query)),
    ])
    return { challenges, teams }
  }, { initial: { challenges: [], teams: [] }, watchSources: eventId })

  const challenges = computed(() => data.data.value?.challenges || [])
  const teams = computed(() => data.data.value?.teams || [])
  const challengeById = computed(() => new Map(challenges.value.map((item) => [item.id, item])))

  const waiting = computed(() => challenges.value.filter((item) => item.status === 'APPROVED' && !item.team))
  const withTeam = computed(() => challenges.value.filter((item) => item.team))
  const freeTeams = computed(() => teams.value.filter((team) => team.status === 'ACTIVE' && !team.challenge))
  const notReady = computed(() => challenges.value.filter((item) => !item.team && item.status !== 'APPROVED' && item.status !== 'FINISHED').length)

  const stats = computed(() => [
    { label: 'Aprovados aguardando equipe', value: waiting.value.length },
    { label: 'Desafios com equipe', value: withTeam.value.length },
    { label: 'Equipes ativas sem desafio', value: freeTeams.value.length },
    { label: 'Desafios ainda não aprovados', value: notReady.value },
  ])

  // Cartões por equipe (como no protótipo), com empresa vinda do desafio.
  const cards = computed(() => teams.value.map((team) => {
    const challenge = team.challenge ? challengeById.value.get(team.challenge.id) || team.challenge : null
    return { team, challenge, company: challenge?.company?.name || '—' }
  }))

  const isLocked = (challenge) => LOCKS_TEAM.includes(challenge?.status)

  // Modal de atribuição: a partir de um desafio (escolhe a equipe) ou de uma equipe (escolhe o desafio).
  const modal = ref(null)
  const target = ref({ challenge: null, team: null })
  const choice = ref('')

  function openFromChallenge(challenge) {
    target.value = { challenge, team: challenge.team || null }
    choice.value = ''
    assign.clear()
    modal.value = 'by-challenge'
  }

  function openFromTeam(team) {
    target.value = { challenge: null, team }
    choice.value = ''
    assign.clear()
    modal.value = 'by-team'
  }

  function askUnassign(challenge) {
    target.value = { challenge, team: challenge.team }
    unassign.clear()
    modal.value = 'unassign'
  }

  const assign = useAction(async () => {
    const challengeId = modal.value === 'by-team' ? Number(choice.value) : target.value.challenge.id
    const teamId = modal.value === 'by-team' ? target.value.team.id : Number(choice.value)
    const updated = await assignChallengeTeam(challengeId, teamId)
    modal.value = null
    await data.load()
    return updated
  }, { success: (updated) => `"${updated?.title}" com a equipe ${updated?.team?.name || ''}.` })

  const unassign = useAction(async () => {
    const updated = await assignChallengeTeam(target.value.challenge.id, null)
    modal.value = null
    await data.load()
    return updated
  }, { success: 'Equipe retirada do desafio.' })

  return {
    canManage,
    data,
    challenges,
    teams,
    waiting,
    freeTeams,
    stats,
    cards,
    isLocked,
    modal,
    target,
    choice,
    openFromChallenge,
    openFromTeam,
    askUnassign,
    assign,
    unassign,
    go,
    label,
    tone,
  }
}
