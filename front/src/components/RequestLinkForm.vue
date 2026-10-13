<script setup>
// Formulário "pedir link por e-mail" (primeiro acesso e esqueci a senha). A resposta é sempre genérica.
import { go } from '@/js/router'
import { useRequestLink } from '@/js/pages/account-recovery'
import AuthFrame from './AuthFrame.vue'
import Field from './Field.vue'
import Icon from './Icon.vue'

const props = defineProps({
  kind: { type: String, required: true }, // 'activation' | 'reset'
  title: { type: String, required: true },
  lead: { type: String, required: true },
  action: { type: String, required: true },
})
const { email, pending, sent, error, fieldError, submit, again } = useRequestLink(props.kind)
</script>

<template>
  <AuthFrame>
    <form class="login-card" novalidate @submit.prevent="submit">
      <h2>{{ title }}</h2>
      <p class="lead">{{ lead }}</p>
      <template v-if="sent">
        <p class="banner ok" role="status"><Icon name="mail" :size="16" /> <span>{{ sent }}</span></p>
        <p class="stat-hint">Confira também a caixa de spam. O link vale por 60 minutos.</p>
        <button class="btn full" type="button" @click="go('login')">Voltar para o login</button>
        <div class="login-links"><p><button type="button" class="linkish" @click="again">Enviar para outro e-mail</button></p></div>
      </template>
      <template v-else>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <Field label="E-mail" :error="fieldError">
          <div class="input-icon">
            <Icon name="mail" :size="16" />
            <input v-model="email" class="input" type="email" autocomplete="email" placeholder="Digite seu e-mail" required :disabled="pending" />
          </div>
        </Field>
        <button class="btn full" type="submit" :disabled="pending || !email.trim()">{{ pending ? 'Enviando…' : action }}</button>
        <div class="login-links">
          <p><button type="button" class="linkish" @click="go('login')">Voltar para o login</button></p>
          <slot name="links" />
        </div>
      </template>
    </form>
  </AuthFrame>
</template>
