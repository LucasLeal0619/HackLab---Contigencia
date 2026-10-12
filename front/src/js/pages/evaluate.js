// Lógica da página Evaluate.vue (#/avaliar?id=<assignmentId>): formulário do jurado por critérios reais.
// Rascunho persistido no backend (PUT), envio com confirmação (POST .../submit). Nada fica salvo no navegador.
// Depois de enviada, a avaliação é somente leitura até a organização solicitar revisão.
import { computed, ref } from 'vue'
import { unwrap } from '@/js/api/client'
import { toPage } from '@/js/api/pagination'
import { useAction, useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { eventContext } from '@/js/stores/event-context'
import { listCriteria } from '@/js/services/jurors'
import * as evaluationsApi from '@/js/services/evaluations'
import { go } from '@/js/router'
import { formatDateTime, formatNumber, formatScore } from '@/js/utils/format'

const fmt = (value) => formatNumber(value, Number.isInteger(Number(value)) ? 0 : 2)

export function useEvaluate(props) {
  const assignmentId = computed(() => Number(props.params?.id) || null)

  const scores = ref({})
  const comments = ref({})
  const notes = ref('')
  const evaluation = ref(null)
  const snapshot = ref('')

  function fill(from) {
    evaluation.value = from
    const nextScores = {}
    const nextComments = {}
    for (const item of from?.scores || []) {
      nextScores[item.criterion_id] = item.score === null || item.score === undefined ? '' : String(item.score)
      nextComments[item.criterion_id] = item.comment || ''
    }
    scores.value = nextScores
    comments.value = nextComments
    notes.value = from?.comments || ''
    snapshot.value = serialize()
  }

  // Atribuição (da lista do próprio jurado) + critérios ativos do evento. Duas requisições, em sequência curta.
  const request = useRequest(async ({ signal }) => {
    const eventId = eventContext.activeEventId
    if (!eventId || !assignmentId.value) return { entry: null, juror: null, criteria: [] }
    const [mine, criteriaPayload] = await Promise.all([
      evaluationsApi.myEvaluations(eventId, { signal }),
      listCriteria(eventId, {}, { signal }),
    ])
    const entry = (mine?.data || []).find((item) => item.assignment?.id === assignmentId.value) || null
    const criteria = toPage(criteriaPayload).items.filter((item) => item.active).sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    fill(entry?.evaluation || null)
    return { entry, juror: mine?.meta?.juror || null, criteria }
  }, { initial: { entry: null, juror: null, criteria: [] } })

  const entry = computed(() => request.data.value?.entry || null)
  const criteria = computed(() => request.data.value?.criteria || [])
  const jurorInactive = computed(() => request.data.value?.juror?.status === 'INACTIVE')
  const status = computed(() => evaluation.value?.status || 'NOT_STARTED')
  const locked = computed(() => status.value === 'SUBMITTED' || jurorInactive.value)
  const isFilled = (id) => scores.value[id] !== undefined && scores.value[id] !== ''
  const filled = computed(() => criteria.value.filter((item) => isFilled(item.id)).length)
  const complete = computed(() => criteria.value.length > 0 && filled.value === criteria.value.length)

  function serialize() {
    return JSON.stringify({ s: scores.value, c: comments.value, n: notes.value })
  }
  const dirty = computed(() => !locked.value && serialize() !== snapshot.value)

  function setScore(id, value) {
    scores.value = { ...scores.value, [id]: value }
  }

  function setComment(id, value) {
    comments.value = { ...comments.value, [id]: value }
  }

  // Todos os critérios ativos, na ordem exibida: o índice do erro 422 (scores.N.score) aponta para criteria[N].
  function payload() {
    return {
      comments: notes.value.trim() || null,
      scores: criteria.value.map((item) => ({
        criterion_id: item.id,
        score: isFilled(item.id) ? Number(String(scores.value[item.id]).replace(',', '.')) : null,
        comment: (comments.value[item.id] || '').trim() || null,
      })),
    }
  }

  function errorsFor(action) {
    const result = {}
    const errors = action.fieldErrors.value || {}
    criteria.value.forEach((item, index) => {
      const message = errors[`scores.${index}.score`] || errors[`scores.${index}.criterion_id`] || errors[`scores.${index}.comment`]
      if (message) result[item.id] = message
    })
    return result
  }

  const save = useAction(async () => {
    submit.clear()
    const saved = unwrap(await evaluationsApi.saveEvaluation(assignmentId.value, payload()))
    fill(saved)
    return saved
  }, { success: 'Rascunho salvo.' })

  const ask = ref(false)
  const submit = useAction(async () => {
    save.clear()
    const sent = unwrap(await evaluationsApi.submitEvaluation(assignmentId.value, payload()))
    fill(sent)
    ask.value = false
    return sent
  }, { success: (result) => (result?.revision ? 'Avaliação reenviada.' : 'Avaliação enviada.') })

  const scoreErrors = computed(() => ({ ...errorsFor(save), ...errorsFor(submit) }))
  const generalError = computed(() => {
    const errors = { ...(save.fieldErrors.value || {}), ...(submit.fieldErrors.value || {}) }
    return errors.scores || errors.status || errors.assignment || errors.comments || ''
  })

  function scaleOf(item) {
    return `${fmt(item.min_score)} a ${fmt(item.max_score)}`
  }

  function back() {
    go('area-jurado')
  }

  return {
    assignmentId, request, entry, criteria, evaluation, status, locked, jurorInactive,
    scores, comments, notes, filled, complete, dirty, isFilled,
    setScore, setComment, save, submit, ask, scoreErrors, generalError,
    scaleOf, back, label, tone, formatDateTime, formatScore,
  }
}
