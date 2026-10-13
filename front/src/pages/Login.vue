<script setup>
import { ref } from 'vue'
import { go } from '@/js/router'
import { login } from '@/js/stores/auth'
import { rememberEmail, rememberedEmail } from '@/js/stores/ui'
import AuthFrame from '../components/AuthFrame.vue'
import Field from '../components/Field.vue'
import Icon from '../components/Icon.vue'

const remembered = rememberedEmail()
const email = ref(remembered)
const password = ref('')
const remember = ref(Boolean(remembered))
const show = ref(false)
const error = ref('')
const fieldErrors = ref({})
const pending = ref(false)

async function submit(event) {
  event.preventDefault()
  if (pending.value) return
  pending.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    await login(email.value, password.value)
    rememberEmail(email.value, remember.value)
    password.value = ''
    // O App leva para a página inicial do perfil (ou para a rota que foi pedida antes do login).
  } catch (caught) {
    if (caught.kind === 'validation') {
      // Credencial inválida volta como 422 no campo e-mail: mensagem única, sem dizer qual dado errou.
      fieldErrors.value = caught.status === 422 && caught.errors?.password ? { password: caught.fieldErrors.password } : {}
      error.value = caught.errors?.email ? caught.fieldErrors.email : caught.message
    } else {
      error.value = caught.message
    }
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <AuthFrame>
    <form class="login-card" novalidate @submit="submit">
      <h2>Bem-vindo ao HackLab</h2>
      <p class="lead">Acesse sua conta para acompanhar e gerenciar a organização do Hackathon.</p>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <Field label="E-mail">
        <div class="input-icon">
          <Icon name="mail" :size="16" />
          <input v-model="email" class="input" type="email" autocomplete="username" placeholder="Digite seu e-mail" required :disabled="pending" />
        </div>
      </Field>
      <Field label="Senha" :error="fieldErrors.password || ''">
        <div class="input-icon">
          <Icon name="lock" :size="16" />
          <input v-model="password" class="input" :type="show ? 'text' : 'password'" autocomplete="current-password" placeholder="Digite sua senha" required :disabled="pending" />
          <button type="button" class="eye" :aria-label="show ? 'Ocultar senha' : 'Mostrar senha'" @click="show = !show"><Icon name="eye" :size="16" /></button>
        </div>
      </Field>
      <div class="login-row">
        <label class="check"><input v-model="remember" type="checkbox" /> Lembrar meu e-mail</label>
      </div>
      <button class="btn full" type="submit" :disabled="pending || !email || !password">{{ pending ? 'Entrando…' : 'Entrar' }}</button>
      <div class="login-links">
        <p>Primeiro acesso? <button type="button" class="linkish" @click="go('primeiro-acesso')">Ativar minha conta</button></p>
        <p>Esqueci minha senha? <button type="button" class="linkish" @click="go('esqueci-senha')">Redefinir senha</button></p>
      </div>
      <p class="stat-hint login-note">Se seu acesso estiver habilitado para o evento, use no primeiro acesso o mesmo e-mail da inscrição.</p>
    </form>
  </AuthFrame>
</template>
