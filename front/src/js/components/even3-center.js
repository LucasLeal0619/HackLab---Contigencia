// Lógica do Even3Center.vue: estado compacto da Even3 no cabeçalho ("Even3 ● Atualizado há 3 min" + ↻).
// ↻ sincroniza na hora, sem confirmação. O painel só resume e leva à tela Presença.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { label } from '@/js/labels'
import { go } from '@/js/router'
import { bindEven3Context, canSeeEven3, canSyncEven3, even3Context, loadEven3, syncEven3 } from '@/js/stores/even3-context'
import { flash } from '@/js/stores/ui'

export function relativeTime(iso, now = Date.now()) {
  if (!iso) return ''
  const minutes = Math.max(0, Math.round((now - Date.parse(iso)) / 60_000))
  if (minutes < 1) return 'agora'
  if (minutes < 60) return `há ${minutes} min`
  const hours = Math.round(minutes / 60)
  return hours < 24 ? `há ${hours} h` : `há ${Math.round(hours / 24)} d`
}

export function useEven3Center() {
  const open = ref(false)
  const root = ref(null)
  const now = ref(Date.now())
  let clock = 0

  const visible = computed(() => canSeeEven3())
  const canSync = computed(() => canSyncEven3())
  const busy = computed(() => even3Context.syncing || even3Context.running)
  const attention = computed(() => Number(even3Context.attention?.total || 0))

  const state = computed(() => {
    if (even3Context.error && !even3Context.loaded) return { tone: 'muted', text: 'Indisponível' }
    if (!even3Context.loaded) return { tone: 'muted', text: 'Carregando…' }
    if (!even3Context.configured) return { tone: 'muted', text: 'Não configurado' }
    if (busy.value) return { tone: 'busy', text: 'Sincronizando…' }
    if (even3Context.lastSyncStatus === 'FAILED') return { tone: 'danger', text: 'Última sincronização falhou' }
    if (!even3Context.lastSyncAt) return { tone: 'muted', text: 'Nunca sincronizado' }
    return { tone: attention.value ? 'warn' : 'ok', text: `Atualizado ${relativeTime(even3Context.lastSyncAt, now.value)}` }
  })

  const canRunSync = computed(() => canSync.value && even3Context.configured && even3Context.integrationStatus === 'ACTIVE' && !busy.value)

  function toggle() {
    open.value = !open.value
    if (open.value) loadEven3({ force: false })
  }

  function navigate(to) {
    open.value = false
    go(to)
  }

  async function runSync() {
    if (!canRunSync.value) return
    try {
      const run = await syncEven3('ALL')
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
    clock = window.setInterval(() => { now.value = Date.now() }, 30_000)
  })
  onUnmounted(() => {
    document.removeEventListener('mousedown', onPointer)
    document.removeEventListener('keydown', onKey)
    window.clearInterval(clock)
  })

  return { root, open, visible, canSync, busy, attention, state, canRunSync, toggle, navigate, runSync, reload: () => loadEven3(), even3: even3Context }
}
