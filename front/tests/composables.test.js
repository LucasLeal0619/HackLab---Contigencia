import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { ApiError } from '@/js/api/errors'
import { useAction, usePaged, useRequest } from '@/js/composables/async'
import { ui } from '@/js/stores/ui'

// Monta um componente só para rodar o composable dentro de um setup().
function withSetup(fn) {
  let result
  mount(defineComponent({ setup() { result = fn(); return () => h('div') } }))
  return result
}

const flush = () => new Promise((resolve) => setTimeout(resolve))

describe('useAction', () => {
  it('bloqueia duplo envio enquanto a primeira chamada não termina', async () => {
    let release
    const action = vi.fn(() => new Promise((resolve) => { release = resolve }))
    const { run, pending } = withSetup(() => useAction(action, { success: 'Salvo.' }))
    const first = run()
    const second = run()
    expect(pending.value).toBe(true)
    expect(action).toHaveBeenCalledTimes(1)
    release({ id: 1 })
    expect(await first).toEqual({ id: 1 })
    expect(await second).toBeUndefined()
    expect(pending.value).toBe(false)
    expect(ui.toast.text).toBe('Salvo.')
  })

  it('422 vira erros por campo e toast de erro', async () => {
    const error = ApiError.fromResponse(422, { message: 'x', errors: { email: ['E-mail em uso.'] } })
    const { run, fieldErrors } = withSetup(() => useAction(() => Promise.reject(error)))
    expect(await run()).toBeUndefined()
    expect(fieldErrors.value).toEqual({ email: 'E-mail em uso.' })
    expect(ui.toast).toMatchObject({ type: 'err', text: 'E-mail em uso.' })
  })
})

describe('useRequest', () => {
  it('só a resposta mais recente vale', async () => {
    const resolvers = []
    const loader = vi.fn(() => new Promise((resolve) => resolvers.push(resolve)))
    const { data, load } = withSetup(() => useRequest(loader, { immediate: false }))
    const a = load()
    const b = load()
    resolvers[1]('nova')
    resolvers[0]('antiga')
    await Promise.all([a, b])
    expect(data.value).toBe('nova')
  })

  it('erro fica disponível para "tentar novamente"', async () => {
    const loader = vi.fn().mockRejectedValueOnce(ApiError.of('network')).mockResolvedValueOnce([1])
    const { data, error, load } = withSetup(() => useRequest(loader))
    await flush()
    expect(error.value.kind).toBe('network')
    await load()
    expect(error.value).toBeNull()
    expect(data.value).toEqual([1])
  })
})

describe('usePaged', () => {
  it('manda filtros na query, ignora vazios e volta para a página 1 ao filtrar', async () => {
    const fetchPage = vi.fn(async (query) => ({ data: [{ id: query.page }], meta: { current_page: query.page, last_page: 3, total: 3 } }))
    const list = withSetup(() => usePaged(fetchPage, { filters: { search: '', status: '' }, perPage: 1, debounce: 0 }))
    await flush()
    expect(fetchPage.mock.calls[0][0]).toEqual({ page: 1, per_page: 1 })
    list.page.value = 3
    await flush()
    expect(fetchPage.mock.lastCall[0].page).toBe(3)
    list.filters.status = 'ACTIVE'
    await nextTick()
    await flush()
    expect(list.page.value).toBe(1)
    expect(fetchPage.mock.lastCall[0]).toEqual({ status: 'ACTIVE', page: 1, per_page: 1 })
    expect(list.activeFilters.value).toBe(1)
    expect(list.items.value).toEqual([{ id: 1 }])
  })
})
