// Lógica da página Teams.vue: equipes do evento (/events/{e}/teams, /teams/{t}) e composição (/teams/{t}/members).
// Não existe exclusão de equipe: inativar = status INACTIVE (o backend recusa se houver membros ativos).
// Não há formação automática no backend: a composição é feita membro a membro em #/montar.
import { computed, ref } from 'vue'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as teamsApi from '@/js/services/teams'
import { formatDateTime } from '@/js/utils/format'

export function countText(value, singular, plural) {
  return `${value} ${value === 1 ? singular : plural}`
}

// Contagem de membros por turma (a partir da composição real).
export function classMix(members) {
  const mix = new Map()
  members.forEach((member) => {
    const name = member.participant?.class?.name || 'Sem turma'
    mix.set(name, (mix.get(name) || 0) + 1)
  })
  return [...mix.entries()].sort((a, b) => a[0].localeCompare(b[0], 'pt-BR'))
}

export function useTeams() {
  const canManage = computed(() => hasPermission('teams.manage'))
  const eventId = () => eventContext.activeEventId

  const list = usePaged((query, opts) => (eventId() ? teamsApi.listTeams(eventId(), query, opts) : { data: [] }), {
    filters: { status: '', has_challenge: '' },
    perPage: 24,
    watchSources: () => eventContext.activeEventId,
  })

  const modal = ref(null) // form | status
  const editing = ref(null)
  const form = ref({ name: '', code: '' })

  // ---------- Detalhe (drawer): composição atual + vínculos encerrados ----------
  const viewing = ref(null)
  const members = useRequest(() => (viewing.value ? teamsApi.listMembers(viewing.value.id, { history: 1 }) : []), { immediate: false, initial: [] })
  const activeMembers = computed(() => (members.data.value || []).filter((item) => item.active))
  const pastMembers = computed(() => (members.data.value || []).filter((item) => !item.active))
  const pausedMembers = computed(() => activeMembers.value.filter((item) => item.participant?.status !== 'AVAILABLE'))
  const mix = computed(() => classMix(activeMembers.value))

  function openDetail(team) {
    viewing.value = team
    members.data.value = []
    members.load()
  }

  function openCreate() {
    editing.value = null
    form.value = { name: '', code: '' }
    save.clear()
    modal.value = 'form'
  }

  function openEdit(team) {
    editing.value = team
    form.value = { name: team.name, code: team.code || '' }
    save.clear()
    modal.value = 'form'
  }

  const save = useAction(async () => {
    const body = { name: form.value.name.trim(), code: form.value.code.trim() || null }
    const created = !editing.value
    const result = created ? await teamsApi.createTeam(eventId(), body) : await teamsApi.updateTeam(editing.value.id, body)
    modal.value = null
    if (viewing.value?.id === result?.id) viewing.value = { ...viewing.value, ...result }
    await list.load()
    return { result, created }
  }, { success: ({ created }) => (created ? 'Equipe criada.' : 'Equipe atualizada.') })

  const statusTarget = ref(null)
  function askStatus(team) {
    statusTarget.value = team
    toggleStatus.clear()
    modal.value = 'status'
  }
  const toggleStatus = useAction(async () => {
    const team = statusTarget.value
    const status = team.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
    const result = await teamsApi.updateTeam(team.id, { status })
    modal.value = null
    statusTarget.value = null
    if (viewing.value?.id === result?.id) viewing.value = { ...viewing.value, ...result }
    await list.load()
    return result
  }, { success: (result) => (result?.status === 'ACTIVE' ? 'Equipe reativada.' : 'Equipe inativada.') })

  function openBuild(team) {
    go(`montar?id=${team.id}`)
  }

  return {
    list,
    modal,
    editing,
    form,
    viewing,
    members,
    activeMembers,
    pastMembers,
    pausedMembers,
    mix,
    openDetail,
    openCreate,
    openEdit,
    save,
    statusTarget,
    askStatus,
    toggleStatus,
    openBuild,
    canManage,
    countText,
    label,
    tone,
    options,
    formatDateTime,
  }
}
