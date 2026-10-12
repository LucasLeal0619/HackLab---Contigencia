// Lógica da página Evaluations.vue: progresso agregado (sem notas), resultado técnico parcial e avaliações individuais.
// Privacidade: Gestor/Consultor só veem o progresso. Notas e comentários exigem evaluations.all.view.
// O Administrador NÃO edita notas: ele solicita revisão (com motivo) e o próprio jurado corrige e reenvia.
import { computed, ref, watch } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { unwrap } from '@/js/api/client'
import { label, options, tone } from '@/js/labels'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as evaluationsApi from '@/js/services/evaluations'
import { listJurors } from '@/js/services/jurors'
import { go } from '@/js/router'
import { formatDateTime, formatNumber, formatScore } from '@/js/utils/format'

export function useEvaluations() {
  const canProgress = computed(() => hasPermission('evaluations.progress.view'))
  const canAll = computed(() => hasPermission('evaluations.all.view'))
  const canRevision = computed(() => hasPermission('evaluations.request_revision'))
  const canJudgeArea = computed(() => hasPermission('evaluations.own'))
  const canJurors = computed(() => hasPermission('jurors.view'))

  const tabs = computed(() => [
    ...(canProgress.value ? [{ id: 'progresso', label: 'Progresso' }] : []),
    ...(canAll.value ? [{ id: 'tecnico', label: 'Resultado técnico' }, { id: 'individuais', label: 'Avaliações individuais' }] : []),
  ])
  const tab = ref(tabs.value[0]?.id || 'progresso')
  const eventId = () => eventContext.activeEventId

  // ---------- Progresso (sem notas) ----------
  const progress = useRequest(async ({ signal }) => {
    if (!eventId() || !canProgress.value) return []
    return unwrap(await evaluationsApi.evaluationProgress(eventId(), { signal })) || []
  }, { initial: [], immediate: false })

  const totals = computed(() => {
    const rows = progress.data.value || []
    const sum = (key) => rows.reduce((acc, row) => acc + Number(row[key] || 0), 0)
    const assigned = sum('assigned_jurors')
    const submitted = sum('submitted')
    return {
      teams: rows.length,
      withoutJurors: rows.filter((row) => !row.assigned_jurors).length,
      assigned,
      submitted,
      draft: sum('draft'),
      revision: sum('revision_requested'),
      notStarted: sum('not_started'),
      percent: assigned ? Math.round((100 * submitted) / assigned) : null,
    }
  })

  function teamStatus(row) {
    if (!row.assigned_jurors) return { text: 'Sem jurados atribuídos', tone: '' }
    if (row.revision_requested) return { text: 'Em revisão', tone: 'danger' }
    if (row.submitted >= row.assigned_jurors) return { text: 'Concluída', tone: 'ok' }
    if (row.submitted || row.draft) return { text: 'Em andamento', tone: 'warn' }
    return { text: 'Não iniciada', tone: '' }
  }

  // ---------- Resultado técnico parcial ----------
  const technical = useRequest(async ({ signal }) => {
    if (!eventId() || !canAll.value) return { rows: [], note: '' }
    const payload = await evaluationsApi.technicalResults(eventId(), { signal })
    const rows = [...(payload?.data || [])].sort((a, b) => (b.technical_score ?? -1) - (a.technical_score ?? -1))
    return { rows, note: payload?.meta?.note || '' }
  }, { initial: { rows: [], note: '' }, immediate: false })

  // ---------- Avaliações individuais ----------
  const list = usePaged((query, opts) => evaluationsApi.listEvaluations(eventId(), query, opts), {
    filters: { status: '', team_id: '', juror_id: '' },
    perPage: 25,
    immediate: false,
  })

  // Jurados para o filtro (sob demanda; só com jurors.view).
  const jurors = useRequest(async ({ signal }) => {
    if (!eventId() || !canJurors.value) return []
    return fetchAll((query) => listJurors(eventId(), query, { signal }))
  }, { initial: [], immediate: false })

  // Carrega só a aba aberta (backend lento: evita requisições paralelas desnecessárias).
  const loadedFor = { progresso: null, tecnico: null, individuais: null }
  function loadTab(current) {
    const id = eventId()
    if (!id || loadedFor[current] === id) return
    loadedFor[current] = id
    if (current === 'progresso') progress.load()
    if (current === 'tecnico') technical.load()
    if (current === 'individuais') {
      list.page.value = 1
      list.load()
      if (canJurors.value) jurors.load()
      if (!progress.loaded.value && canProgress.value) progress.load()
    }
  }
  watch([tab, () => eventId()], ([current]) => loadTab(current), { immediate: true })

  // Equipes do filtro: as do progresso (todas as equipes do evento).
  const teamOptions = computed(() => (progress.data.value || []).map((row) => row.team))

  // ---------- Detalhe + solicitar revisão ----------
  const detailId = ref(null)
  const detail = useRequest(async ({ signal }) => {
    if (!detailId.value) return null
    const payload = await evaluationsApi.getEvaluation(detailId.value, { signal })
    return { ...payload.data, assignment_status: payload.meta?.assignment_status || null }
  }, { immediate: false })

  function openDetail(item) {
    detailId.value = item.id
    detail.data.value = null
    detail.load()
  }

  function closeDetail() {
    detailId.value = null
    modal.value = null
  }

  const canAskRevision = computed(() => canRevision.value && detail.data.value?.status === 'SUBMITTED' && detail.data.value?.assignment_status === 'ACTIVE')

  const modal = ref(null)
  const reason = ref('')
  function openRevision() {
    reason.value = ''
    revision.clear()
    modal.value = 'revision'
  }

  const revision = useAction(async () => {
    const updated = unwrap(await evaluationsApi.requestRevision(detailId.value, { reason: reason.value.trim() }))
    modal.value = null
    await Promise.all([detail.load(), list.load()])
    loadedFor.progresso = null
    loadedFor.tecnico = null
    return updated
  }, { success: 'Revisão solicitada. O jurado corrige e reenvia a avaliação.' })

  function scoreText(value) {
    return value === null || value === undefined ? '—' : formatNumber(value, Number.isInteger(value) ? 0 : 2)
  }

  return {
    tabs, tab, canProgress, canAll, canRevision, canJudgeArea,
    progress, totals, teamStatus,
    technical,
    list, jurors, teamOptions,
    detailId, detail, openDetail, closeDetail, canAskRevision,
    modal, reason, openRevision, revision,
    scoreText, formatScore, formatDateTime, label, tone, options, go,
  }
}
