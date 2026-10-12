// Lógica da página PublicVote.vue: área do eleitor (User VOTER elegível), sempre autenticada.
// Sessões em que é elegível (/my-voting-sessions), cédula (/ballot), voto (POST /vote) e o próprio voto (/my-vote).
// O voto é definitivo: repetir o mesmo voto (200) é sucesso; tentar outra equipe (422) mostra a mensagem do backend.
// Nada fica no navegador: depois de recarregar, o estado vem de /ballot e /my-vote.
import { computed, ref } from 'vue'
import { useAction, useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { eventContext } from '@/js/stores/event-context'
import * as votingApi from '@/js/services/voting'
import { formatDateTime } from '@/js/utils/format'

// Sessão sugerida: a aberta (só existe uma por evento); senão a mais recente.
function pickDefault(sessions) {
  return sessions.find((item) => item.status === 'OPEN') || sessions[0] || null
}

export function usePublicVote() {
  const selectedId = ref(null)

  const sessions = useRequest(async ({ signal }) => {
    const items = eventContext.activeEventId ? await votingApi.mySessions(eventContext.activeEventId, { signal }) : []
    if (!items.some((item) => item.id === selectedId.value)) selectedId.value = pickDefault(items)?.id || null
    return items
  }, { initial: [], immediate: false })

  // Cédula + meu voto da sessão escolhida.
  const sheet = useRequest(async ({ signal }) => {
    if (!selectedId.value) return null
    const [ballot, vote] = await Promise.all([
      votingApi.ballot(selectedId.value, { signal }),
      votingApi.myVote(selectedId.value, { signal }),
    ])
    return { ...ballot, my_vote: vote }
  }, { immediate: false })

  async function load() {
    await sessions.load()
    if (selectedId.value) await sheet.load()
    else sheet.data.value = null
  }

  load()

  function selectSession(id) {
    if (id === selectedId.value) return
    selectedId.value = id
    choice.value = null
    sheet.data.value = null
    sheet.load()
  }

  const status = computed(() => sheet.data.value?.session?.status || '')
  const alreadyVoted = computed(() => Boolean(sheet.data.value?.has_voted || sheet.data.value?.my_vote))
  const canVote = computed(() => Boolean(sheet.data.value?.can_vote))
  const candidates = computed(() => sheet.data.value?.candidates || [])

  const choice = ref(null)

  function pick(candidate) {
    if (!canVote.value) return
    cast.clear()
    choice.value = candidate
  }

  const cast = useAction(async () => {
    const vote = await votingApi.castVote(selectedId.value, choice.value.team.id)
    choice.value = null
    sheet.data.value = { ...sheet.data.value, has_voted: true, can_vote: false, my_vote: vote }
    sessions.data.value = (sessions.data.value || []).map((item) => (item.id === selectedId.value ? { ...item, has_voted: true } : item))
    return vote
  }, { success: 'Voto registrado. Obrigado pela participação!' })

  // 422 (ex.: votação fechada no meio do caminho, já votou em outra equipe): mensagem do backend já foi mostrada;
  // recarrega para refletir o estado real.
  async function confirm() {
    const result = await cast.run()
    if (!result && cast.error.value?.kind === 'validation') {
      choice.value = null
      await load()
    }
  }

  return {
    eventContext,
    sessions,
    sheet,
    selectedId,
    selectSession,
    load,
    status,
    alreadyVoted,
    canVote,
    candidates,
    choice,
    pick,
    cast,
    confirm,
    label,
    tone,
    formatDateTime,
  }
}
