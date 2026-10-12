<script setup>
import { ref } from 'vue'
import { login } from '@/js/stores/auth'
import { rememberEmail, rememberedEmail } from '@/js/stores/ui'
import Field from '../components/Field.vue'
import Icon from '../components/Icon.vue'
import Logo from '../components/Logo.vue'
import Modal from '../components/Modal.vue'

const remembered = rememberedEmail()
const email = ref(remembered)
const password = ref('')
const remember = ref(Boolean(remembered))
const show = ref(false)
const error = ref('')
const fieldErrors = ref({})
const pending = ref(false)
const forgot = ref(false)
const a11y = ref(false)

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
  <div class="login">
    <section class="login-side">
      <Logo />
      <div class="login-copy">
        <div class="bar" />
        <h1>Organização do Hackathon em um só lugar.</h1>
        <p>Planejamento, acompanhamento durante o evento e consulta de resultados e presença.</p>
      </div>
      <div class="login-foot">Hackathon Senac · Plataforma de gestão</div>
    </section>
    <section class="login-main">
      <div class="login-tools">
        <button class="a11y-btn" type="button" @click="a11y = true"><Icon name="access" :size="16" /> Acessibilidade</button>
      </div>
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
          <button type="button" class="linkish" @click="forgot = true">Esqueci minha senha</button>
        </div>
        <button class="btn full" type="submit" :disabled="pending || !email || !password">{{ pending ? 'Entrando…' : 'Entrar' }}</button>
        <p class="stat-hint login-note">Contas são criadas pela administração do HackLab.</p>
      </form>
    </section>
    <Modal v-if="forgot" title="Esqueci minha senha" @close="forgot = false">
      <p>A redefinição de senha é feita pela administração do HackLab. Procure o Administrador do evento para gerar uma nova senha.</p>
      <template #footer><button class="btn" type="button" @click="forgot = false">Entendi</button></template>
    </Modal>
    <Modal v-if="a11y" title="Acessibilidade" subtitle="As preferências completas ficam disponíveis depois do login." @close="a11y = false">
      <p>Alto contraste, tamanho da fonte, foco reforçado, redução de animações e espaçamento podem ser ajustados no topo de qualquer tela depois de entrar.</p>
      <template #footer><button class="btn" type="button" @click="a11y = false">Fechar</button></template>
    </Modal>
  </div>
</template>
