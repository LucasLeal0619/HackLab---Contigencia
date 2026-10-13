// Lógica da página VotingAdmin.vue: sessões de votação do público do evento ativo.
// Candidatas e elegíveis são listas finais explícitas (PUT), editáveis só em DRAFT. Abrir/fechar/cancelar com confirmação.
// Nenhuma tela mostra eleitor -> equipe: só "já votou" por eleitor, totais de participação e, com a sessão encerrada,
// a contagem por equipe (voting_aggregates.view).
import { computed, ref, shallowRef } from 'vue'
import { errorMessage } from '@/js/api/errors'
import { fetchAll } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { flash } from '@/js/stores/ui'
import { listTeams } from '@/js/services/teams'
import { listUsers } from '@/js/services/users'
import * as votingApi from '@/js/services/voting'
import { formatDateTime, formatNumber } from '@/js/utils/format'

export function useVotingAdmin() {
  const canManage = computed(() => hasPermission('voting_sessions.manage'))
  const canEligibility = computed(() => hasPermission('voting_eligibility.manage'))
  const canAggregates = computed(() => hasPermission('voting_aggregates.view'))
  const canTeams = computed(() => hasPermission('teams.view'))
  const canUsers = computed(() => hasPermission('users.view'))

  const list = usePaged((query, opts) => votingApi.listSessions(eventContext.activeEventId, query, opts), {
    filters: { search: '', status: '' },
    perPage: 20,
  })

  // Sessão aberta no painel lateral (detalhe com candidatas e contadores).
  const selectedId = ref(null)
  const detail = useRequest(({ signal }) => (selectedId.value ? votingApi.getSession(selectedId.value, { signal }) : null), { immediate: false })
  const session = computed(() => detail.data.value)
  const isDraft = computed(() => session.value?.status === 'DRAFT')
  const tab = ref('candidatas')
  const tabs = computed(() => {
    const items = [{ id: 'candidatas', label: 'Candidatas' }, { id: 'elegiveis', label: 'Eleitores elegíveis' }]
    if (canAggregates.value) items.push({ id: 'participacao', label: 'Participação' }, { id: 'contagem', label: 'Contagem' })
    return items
  })

  // Elegíveis (com "já votou", nunca em quem). Paginado.
  const voters = usePaged((query, opts) => (selectedId.value ? votingApi.listEligibilities(selectedId.value, query, opts) : Promise.resolve({ data: [] })), {
    filters: { search: '', has_voted: '' },
    perPage: 50,
    immediate: false,
  })

  const turnout = useRequest(({ signal }) => (selectedId.value && canAggregates.value ? votingApi.sessionTurnout(selectedId.value, { signal }) : null), { immediate: false })
  const counting = useRequest(async ({ signal }) => {
    if (!selectedId.value || !canAggregates.value || session.value?.status !== 'CLOSED') return null
    const payload = await votingApi.sessionResults(selectedId.value, { signal })
    return { ...(payload?.data || {}), note: payload?.meta?.note || '' }
  }, { immediate: false })

  // Prontidão dos Votantes do público (só faz sentido em rascunho): contagens por Person, sem dados pessoais.
  const readiness = useRequest(({ signal }) => (selectedId.value && isDraft.value ? votingApi.publicVoterReadiness(selectedId.value, { signal }) : null), { immediate: false })

  const addPublic = useAction(async () => {
    const result = await votingApi.addPublicVoters(selectedId.value)
    await Promise.all([detail.load(), voters.load(), readiness.load(), list.load()])
    return result
  }, { success: (result) => (result?.added ? `${result.added} votante(s) adicionado(s) à sessão.` : 'Nenhum votante novo para adicionar.') })

  function loadTab(id = tab.value) {
    if (id === 'elegiveis') { voters.load(); readiness.load() }
    if (id === 'participacao') turnout.load()
    if (id === 'contagem') counting.load()
  }

  function setTab(id) {
    tab.value = id
    editing.value = null
    loadTab(id)
  }

  async function openSession(item) {
    selectedId.value = item.id
    detail.data.value = item
    tab.value = 'candidatas'
    editing.value = null
    voters.reset()
    turnout.data.value = null
    counting.data.value = null
    await detail.load()
  }

  function closeDrawer() {
    selectedId.value = null
    editing.value = null
  }

  async function refreshSession() {
    await Promise.all([detail.load(), list.load()])
    loadTab()
  }

  // ---- Criar / renomear (nome só muda em DRAFT) ----
  const modal = ref(null)
  const form = ref({ mode: 'create', name: '' })

  function startCreate() {
    form.value = { mode: 'create', name: '' }
    save.clear()
    modal.value = 'form'
  }

  function startRename() {
    form.value = { mode: 'rename', name: session.value?.name || '' }
    save.clear()
    modal.value = 'form'
  }

  const save = useAction(async () => {
    const body = { name: form.value.name.trim() }
    const renaming = form.value.mode === 'rename'
    const result = renaming ? await votingApi.updateSession(selectedId.value, body) : await votingApi.createSession(eventContext.activeEventId, body)
    modal.value = null
    await list.load()
    if (renaming) detail.data.value = result
    else await openSession(result)
    return renaming
  }, { success: (renamed) => (renamed ? 'Sessão renomeada.' : 'Sessão criada em rascunho.') })

  // ---- Candidatas (lista final) ----
  const editing = ref(null) // 'candidatas' | 'elegiveis'
  const choice = ref(new Set())
  const teams = useRequest(async () => {
    const items = await fetchAll((query) => listTeams(eventContext.activeEventId, { ...query, status: 'ACTIVE' }))
    return items.sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'))
  }, { immediate: false, initial: [] })

  function editCandidates() {
    choice.value = new Set((session.value?.candidates || []).map((item) => item.id))
    editing.value = 'candidatas'
    saveCandidates.clear()
    if (!teams.loaded.value) teams.load()
  }

  // Equipes já candidatas que deixaram de estar ativas continuam visíveis para poder retirá-las.
  const teamOptions = computed(() => {
    const active = teams.data.value || []
    const ids = new Set(active.map((item) => item.id))
    const stale = (session.value?.candidates || []).filter((item) => !ids.has(item.id)).map((item) => ({ ...item, stale: true }))
    return [...active, ...stale]
  })

  function toggle(id) {
    const next = new Set(choice.value)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    choice.value = next
  }

  function selectAll(items) {
    choice.value = new Set(items.filter((item) => !item.disabled).map((item) => item.id))
  }

  function clearChoice() {
    choice.value = new Set()
  }

  const saveCandidates = useAction(async () => {
    const updated = await votingApi.syncCandidates(selectedId.value, [...choice.value])
    detail.data.value = updated
    editing.value = null
    await list.load()
    return updated
  }, { success: (updated) => `Candidatas salvas (${updated?.candidates_count ?? choice.value.size}).` })

  // ---- Elegíveis (lista final de usuários VOTER) ----
  const voterAccounts = useRequest(async () => {
    const items = await fetchAll((query) => listUsers({ ...query, role: 'VOTER' }))
    return items.sort((a, b) => (a.person?.full_name || a.email).localeCompare(b.person?.full_name || b.email, 'pt-BR'))
  }, { immediate: false, initial: [] })
  const voterSearch = ref('')

  const voterOptions = computed(() => {
    const term = voterSearch.value.trim().toLowerCase()
    return (voterAccounts.data.value || [])
      .map((item) => ({ id: item.id, name: item.person?.full_name || item.email, email: item.email, status: item.status, disabled: item.status !== 'ACTIVE' && !choice.value.has(item.id) }))
      .filter((item) => !term || item.name.toLowerCase().includes(term) || item.email.toLowerCase().includes(term))
  })

  const currentEligible = shallowRef([])
  const loadingCurrent = ref(false)

  async function editEligibles() {
    editing.value = 'elegiveis'
    saveEligibles.clear()
    voterSearch.value = ''
    loadingCurrent.value = true
    try {
      const [current] = await Promise.all([
        fetchAll((query) => votingApi.listEligibilities(selectedId.value, query)),
        voterAccounts.loaded.value ? null : voterAccounts.load(),
      ])
      currentEligible.value = current
      choice.value = new Set(current.map((item) => item.user?.id).filter(Boolean))
    } catch (error) {
      editing.value = null
      flash(errorMessage(error), 'err')
    } finally {
      loadingCurrent.value = false
    }
  }

  const saveEligibles = useAction(async () => {
    const updated = await votingApi.syncEligibilities(selectedId.value, [...choice.value])
    detail.data.value = updated
    editing.value = null
    await Promise.all([list.load(), voters.load()])
    return updated
  }, { success: (updated) => `Eleitores elegíveis salvos (${updated?.eligible_voters_count ?? choice.value.size}).` })

  // ---- Abrir / fechar / cancelar ----
  const confirm = ref(null) // 'open' | 'close' | 'cancel'
  const reason = ref('')

  function ask(kind) {
    reason.value = ''
    transition.clear()
    confirm.value = kind
    if (kind === 'open') readiness.load()
  }

  const transition = useAction(async (kind) => {
    const id = selectedId.value
    const updated = kind === 'open' ? await votingApi.openSession(id)
      : kind === 'close' ? await votingApi.closeSession(id)
        : await votingApi.cancelSession(id, reason.value.trim())
    detail.data.value = updated
    confirm.value = null
    await list.load()
    if (kind === 'close' && canAggregates.value) setTab('contagem')
    else loadTab()
    return updated
  }, { success: (_, kind) => ({ open: 'Votação aberta.', close: 'Votação encerrada.', cancel: 'Sessão cancelada.' })[kind] })

  const confirmText = computed(() => {
    const s = session.value
    if (!s) return {}
    if (confirm.value === 'open') {
      return {
        title: 'Abrir votação?',
        subtitle: 'Depois da abertura, candidatas e elegíveis ficam congelados. Só uma votação pode estar aberta por evento.',
        body: '',
        button: 'Abrir votação',
        danger: false,
      }
    }
    if (confirm.value === 'close') {
      return {
        title: 'Encerrar votação?',
        subtitle: 'O encerramento é definitivo: a sessão não reabre. Para repetir a votação, crie outra sessão.',
        body: `${s.votes_cast_count ?? 0} voto(s) registrado(s) de ${s.eligible_voters_count ?? 0} eleitor(es).`,
        button: 'Encerrar votação',
        danger: true,
      }
    }
    return {
      title: 'Cancelar sessão?',
      subtitle: s.status === 'OPEN'
        ? 'Uma votação aberta só pode ser cancelada se ainda não recebeu votos, e o motivo é obrigatório.'
        : 'A sessão em rascunho deixa de existir para os eleitores. O registro continua no histórico.',
      body: '',
      button: 'Cancelar sessão',
      danger: true,
    }
  })

  const reasonRequired = computed(() => session.value?.status === 'OPEN')

  function percent(value) {
    return value === null || value === undefined ? '—' : `${formatNumber(value, 2)}%`
  }

  return {
    list,
    canManage,
    canEligibility,
    canAggregates,
    canTeams,
    canUsers,
    selectedId,
    detail,
    session,
    isDraft,
    tab,
    tabs,
    setTab,
    voters,
    turnout,
    counting,
    openSession,
    closeDrawer,
    refreshSession,
    modal,
    form,
    startCreate,
    startRename,
    save,
    editing,
    choice,
    teams,
    teamOptions,
    toggle,
    selectAll,
    clearChoice,
    editCandidates,
    saveCandidates,
    voterAccounts,
    voterSearch,
    voterOptions,
    currentEligible,
    loadingCurrent,
    editEligibles,
    saveEligibles,
    readiness,
    addPublic,
    confirm,
    reason,
    reasonRequired,
    ask,
    transition,
    confirmText,
    percent,
    label,
    tone,
    options,
    formatDateTime,
  }
}
