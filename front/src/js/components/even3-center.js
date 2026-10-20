// Lógica do Even3Center.vue: botão global "Sincronizar Even3" no cabeçalho + painel de resumo e histórico.
// O painel só mostra contagens (nunca lista pessoas). Cada pendência leva à página onde ela é revisada.
// Participantes não aparecem: são criados automaticamente ao sincronizar.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { unwrap } from '@/js/api/client'
import { useRequest } from '@/js/composables/async'
import { label, tone } from '@/js/labels'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { bindEven3Context, canSeeEven3, canSyncEven3, even3Context, loadEven3, markNewsSeen, syncEven3 } from '@/js/stores/even3-context'
import { flash } from '@/js/stores/ui'
import * as integrationsApi from '@/js/services/integrations'
import { formatDateTime } from '@/js/utils/format'

// Pendências do painel: chave do resumo, rótulo, página de destino e se conta no selo.
// Jurados e evento são aplicados sozinhos ao sincronizar: só aparecem se sobrar algo (ignorado, chegou pelo
// webhook depois do último sync, datas que não couberam nos dias). Organização é interna: não tem fila.
export const QUEUES = [
  { key: 'jurors', label: 'Jurados', to: 'jurados', badge: true },
  { key: 'company_guests', label: 'Empresa / Convidado', to: 'empresas', badge: true },
  // Depende de cada pessoa ativar o próprio acesso: informa, mas não acende o selo.
  { key: 'public_without_access', label: 'Público sem acesso', to: 'votacao-gestao', badge: false },
  { key: 'event_differences', label: 'Divergências do evento', to: 'config', badge: true },
]

export function useEven3Center() {
  const open = ref(false)
  const scope = ref('ALL')
  const root = ref(null)

  const visible = computed(() => canSeeEven3())
  const canSync = computed(() => canSyncEven3())
  const canManage = computed(() => hasPermission('integrations.manage'))
  const busy = computed(() => even3Context.syncing || even3Context.running)
  const pendingTotal = computed(() => QUEUES.filter((q) => q.badge).reduce((sum, q) => sum + Number(even3Context.pending?.[q.key] || 0), 0))

  // Estado do botão: não configurado, sincronizando, falhou, pendências, atualizado.
  const state = computed(() => {
    if (even3Context.error && !even3Context.loaded) return { tone: 'muted', text: 'Indisponível' }
    if (!even3Context.loaded) return { tone: 'muted', text: 'Carregando…' }
    if (!even3Context.configured) return { tone: 'muted', text: 'Não configurado' }
    if (busy.value) return { tone: 'busy', text: 'Sincronizando…' }
    if (even3Context.lastSyncStatus === 'FAILED') return { tone: 'danger', text: 'Última sincronização falhou' }
    if (even3Context.news) return { tone: 'news', text: `${even3Context.news.total} novidade${even3Context.news.total === 1 ? '' : 's'}` }
    if (pendingTotal.value > 0) return { tone: 'warn', text: `${pendingTotal.value} pendência${pendingTotal.value === 1 ? '' : 's'}` }
    if (!even3Context.lastSyncAt) return { tone: 'muted', text: 'Nunca sincronizado' }
    return { tone: 'ok', text: 'Atualizado' }
  })

  const canRunSync = computed(() => canSync.value && even3Context.configured && even3Context.integrationStatus === 'ACTIVE' && !busy.value)

  const rows = computed(() => QUEUES.map((q) => ({
    ...q,
    value: Number(even3Context.pending?.[q.key] || 0),
    ignored: Number(even3Context.ignored?.[q.key] || 0),
  })))

  // Histórico: só as últimas sincronizações, buscadas quando o painel abre (nunca a cada troca de tela).
  const history = useRequest(async ({ signal }) => {
    if (!eventContext.activeEventId || !even3Context.configured) return []
    return unwrap(await integrationsApi.listSyncRuns(eventContext.activeEventId, { per_page: 5 }, { signal })) || []
  }, { immediate: false, initial: [] })
  watch(() => even3Context.revision, () => { if (open.value) history.load() })

  // Novidades vistas no painel: guardadas para mostrar enquanto ele estiver aberto; o selo do botão some.
  const seenNews = ref(null)

  function toggle() {
    open.value = !open.value
    // Abrir o painel recarrega só se o resumo estiver desatualizado.
    if (open.value) {
      seenNews.value = even3Context.news
      markNewsSeen()
      loadEven3({ force: false }).then(() => history.load())
    }
  }

  function navigate(to) {
    open.value = false
    go(to)
  }

  async function runSync() {
    if (!canRunSync.value) return
    try {
      const run = await syncEven3(scope.value)
      flash(`Sincronização ${label('syncStatus', run?.status).toLowerCase()}.`)
    } catch (error) {
      flash(error?.message || 'Não foi possível sincronizar.', 'err')
    }
  }

  function onPointer(event) {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false
  }
  function onKey(event) {
    if (event.key === 'Escape') open.value = false
  }

  onMounted(() => {
    bindEven3Context()
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
  })
  onUnmounted(() => {
    document.removeEventListener('mousedown', onPointer)
    document.removeEventListener('keydown', onKey)
  })

  return { root, open, scope, visible, canSync, canManage, busy, state, canRunSync, rows, history, seenNews, toggle, navigate, runSync, reload: () => loadEven3(), even3: even3Context, formatDateTime, label, tone }
}
