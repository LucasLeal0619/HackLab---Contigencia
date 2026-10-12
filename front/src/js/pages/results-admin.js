// Lógica da página ResultsAdmin.vue: prévia (results.preview), histórico de publicações, publicar/revogar (results.publish)
// e o resultado oficial publicado (results.view_published). O front não calcula nada: mostra o que o backend devolve,
// inclusive os impedimentos de publicação (ex.: "não há avaliação técnica válida").
import { computed, ref } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { useAction, useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as resultsApi from '@/js/services/results'
import { listSessions } from '@/js/services/voting'
import { formatDateTime } from '@/js/utils/format'

export function useResultsAdmin() {
  const canPreview = computed(() => hasPermission('results.preview'))
  const canPublish = computed(() => hasPermission('results.publish'))
  const canPublished = computed(() => hasPermission('results.view_published'))
  const canSessions = computed(() => hasPermission('voting_sessions.view'))

  const tabs = computed(() => {
    const items = []
    if (canPreview.value) items.push({ id: 'previa', label: 'Prévia e publicação' }, { id: 'publicacoes', label: 'Histórico de publicações' })
    if (canPublished.value) items.push({ id: 'oficial', label: 'Resultado oficial' })
    return items
  })
  const tab = ref(canPreview.value ? 'previa' : 'oficial')

  // Sessões encerradas do evento: a prévia/publicação sempre indica a sessão (nunca inferida pelo backend).
  const sessionId = ref('')
  const sessions = useRequest(async () => {
    if (!canPreview.value || !canSessions.value) return []
    const items = await fetchAll((query) => listSessions(eventContext.activeEventId, { ...query, status: 'CLOSED' }))
    if (!sessionId.value && items.length) sessionId.value = String(items[0].id)
    return items
  }, { initial: [], immediate: false })

  const preview = useRequest(({ signal }) => (canPreview.value ? resultsApi.previewResults(eventContext.activeEventId, sessionId.value || null, { signal }) : null), { immediate: false })
  const publications = useRequest(({ signal }) => (canPreview.value ? resultsApi.listPublications(eventContext.activeEventId, { signal }) : []), { initial: [], immediate: false })
  const published = useRequest(({ signal }) => (canPublished.value ? resultsApi.publishedResults(eventContext.activeEventId, { signal }) : null), { immediate: false })

  async function start() {
    if (canPreview.value) {
      await sessions.load()
      await preview.load()
    }
    if (canPublished.value) published.load()
  }
  start()

  function setTab(id) {
    tab.value = id
    if (id === 'publicacoes' && !publications.loaded.value) publications.load()
    if (id === 'oficial' && !published.loaded.value) published.load()
    if (id === 'previa' && !preview.loaded.value) preview.load()
  }

  function changeSession(value) {
    sessionId.value = value
    preview.load()
  }

  const blockers = computed(() => preview.data.value?.blockers || [])
  const pending = computed(() => preview.data.value?.completeness?.pending || [])
  const current = computed(() => (published.data.value?.status === 'PUBLISHED' ? published.data.value : null))

  // ---- Publicar / revogar (com confirmação) ----
  const modal = ref(null) // 'publish' | 'revoke' | 'snapshot'
  const reason = ref('')
  const viewing = ref(null)

  function askPublish() {
    publish.clear()
    modal.value = 'publish'
  }

  function askRevoke() {
    reason.value = ''
    revoke.clear()
    modal.value = 'revoke'
  }

  function viewSnapshot(item) {
    viewing.value = item
    modal.value = 'snapshot'
  }

  async function refreshAll() {
    await Promise.all([preview.load(), published.load()])
    if (publications.loaded.value || tab.value === 'publicacoes') await publications.load()
  }

  const publish = useAction(async () => {
    const created = await resultsApi.publishResults(eventContext.activeEventId, Number(sessionId.value))
    modal.value = null
    await refreshAll()
    return created
  }, { success: (created) => `Resultado oficial publicado (versão ${created?.version ?? ''}).` })

  const revoke = useAction(async () => {
    const revoked = await resultsApi.revokeResults(eventContext.activeEventId, reason.value.trim())
    modal.value = null
    await refreshAll()
    return revoked
  }, { success: (revoked) => `Publicação versão ${revoked?.version ?? ''} revogada.` })

  return {
    canPreview,
    canPublish,
    canPublished,
    canSessions,
    tabs,
    tab,
    setTab,
    sessionId,
    sessions,
    changeSession,
    preview,
    publications,
    published,
    blockers,
    pending,
    current,
    modal,
    reason,
    viewing,
    askPublish,
    askRevoke,
    viewSnapshot,
    publish,
    revoke,
    label,
    tone,
    go,
    formatDateTime,
  }
}
