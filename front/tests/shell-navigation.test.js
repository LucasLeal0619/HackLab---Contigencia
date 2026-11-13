import { createApp, h } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { auth } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { useShell } from '@/js/components/shell'

function mountShell(matches) {
  const listeners = new Set()
  const mediaQuery = {
    matches,
    addEventListener: vi.fn((_, listener) => listeners.add(listener)),
    removeEventListener: vi.fn((_, listener) => listeners.delete(listener)),
  }
  vi.stubGlobal('matchMedia', vi.fn(() => mediaQuery))

  let state
  const app = createApp({
    setup() {
      state = useShell({ path: 'setores' })
      return () => h('div')
    },
  })
  app.mount(document.createElement('div'))

  return { app, listeners, mediaQuery, state }
}

afterEach(() => {
  auth.permissions = []
  eventContext.activeEventId = null
  vi.unstubAllGlobals()
})

describe('controle da navegação lateral', () => {
  it('permite recolher e reabrir o menu em telas largas', () => {
    const { app, state } = mountShell(false)

    expect(state.open.value).toBe(true)
    state.toggleNavigation()
    expect(state.open.value).toBe(false)
    state.toggleNavigation()
    expect(state.open.value).toBe(true)

    app.unmount()
  })

  it('abre como drawer em telas estreitas e responde ao redimensionamento', () => {
    const { app, listeners, state } = mountShell(true)

    expect(state.open.value).toBe(false)
    state.toggleNavigation()
    expect(state.open.value).toBe(true)
    listeners.forEach((listener) => listener({ matches: false }))
    expect(state.isNarrow.value).toBe(false)
    expect(state.open.value).toBe(true)

    app.unmount()
  })
})
