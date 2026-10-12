// Preferências de interface e aviso (toast). Única chave no navegador: `hacklab.ui` (nenhum dado de negócio).
import { reactive, watch } from 'vue'

export const UI_KEY = 'hacklab.ui'
export const REMEMBER_KEY = 'hacklab.remember'
const LEGACY_KEYS = ['hacklab', 'hacklab.vote.cast']

export function defaultA11y() {
  return { scale: 100, contrast: false, focus: false, motion: false, spacing: 'padrao' }
}

function sanitizeA11y(value) {
  const base = defaultA11y()
  if (!value || typeof value !== 'object') return base
  const scale = Number(value.scale)
  return {
    scale: Number.isFinite(scale) ? Math.min(140, Math.max(90, scale)) : base.scale,
    contrast: Boolean(value.contrast),
    focus: Boolean(value.focus),
    motion: Boolean(value.motion),
    spacing: ['padrao', 'confortavel', 'amplo'].includes(value.spacing) ? value.spacing : base.spacing,
  }
}

function readJson(key) {
  try {
    const raw = localStorage.getItem(key)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

// Limpeza única do protótipo: só as preferências de acessibilidade do estado antigo são aproveitadas.
export function migrateLegacyStorage() {
  let prefs = readJson(UI_KEY)
  try {
    if (localStorage.getItem('hacklab') !== null) {
      const legacy = readJson('hacklab')
      if (!prefs && legacy?.a11y) prefs = { a11y: sanitizeA11y(legacy.a11y) }
    }
    LEGACY_KEYS.forEach((key) => localStorage.removeItem(key))
    if (prefs) localStorage.setItem(UI_KEY, JSON.stringify(cleanPrefs(prefs)))
  } catch {
    // Navegador sem storage: segue só com o padrão em memória.
  }
  return cleanPrefs(prefs || {})
}

function cleanPrefs(prefs) {
  const id = Number(prefs?.activeEventId)
  return { a11y: sanitizeA11y(prefs?.a11y), activeEventId: Number.isInteger(id) && id > 0 ? id : null }
}

const loaded = migrateLegacyStorage()

export const ui = reactive({
  a11y: loaded.a11y,
  activeEventId: loaded.activeEventId,
  toast: null,
})

let toastTimer = 0

export function flash(text, type = 'ok') {
  ui.toast = { text, type, id: Date.now() }
  window.clearTimeout(toastTimer)
  toastTimer = window.setTimeout(() => { ui.toast = null }, type === 'err' ? 5000 : 3200)
}

export function setA11y(partial) {
  ui.a11y = sanitizeA11y({ ...ui.a11y, ...partial })
}

export function resetA11y() {
  ui.a11y = defaultA11y()
  flash('Acessibilidade restaurada ao padrão.')
}

export function setActiveEventPreference(id) {
  ui.activeEventId = id || null
}

function persist() {
  try {
    localStorage.setItem(UI_KEY, JSON.stringify({ a11y: ui.a11y, activeEventId: ui.activeEventId }))
  } catch {
    // Sem storage disponível: a preferência vale só nesta aba.
  }
}

export function rememberedEmail() {
  try {
    return localStorage.getItem(REMEMBER_KEY) || ''
  } catch {
    return ''
  }
}

export function rememberEmail(email, remember) {
  try {
    if (remember && email) localStorage.setItem(REMEMBER_KEY, email.trim().toLowerCase())
    else localStorage.removeItem(REMEMBER_KEY)
  } catch {
    // Ignora: lembrar e-mail é só conveniência.
  }
}

let bound = false

// Liga a persistência e aplica as preferências no documento (chamado uma vez no main.js).
export function bindUi() {
  if (bound) return
  bound = true
  watch(() => [ui.a11y, ui.activeEventId], persist, { deep: true })
  watch(() => ui.a11y, (a11y) => {
    const root = document.documentElement
    document.body.style.zoom = `${a11y.scale}%`
    root.classList.toggle('contrast', a11y.contrast)
    root.classList.toggle('focus-strong', a11y.focus)
    root.classList.toggle('reduce-motion', a11y.motion)
    root.dataset.spacing = a11y.spacing
  }, { deep: true, immediate: true })
}
