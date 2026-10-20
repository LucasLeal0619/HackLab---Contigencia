// Centro Even3: estado da integração do evento ativo, compartilhado pelo cabeçalho e pelas páginas.
// Sincronizar = endpoint central (POST .../sync). Revisar = decisões sobre dados já sincronizados.
// Sincronização automática (decisão de 20/10/2026): com um Administrador usando o sistema, o próprio front
// dispara a sincronização central quando a última tem mais de 10 minutos (verifica a cada minuto). As novidades
// da última sincronização aparecem no botão do topo até o painel ser aberto.
// Sem polling do resumo: carrega ao entrar, ao trocar de evento, depois de sincronizar/reconciliar e ao abrir
// o painel se estiver desatualizado. Com uma sincronização de outra origem em andamento, consulta em intervalo
// curto só até ela terminar.
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
    lastErrorCode: null,
    running: false,
    pending: null,
    ignored: null,
    unmappedTickets: 0,
    loadedAt: 0,
  }
}

export const even3Context = reactive({
  ...emptyState(),
  // Sincronização disparada por este navegador.
  syncing: false,
  syncError: null,
  // Novidades da última sincronização (inscrições, participantes, jurados, evento) ainda não vistas.
  news: null,
  // Muda depois de cada sincronização ou reconciliação: páginas usam para recarregar dados locais.
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
    lastErrorCode: data?.sync?.last_error_code ?? null,
    running: Boolean(data?.sync?.running),
    pending: data?.pending ?? null,
    ignored: data?.ignored ?? null,
    unmappedTickets: data?.unmapped_tickets ?? 0,
  })
}

function stopPolling() {
  window.clearTimeout(pollTimer)
  pollTimer = 0
}

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

// Troca de evento ou fim de sessão: nada do evento anterior pode aparecer.
export function resetEven3() {
  controller?.abort()
  controller = null
  stopPolling()
  pollStarted = 0
  Object.assign(even3Context, emptyState(), { syncing: false, syncError: null, news: null })
}

// O que a última sincronização trouxe de novo (só mudanças reais, pelos contadores do histórico).
export function newsFrom(run) {
  const c = run?.counters || {}
  const items = [
    ['attendees_created', 'inscrição(ões) nova(s)'],
    ['attendees_updated', 'inscrição(ões) atualizada(s)'],
    ['participants_created', 'participante(s) novo(s)'],
    ['jurors_created', 'jurado(s) novo(s)'],
    ['event_fields_applied', 'dado(s) do evento atualizado(s)'],
  ].filter(([key]) => Number(c[key] || 0) > 0).map(([key, text]) => ({ key, count: Number(c[key]), text }))
  return items.length ? { total: items.reduce((sum, item) => sum + item.count, 0), items, at: run?.finished_at || null } : null
}

export function markNewsSeen() {
  even3Context.news = null
}

// Hora de sincronizar sozinho? Só Administrador (integrations.sync), integração ativa, nada em andamento.
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
    // Silencioso: o botão do topo mostra o estado (409 = outro já está sincronizando).
    return null
  }
}

function startAutoSync() {
  if (autoTimer) return
  autoTimer = window.setInterval(() => { autoSyncIfDue() }, AUTO_CHECK_MS)
}

function stopAutoSync() {
  window.clearInterval(autoTimer)
  autoTimer = 0
}

// Depois de uma reconciliação (ou outra mudança local relevante): recarrega o resumo e avisa as páginas.
export function even3Changed() {
  even3Context.revision += 1
  return loadEven3()
}

// Único disparo da sincronização no front (botão do topo ou automático). Usa o endpoint central;
// 409 = já existe uma em andamento.
export async function syncEven3(scope = 'ALL', { automatic = false } = {}) {
  const eventId = eventContext.activeEventId
  if (!eventId || even3Context.syncing) return null
  even3Context.syncing = true
  even3Context.syncError = null
  stopPolling()
  try {
    const run = unwrap(await integrationsApi.syncEven3(eventId, automatic ? { scope, automatic: true } : { scope }))
    if (eventId === eventContext.activeEventId) even3Context.news = newsFrom(run) || even3Context.news
    return run
  } catch (error) {
    even3Context.syncError = error?.kind === 'conflict' ? { ...error, message: 'Sincronização já em andamento.' } : error
    throw even3Context.syncError
  } finally {
    even3Context.syncing = false
    if (eventId === eventContext.activeEventId) {
      even3Context.revision += 1
      loadEven3()
    }
  }
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
        // Ao entrar (ou trocar de evento), já sincroniza se a última tiver mais de 10 minutos.
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
