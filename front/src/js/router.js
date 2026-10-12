// Roteamento por hash (#/rota?param=valor). Sem Vue Router: a rota é só um ref reativo.
import { ref } from 'vue'

export function parseHash(hash = window.location.hash) {
  const raw = (hash || '').replace(/^#\/?/, '')
  const [path, qs] = raw.split('?')
  const params = Object.fromEntries(new URLSearchParams(qs || ''))
  return { path: path || '', params }
}

export function go(to) {
  const path = to.startsWith('/') ? to : `/${to}`
  if (`#${path}` === window.location.hash) {
    window.dispatchEvent(new HashChangeEvent('hashchange'))
  } else {
    window.location.hash = path
  }
}

// Troca a rota sem criar entrada no histórico (redirecionamentos de acesso).
export function replace(to) {
  const path = to.startsWith('/') ? to : `/${to}`
  window.location.replace(`#${path}`)
  route.value = parseHash(`#${path}`)
}

const route = ref(parseHash())
let bound = false

export function useRoute() {
  if (!bound) {
    bound = true
    window.addEventListener('hashchange', () => { route.value = parseHash() })
  }
  return route
}
