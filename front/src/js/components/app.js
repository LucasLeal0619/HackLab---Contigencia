// Lógica do componente App.vue: sessão, evento ativo e qual layout/página mostrar.
import { computed, watch } from 'vue'
import { BARE_ROUTES, PUBLIC_ROUTES, canAccess, focusFor, homeFor, legacyTarget } from '@/js/config/access'
import { replace, useRoute } from '@/js/router'
import { AUTH, auth, bootstrap } from '@/js/stores/auth'
import { eventContext, loadEvents } from '@/js/stores/event-context'
import { ui } from '@/js/stores/ui'

// Rotas que funcionam sem evento ativo (cadastro do evento, usuários, auditoria, painel).
const EVENTLESS = new Set(['config', 'usuarios', 'auditoria', 'dashboard'])

let intended = ''

export function useApp() {
  const route = useRoute()
  const path = computed(() => route.value.path)
  const params = computed(() => route.value.params)
  const user = computed(() => auth.user)
  const focus = computed(() => focusFor(user.value?.role?.code))
  const home = computed(() => homeFor(user.value))

  const phase = computed(() => {
    // Primeiro acesso e recuperação de senha: abertos com ou sem sessão, sem esperar /auth/me.
    if (PUBLIC_ROUTES.has(path.value)) return 'public'
    // Na tela de login o formulário aparece na hora; /auth/me segue em segundo plano e, se já houver
    // sessão, o watch abaixo leva para a página inicial.
    if (auth.status === AUTH.UNKNOWN && path.value === 'login') return 'login'
    if (auth.status === AUTH.UNKNOWN) return auth.bootError ? 'boot-error' : 'boot'
    if (auth.status === AUTH.GUEST) return 'login'
    if (eventContext.status === 'idle' || eventContext.status === 'loading') return 'boot'
    if (eventContext.status === 'error') return 'event-error'
    if (!home.value) return 'no-access'
    return 'app'
  })

  const layout = computed(() => {
    if (focus.value) return 'focus'
    if (BARE_ROUTES.has(path.value)) return 'bare'
    return 'shell'
  })

  const needsEvent = computed(() => !EVENTLESS.has(path.value))
  const hasEvent = computed(() => Boolean(eventContext.activeEventId))

  // Sessão confirmada: carrega os eventos visíveis.
  watch(() => auth.status, (status) => {
    if (status === AUTH.AUTHENTICATED && eventContext.status === 'idle') loadEvents()
  }, { immediate: true })

  watch([path, params, () => auth.status, home, () => eventContext.status], () => {
    if (PUBLIC_ROUTES.has(path.value) || auth.status === AUTH.UNKNOWN) return
    if (auth.status === AUTH.GUEST) {
      if (path.value && path.value !== 'login') intended = window.location.hash.replace(/^#\/?/, '')
      if (path.value !== 'login') replace('login')
      return
    }
    const legacy = legacyTarget(path.value, params.value)
    if (legacy) { replace(legacy); return }
    if (!home.value) return
    if (path.value === 'login' || !path.value) {
      const next = intended
      intended = ''
      replace(next && canAccess(auth.user, next.split('?')[0]) ? next : home.value)
      return
    }
    if (!canAccess(auth.user, path.value)) replace(home.value)
  }, { immediate: true })

  function retryBoot() {
    if (auth.status === AUTH.UNKNOWN) bootstrap()
    else loadEvents({ force: true })
  }

  return { path, params, phase, layout, focus, needsEvent, hasEvent, ui, auth, eventContext, retryBoot }
}
