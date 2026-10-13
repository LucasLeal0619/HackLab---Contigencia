// Primeiro acesso e recuperação de senha (telas públicas). Toda a decisão é do backend; aqui só o formulário.
// Tokens vêm do link do e-mail, ficam só em memória e saem da barra de endereço assim que a tela abre.
import { ref } from 'vue'
import { completeActivation, forgotPassword, requestActivation, resetPassword } from '@/js/services/auth'

// Mensagens iguais às do backend (exibidas mesmo se a resposta não trouxer texto).
export const GENERIC = {
  activation: 'Se este e-mail puder acessar o HackLab, enviaremos as instruções.',
  reset: 'Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha.',
}

const REQUESTS = { activation: requestActivation, reset: forgotPassword }

export function useRequestLink(kind) {
  const email = ref('')
  const pending = ref(false)
  const sent = ref('')
  const error = ref('')
  const fieldError = ref('')

  async function submit() {
    if (pending.value || !email.value.trim()) return
    pending.value = true
    error.value = ''
    fieldError.value = ''
    try {
      const payload = await REQUESTS[kind](email.value.trim())
      // Resposta sempre genérica: não muda conforme o e-mail existir ou não.
      sent.value = payload?.message || GENERIC[kind]
    } catch (caught) {
      if (caught.kind === 'validation') fieldError.value = caught.fieldErrors.email || caught.message
      else error.value = caught.message
    } finally {
      pending.value = false
    }
  }

  function again() {
    sent.value = ''
  }

  return { email, pending, sent, error, fieldError, submit, again }
}

// Lê os dados do link e limpa a barra de endereço (o token não fica no histórico nem no armazenamento).
function takeFromLink(params, route, keys) {
  const values = Object.fromEntries(keys.map((key) => [key, String(params?.[key] || '')]))
  if (keys.some((key) => params?.[key])) {
    window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#/${route}`)
  }
  return values
}

export function useSetPassword(kind, params) {
  const link = kind === 'activation'
    ? takeFromLink(params, 'ativar-conta', ['token'])
    : takeFromLink(params, 'redefinir-senha', ['token', 'email'])
  const missing = !link.token || (kind === 'reset' && !link.email)
  const password = ref('')
  const confirmation = ref('')
  const show = ref(false)
  const pending = ref(false)
  const done = ref(false)
  const error = ref('')
  const fieldErrors = ref({})

  async function submit() {
    if (pending.value || missing) return
    error.value = ''
    fieldErrors.value = {}
    if (password.value !== confirmation.value) {
      fieldErrors.value = { password_confirmation: 'As senhas não conferem.' }
      return
    }
    pending.value = true
    try {
      if (kind === 'activation') await completeActivation(link.token, password.value, confirmation.value)
      else await resetPassword(link.email, link.token, password.value, confirmation.value)
      password.value = ''
      confirmation.value = ''
      done.value = true
    } catch (caught) {
      const fields = caught.fieldErrors || {}
      if (caught.kind === 'validation' && fields.password) fieldErrors.value = { password: fields.password }
      else error.value = caught.message
    } finally {
      pending.value = false
    }
  }

  return { missing, email: link.email, password, confirmation, show, pending, done, error, fieldErrors, submit }
}
