import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { clearCookies, response } from './helpers'

const TOKEN = 'tok3n-secreto-do-link'

// Servidor falso: registra método, caminho e corpo de cada chamada.
async function setup(handler = () => response(202, { message: 'genérica do backend' })) {
  vi.resetModules()
  clearCookies()
  localStorage.clear()
  const calls = []
  const client = await import('@/js/api/client')
  client.configureClient({
    baseUrl: 'http://api.test',
    fetch: vi.fn(async (url, init) => {
      const path = new URL(url).pathname
      if (path === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=t; path=/'
        return response(204)
      }
      calls.push({ method: init.method, path, body: init.body ? JSON.parse(init.body) : null })
      return handler(path, init)
    }),
  })
  return { calls }
}

async function page(name, props = {}) {
  const component = (await import(`@/pages/${name}.vue`)).default
  return mount(component, { props, attachTo: document.body })
}

describe('Login', () => {
  beforeEach(() => { document.body.innerHTML = '' })

  it('oferece primeiro acesso e redefinição, sem cadastro público nem "procure o Administrador"', async () => {
    await setup()
    const wrapper = await page('Login')
    const text = wrapper.text()
    expect(text).toContain('Primeiro acesso?')
    expect(text).toContain('Ativar minha conta')
    expect(text).toContain('Redefinir senha')
    expect(text).toContain('use no primeiro acesso o mesmo e-mail da inscrição')
    expect(text).not.toMatch(/Criar conta|Cadastre-se|Escolher perfil|procure o Administrador|Contas são criadas pela administração/i)

    await wrapper.findAll('button.linkish').find((b) => b.text() === 'Ativar minha conta').trigger('click')
    expect(window.location.hash).toBe('#/primeiro-acesso')
    await wrapper.findAll('button.linkish').find((b) => b.text() === 'Redefinir senha').trigger('click')
    expect(window.location.hash).toBe('#/esqueci-senha')
  })
})

describe('primeiro acesso e esqueci a senha', () => {
  beforeEach(() => { document.body.innerHTML = '' })

  it('primeiro acesso envia só o e-mail e mostra a resposta genérica', async () => {
    const { calls } = await setup(() => response(202, { message: 'Se este e-mail puder acessar o HackLab, enviaremos as instruções.' }))
    const wrapper = await page('FirstAccess')
    await wrapper.find('input[type=email]').setValue(' pessoa@teste.local ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(calls).toEqual([{ method: 'POST', path: '/api/v1/auth/activation/request', body: { email: 'pessoa@teste.local' } }])
    expect(wrapper.text()).toContain('Se este e-mail puder acessar o HackLab, enviaremos as instruções.')
  })

  it('a mensagem não muda conforme o e-mail existir ou não', async () => {
    const texts = []
    for (const email of ['existe@teste.local', 'nao.existe@teste.local']) {
      await setup(() => response(202, {}))
      const wrapper = await page('FirstAccess')
      await wrapper.find('input[type=email]').setValue(email)
      await wrapper.find('form').trigger('submit')
      await flushPromises()
      texts.push(wrapper.find('.banner').text())
      wrapper.unmount()
    }
    expect(texts[0]).toBe(texts[1])
  })

  it('esqueci a senha usa o endpoint de redefinição', async () => {
    const { calls } = await setup()
    const wrapper = await page('ForgotPassword')
    await wrapper.find('input[type=email]').setValue('conta@teste.local')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(calls[0]).toEqual({ method: 'POST', path: '/api/v1/auth/password/forgot', body: { email: 'conta@teste.local' } })
    expect(wrapper.find('.banner').exists()).toBe(true)
  })
})

describe('definir senha pelo link', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
    window.history.replaceState(null, '', `/#/ativar-conta?token=${TOKEN}`)
  })

  it('ativação: tira o token da barra de endereço, confere a confirmação e conclui', async () => {
    const { calls } = await setup(() => response(200, { message: 'ok' }))
    const wrapper = await page('ActivateAccount', { params: { token: TOKEN } })
    expect(window.location.hash).toBe('#/ativar-conta')

    const [password, confirmation] = wrapper.findAll('input[autocomplete=new-password]')
    await password.setValue('senhaNova123')
    await confirmation.setValue('outra123')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(calls).toEqual([])
    expect(wrapper.text()).toContain('As senhas não conferem.')

    await confirmation.setValue('senhaNova123')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(calls).toEqual([{ method: 'POST', path: '/api/v1/auth/activation/complete', body: { token: TOKEN, password: 'senhaNova123', password_confirmation: 'senhaNova123' } }])
    expect(wrapper.text()).toContain('Acesso ativado. Agora você pode entrar no HackLab.')
    expect(JSON.stringify({ ...localStorage, ...sessionStorage })).not.toContain(TOKEN)
  })

  it('link inválido mostra a mensagem do backend e oferece um novo link', async () => {
    await setup(() => response(422, { message: 'x', errors: { token: ['Link de ativação inválido ou expirado. Peça um novo primeiro acesso.'] } }))
    const wrapper = await page('ActivateAccount', { params: { token: TOKEN } })
    for (const input of wrapper.findAll('input[autocomplete=new-password]')) await input.setValue('senhaNova123')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Link de ativação inválido ou expirado.')
    expect(wrapper.text()).toContain('Pedir um novo link')
  })

  it('senha fraca aparece no campo', async () => {
    await setup(() => response(422, { message: 'x', errors: { password: ['A senha deve ter ao menos 8 caracteres.'] } }))
    const wrapper = await page('ActivateAccount', { params: { token: TOKEN } })
    for (const input of wrapper.findAll('input[autocomplete=new-password]')) await input.setValue('curta1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('.field-error').text()).toBe('A senha deve ter ao menos 8 caracteres.')
  })

  it('redefinição envia e-mail + token do link', async () => {
    window.history.replaceState(null, '', `/#/redefinir-senha?token=${TOKEN}&email=conta%40teste.local`)
    const { calls } = await setup(() => response(200, { message: 'ok' }))
    const wrapper = await page('ResetPassword', { params: { token: TOKEN, email: 'conta@teste.local' } })
    expect(window.location.hash).toBe('#/redefinir-senha')
    expect(wrapper.html()).not.toContain(TOKEN)
    for (const input of wrapper.findAll('input[autocomplete=new-password]')) await input.setValue('senhaNova456')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(calls[0]).toEqual({ method: 'POST', path: '/api/v1/auth/password/reset', body: { email: 'conta@teste.local', token: TOKEN, password: 'senhaNova456', password_confirmation: 'senhaNova456' } })
    expect(wrapper.text()).toContain('Senha redefinida.')
  })

  it('link sem token não chama a API', async () => {
    const { calls } = await setup()
    const wrapper = await page('ActivateAccount', { params: {} })
    expect(wrapper.text()).toContain('Link incompleto')
    expect(calls).toEqual([])
  })
})

describe('rotas públicas no App', () => {
  async function mountApp(hash, meHandler) {
    vi.resetModules()
    await new Promise((resolve) => setTimeout(resolve, 20))
    window.history.replaceState(null, '', hash)
    const client = await import('@/js/api/client')
    client.configureClient({ baseUrl: 'http://api.test', fetch: vi.fn(meHandler) })
    const { bootstrap } = await import('@/js/stores/auth')
    const App = (await import('@/App.vue')).default
    const wrapper = mount(App, { attachTo: document.body })
    return { wrapper, bootstrap }
  }

  beforeEach(() => { document.body.innerHTML = ''; localStorage.clear() })

  it('telas públicas abrem sem sessão (nem esperam /auth/me)', async () => {
    for (const [hash, title] of [['#/primeiro-acesso', 'Primeiro acesso'], ['#/esqueci-senha', 'Esqueci minha senha'], ['#/ativar-conta?token=abc', 'Criar minha senha'], ['#/redefinir-senha?token=abc&email=a%40b.c', 'Redefinir senha']]) {
      const { wrapper, bootstrap } = await mountApp(hash, () => new Promise(() => {}))
      bootstrap()
      await flushPromises()
      expect(wrapper.find('.boot').exists()).toBe(false)
      expect(wrapper.find('h2').text()).toBe(title)
      wrapper.unmount()
    }
  })

  it('rota privada continua exigindo login', async () => {
    const { wrapper, bootstrap } = await mountApp('#/usuarios', async () => response(401, { message: 'Não autenticado.' }))
    await bootstrap()
    await flushPromises()
    expect(window.location.hash).toBe('#/login')
    expect(wrapper.find('form.login-card h2').text()).toBe('Bem-vindo ao HackLab')
  })
})
