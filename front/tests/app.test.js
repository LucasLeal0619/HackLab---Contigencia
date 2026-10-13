import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ADMIN, clearCookies, fakeFetch, response } from './helpers'

async function mountApp(fetch, hash = '#/usuarios') {
  vi.resetModules()
  // Deixa terminar eventos de hash pendentes do teste anterior antes de definir a rota inicial.
  await new Promise((resolve) => setTimeout(resolve, 20))
  window.history.replaceState(null, '', hash)
  await new Promise((resolve) => setTimeout(resolve, 20))
  const client = await import('@/js/api/client')
  client.configureClient({ baseUrl: 'http://api.test', fetch })
  const { bootstrap } = await import('@/js/stores/auth')
  const App = (await import('@/App.vue')).default
  const wrapper = mount(App, { attachTo: document.body })
  return { wrapper, bootstrap }
}

describe('App', () => {
  beforeEach(() => {
    clearCookies()
    localStorage.clear()
    document.body.innerHTML = ''
  })

  it('mostra carregamento enquanto /auth/me não responde (sem flash de tela autenticada)', async () => {
    const { wrapper, bootstrap } = await mountApp(vi.fn(() => new Promise(() => {})))
    bootstrap()
    await flushPromises()
    expect(wrapper.find('.boot').exists()).toBe(true)
    expect(wrapper.find('.shell').exists()).toBe(false)
    expect(wrapper.find('form.login-card').exists()).toBe(false)
  })

  it('backend fora do ar → erro de conexão com "tentar novamente", nunca login demo', async () => {
    const { wrapper, bootstrap } = await mountApp(fakeFetch(new TypeError('Failed to fetch')))
    await bootstrap()
    await flushPromises()
    expect(wrapper.text()).toContain('Não foi possível conectar ao HackLab')
    expect(wrapper.find('button').text()).toContain('Tentar novamente')
    expect(wrapper.find('.shell').exists()).toBe(false)
    expect(wrapper.text()).not.toMatch(/demonstra/i)
  })

  it('sem sessão → tela de login real, sem contas demo nem cadastro público', async () => {
    const { wrapper, bootstrap } = await mountApp(fakeFetch(response(401, { message: 'Não autenticado.' })))
    await bootstrap()
    await flushPromises()
    expect(wrapper.find('form.login-card').exists()).toBe(true)
    expect(window.location.hash).toBe('#/login')
    expect(wrapper.text()).not.toMatch(/demonstra|Criar cadastro|qualquer senha/i)
  })

  it('#/login mostra o formulário na hora, sem esperar /auth/me', async () => {
    const { wrapper, bootstrap } = await mountApp(vi.fn(() => new Promise(() => {})), '#/login')
    bootstrap()
    await flushPromises()
    expect(wrapper.find('form.login-card').exists()).toBe(true)
    expect(wrapper.find('.boot').exists()).toBe(false)
  })

  it('sessão válida → carrega eventos e abre o Shell na rota pedida', async () => {
    const fetch = fakeFetch((url) => {
      if (url.includes('/auth/me')) return response(200, { data: ADMIN })
      if (url.includes('/api/v1/events?')) return response(200, { data: [{ id: 1, name: 'Evento', status: 'PLANNED', days: [] }], meta: { current_page: 1, last_page: 1, total: 1 } })
      return response(200, { data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    })
    const { wrapper, bootstrap } = await mountApp(fetch)
    await bootstrap()
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 50))
    await flushPromises()
    expect(wrapper.find('.shell').exists()).toBe(true)
    expect(window.location.hash).toBe('#/usuarios')
    expect(wrapper.find('.user-btn').text()).toContain('Administrador HackLab')
  })
})
