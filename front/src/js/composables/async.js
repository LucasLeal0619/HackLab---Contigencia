// Estados de tela padronizados: carregando, erro (com tentar de novo), vazio, envio sem duplo clique e erros 422.
import { computed, onBeforeUnmount, reactive, ref, shallowRef, watch } from 'vue'
import { emptyPage, toPage } from '@/js/api/pagination'
import { ApiError, errorMessage } from '@/js/api/errors'
import { flash } from '@/js/stores/ui'

// Carrega um recurso. Só a resposta da última chamada vale (troca rápida de evento/filtro não mistura dados).
export function useRequest(loader, { immediate = true, initial = null, watchSources = null } = {}) {
  const data = shallowRef(initial)
  const loading = ref(false)
  const error = shallowRef(null)
  const loaded = ref(false)
  let ticket = 0
  let controller = null

  async function load(...args) {
    const mine = ++ticket
    controller?.abort()
    controller = new AbortController()
    loading.value = true
    error.value = null
    try {
      const result = await loader({ signal: controller.signal }, ...args)
      if (mine !== ticket) return data.value
      data.value = result
      loaded.value = true
      return result
    } catch (caught) {
      if (mine !== ticket || caught?.kind === 'aborted') return data.value
      error.value = caught instanceof ApiError ? caught : ApiError.of('http')
      return data.value
    } finally {
      if (mine === ticket) loading.value = false
    }
  }

  if (watchSources) watch(watchSources, () => load(), { immediate })
  else if (immediate) load()

  onBeforeUnmount(() => {
    ticket += 1
    controller?.abort()
  })

  return { data, loading, error, loaded, load, reload: load }
}

// Listagem paginada com filtros na query string. Mudar filtro volta para a página 1 (busca textual com debounce).
export function usePaged(fetchPage, { filters = {}, debounce = 350, debounced = ['search'], perPage = 25, watchSources = null, immediate = true } = {}) {
  const state = reactive({ ...filters })
  const page = ref(1)
  const result = shallowRef(emptyPage())
  const request = useRequest(async ({ signal }) => toPage(await fetchPage({ ...cleanFilters(state), page: page.value, per_page: perPage }, { signal })), { immediate: false })
  let timer = 0

  function cleanFilters(source) {
    return Object.fromEntries(Object.entries(source).filter(([, value]) => value !== '' && value !== null && value !== undefined && value !== 'all'))
  }

  async function load() {
    const next = await request.load()
    if (next) result.value = next
    return next
  }

  watch(() => ({ ...state }), (now, before) => {
    const onlyText = Object.keys(now).every((key) => now[key] === before?.[key] || debounced.includes(key))
    window.clearTimeout(timer)
    const run = () => {
      if (page.value !== 1) page.value = 1
      else load()
    }
    if (onlyText && debounce) timer = window.setTimeout(run, debounce)
    else run()
  })
  watch(page, () => load())
  if (watchSources) watch(watchSources, () => { page.value = 1; load() }, { immediate })
  else if (immediate) load()

  function reset() {
    Object.keys(state).forEach((key) => { state[key] = filters[key] })
  }

  onBeforeUnmount(() => window.clearTimeout(timer))

  return {
    filters: state,
    page,
    result,
    items: computed(() => result.value.items),
    meta: computed(() => result.value.meta),
    loading: request.loading,
    error: request.error,
    loaded: request.loaded,
    load,
    reset,
    activeFilters: computed(() => Object.keys(filters).filter((key) => state[key] !== filters[key]).length),
  }
}

// Envio de formulário/ação: bloqueia duplo envio, guarda erros por campo (422) e mostra aviso.
export function useAction(action, { success = '', error: errorText = '', toastErrors = true } = {}) {
  const pending = ref(false)
  const fieldErrors = ref({})
  const error = shallowRef(null)

  async function run(...args) {
    if (pending.value) return undefined
    pending.value = true
    fieldErrors.value = {}
    error.value = null
    try {
      const result = await action(...args)
      const message = typeof success === 'function' ? success(result, ...args) : success
      if (message) flash(message)
      return result === undefined ? true : result
    } catch (caught) {
      error.value = caught
      if (caught?.kind === 'validation') fieldErrors.value = caught.fieldErrors
      // 401 já leva ao login pelo client; não repete aviso.
      if (toastErrors && caught?.kind !== 'unauthorized') flash(errorText && caught?.kind !== 'validation' && caught?.kind !== 'conflict' ? errorText : errorMessage(caught), 'err')
      return undefined
    } finally {
      pending.value = false
    }
  }

  function clear() {
    fieldErrors.value = {}
    error.value = null
  }

  return { run, pending, fieldErrors, error, clear }
}
