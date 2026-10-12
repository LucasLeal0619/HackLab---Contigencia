// Lógica da página JudgeArea.vue: equipes atribuídas à Person logada (GET /events/{e}/my-evaluations).
// Só a avaliação do próprio jurado: notas e andamento dos demais não aparecem aqui.
import { computed } from 'vue'
import { useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { eventContext } from '@/js/stores/event-context'
import { myEvaluations } from '@/js/services/evaluations'
import { go } from '@/js/router'
import { formatDateTime } from '@/js/utils/format'

const ACTIONS = {
  NOT_STARTED: { text: 'Avaliar', ghost: false },
  DRAFT: { text: 'Continuar', ghost: false },
  REVISION_REQUESTED: { text: 'Revisar', ghost: false },
  SUBMITTED: { text: 'Visualizar', ghost: true },
}

export function useJudgeArea() {
  const request = useRequest(async ({ signal }) => {
    if (!eventContext.activeEventId) return { items: [], juror: null }
    const payload = await myEvaluations(eventContext.activeEventId, { signal })
    return { items: payload?.data || [], juror: payload?.meta?.juror || null }
  }, { initial: { items: [], juror: null } })

  const items = computed(() => request.data.value?.items || [])
  const juror = computed(() => request.data.value?.juror || null)
  const inactive = computed(() => juror.value?.status === 'INACTIVE')

  const summary = computed(() => {
    const count = (status) => items.value.filter((item) => item.progress?.status === status).length
    return { total: items.value.length, submitted: count('SUBMITTED'), revision: count('REVISION_REQUESTED') }
  })

  function statusOf(item) {
    const code = item.progress?.status || 'NOT_STARTED'
    if (code === 'NOT_STARTED') return { text: 'Não iniciada', tone: '' }
    return { text: label('evaluationStatus', code), tone: tone('evaluationStatus', code) }
  }

  function actionOf(item) {
    return ACTIONS[item.progress?.status] || ACTIONS.NOT_STARTED
  }

  function open(item) {
    go(`avaliar?id=${item.assignment.id}`)
  }

  return { request, items, juror, inactive, summary, statusOf, actionOf, open, formatDateTime }
}
