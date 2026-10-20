// Estado da Even3 do evento ativo, compartilhado pelo cabeçalho e pela tela Presença.
// Sincronizar = endpoint central (POST .../sync), sem confirmação (idempotente, auditado, com lock).
// O resumo vem do PostgreSQL (GET .../workbench): carrega ao entrar, ao trocar de evento e depois de sincronizar.
// Reserva silenciosa: com um Administrador usando o sistema, sincroniza sozinho quando a última tem mais de 10 min.
import { reactive, watch } from 'vue'
import { unwrap } from '@/js/api/client'
import { auth, AUTH, hasPermission, onSessionEnd } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import * as integrationsApi from '@/js/services/integrations'

const STALE_MS = 60_000
const POLL_MS = 4_000
const POLL_LIMIT_MS = 3 * 60_000
export const AUTO_SYNC_MS = 10 * 60_000
const AUTO_CHECK_MS = 60_000

function emptyState() {
  return {
    eventId: null,
    loaded: false,
    loading: false,
    error: null,
    configured: false,
    integrationStatus: null,
    externalEventId: null,
    lastSyncAt: null,
    lastSyncStatus: null,
    running: false,
    totals: null,
    attention: null,
    tickets: null,
    eventDifferences: 0,
    loadedAt: 0,
  }
}

export const even3Context = reactive({
  ...emptyState(),
  syncing: false,
  syncError: null,
  // Resumo da última sincronização feita por este navegador (mostrado na Presença e no cabeçalho).
  lastResult: null,
  // Muda depois de cada sincronização: a tela Presença recarrega as listas.
  revision: 0,
})

let controller = null
let pollTimer = 0
let pollStarted = 0
let autoTimer = 0
let bound = false

export const canSeeEven3 = () => hasPermission('integrations.view')
export const canSyncEven3 = () => hasPermission('integrations.sync')

function apply(data) {
  Object.assign(even3Context, {
    configured: Boolean(data?.configured),
    integrationStatus: data?.integration_status ?? null,
    externalEventId: data?.external_event_id ?? null,
    lastSyncAt: data?.sync?.last_sync_at ?? null,
    lastSyncStatus: data?.sync?.last_sync_status ?? null,
    running: Boolean(data?.sync?.running),
    totals: data?.totals ?? null,
    attention: data?.attention ?? null,
    tickets: data?.tickets ?? null,
    eventDifferences: Number(data?.event_differences || 0),
  })
}

function stopPolling() {
  window.clearTimeout(pollTimer)
  pollTimer = 0
}

// Sincronização de outra origem em andamento: consulta em intervalo curto só até ela terminar.
function schedulePoll() {
  if (pollTimer || even3Context.syncing) return
  if (!pollStarted) pollStarted = Date.now()
  if (Date.now() - pollStarted > POLL_LIMIT_MS) return
  pollTimer = window.setTimeout(() => {
    pollTimer = 0
    loadEven3()
  }, POLL_MS)
}

export async function loadEven3({ force = true } = {}) {
  const eventId = eventContext.activeEventId
  if (!eventId || !canSeeEven3()) return null
  if (!force && even3Context.loaded && even3Context.eventId === eventId && Date.now() - even3Context.loadedAt < STALE_MS) return null

  controller?.abort()
  controller = new AbortController()
  const mine = controller
  even3Context.loading = true
  try {
    const data = unwrap(await integrationsApi.even3Workbench(eventId, { signal: mine.signal }))
    if (mine !== controller || eventId !== eventContext.activeEventId) return null
    // O erro anterior só some com um sucesso (enquanto tenta de novo, a mensagem continua visível).
    even3Context.error = null
    apply(data)
    Object.assign(even3Context, { eventId, loaded: true, loadedAt: Date.now() })
    if (even3Context.running) schedulePoll()
    else { stopPolling(); pollStarted = 0 }
    return data
  } catch (error) {
    if (mine !== controller || error?.kind === 'aborted') return null
    even3Context.error = error
    return null
  } finally {
    if (mine === controller) even3Context.loading = false
  }
}

export function resetEven3() {
  controller?.abort()
  controller = null
  stopPolling()
  pollStarted = 0
  Object.assign(even3Context, emptyState(), { syncing: false, syncError: null, lastResult: null })
}

// Resumo curto para gente (sem detalhes técnicos): o que mudou e o que precisa de atenção.
export function resultFrom(run, summary) {
  const c = run?.counters || {}
  const lines = [
    [Number(c.attendees_created || 0), 'inscrição(ões) nova(s)'],
    [Number(c.attendees_updated || 0), 'inscrição(ões) atualizada(s)'],
    [Number(c.jurors_created || 0), 'jurado(s) adicionado(s)'],
    [Number(summary?.totals?.public_without_access || 0), 'pessoa(s) do público disponível(is) para primeiro acesso'],
  ].filter(([count]) => count > 0).map(([count, text]) => ({ count, text }))
  return {
    status: run?.status || null,
    lines,
    attention: Number(summary?.attention?.total || 0),
    at: run?.finished_at || new Date().toISOString(),
  }
}

// Único disparo da sincronização no front (botão Sincronizar ou reserva automática). 409 = já em andamento.
export async function syncEven3(scope = 'ALL', { automatic = false } = {}) {
  const eventId = eventContext.activeEventId
  if (!eventId || even3Context.syncing) return null
  even3Context.syncing = true
  even3Context.syncError = null
  stopPolling()
  let run = null
  try {
    run = unwrap(await integrationsApi.syncEven3(eventId, automatic ? { scope, automatic: true } : { scope }))
    return run
  } catch (error) {
    even3Context.syncError = error?.kind === 'conflict' ? { ...error, message: 'Sincronização já em andamento.' } : error
    throw even3Context.syncError
  } finally {
    even3Context.syncing = false
    if (eventId === eventContext.activeEventId) {
      even3Context.revision += 1
      const summary = await loadEven3()
      if (run && !automatic) even3Context.lastResult = resultFrom(run, summary)
    }
  }
}

// Hora de sincronizar sozinho? Só Administrador, integração ativa, nada em andamento.
export function autoSyncDue(now = Date.now()) {
  if (!canSyncEven3() || !even3Context.loaded || !even3Context.configured) return false
  if (even3Context.integrationStatus !== 'ACTIVE' || even3Context.running || even3Context.syncing) return false
  const last = even3Context.lastSyncAt ? Date.parse(even3Context.lastSyncAt) : 0
  return !last || now - last >= AUTO_SYNC_MS
}

export async function autoSyncIfDue() {
  if (!autoSyncDue()) return null
  try {
    return await syncEven3('ALL', { automatic: true })
  } catch {
    return null // silencioso: o cabeçalho mostra o estado
  }
}

function startAutoSync() {
  if (!autoTimer) autoTimer = window.setInterval(() => { autoSyncIfDue() }, AUTO_CHECK_MS)
}

function stopAutoSync() {
  window.clearInterval(autoTimer)
  autoTimer = 0
}

// Liga o store ao login e ao evento ativo (uma vez, a partir do cabeçalho administrativo).
export function bindEven3Context() {
  if (bound) return
  bound = true
  watch(
    [() => eventContext.activeEventId, () => auth.status, () => auth.user?.id],
    ([eventId, status], previous) => {
      const [previousEventId] = previous || []
      if (eventId !== previousEventId || status !== AUTH.AUTHENTICATED) resetEven3()
      if (status === AUTH.AUTHENTICATED && eventId && canSeeEven3()) {
        loadEven3().then(() => autoSyncIfDue())
        if (canSyncEven3()) startAutoSync()
      } else {
        stopAutoSync()
      }
    },
    { immediate: true },
  )
}

onSessionEnd(() => {
  stopAutoSync()
  resetEven3()
})
