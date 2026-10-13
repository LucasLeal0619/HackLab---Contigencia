<script setup>
// Formulário "definir senha" a partir do link do e-mail (ativação de conta e redefinição de senha).
import { go } from '@/js/router'
import { useSetPassword } from '@/js/pages/account-recovery'
import AuthFrame from './AuthFrame.vue'
import Field from './Field.vue'
import Icon from './Icon.vue'

const props = defineProps({
  kind: { type: String, required: true }, // 'activation' | 'reset'
  params: { type: Object, default: () => ({}) },
  title: { type: String, required: true },
  lead: { type: String, required: true },
  action: { type: String, required: true },
  doneTitle: { type: String, required: true },
  doneText: { type: String, required: true },
  restart: { type: String, required: true }, // rota para pedir um novo link
})
const { missing, password, confirmation, show, pending, done, error, fieldErrors, submit } = useSetPassword(props.kind, props.params)
</script>

<template>
  <AuthFrame>
    <form class="login-card" novalidate @submit.prevent="submit">
      <template v-if="done">
        <h2>{{ doneTitle }}</h2>
        <p class="banner ok" role="status"><Icon name="check" :size="16" /> <span>{{ doneText }}</span></p>
        <button class="btn full" type="button" @click="go('login')">Ir para o login</button>
      </template>
      <template v-else-if="missing">
        <h2>{{ title }}</h2>
        <p class="banner warn" role="alert"><Icon name="info" :size="16" /> <span>Link incompleto. Abra o link exatamente como chegou no e-mail ou peça um novo.</span></p>
        <button class="btn full" type="button" @click="go(restart)">Pedir um novo link</button>
        <div class="login-links"><p><button type="button" class="linkish" @click="go('login')">Voltar para o login</button></p></div>
      </template>
      <template v-else>
        <h2>{{ title }}</h2>
        <p class="lead">{{ lead }}</p>
        <p v-if="error" class="error" role="alert">
          {{ error }}
          <button type="button" class="linkish" @click="go(restart)">Pedir um novo link</button>
        </p>
        <Field label="Nova senha" hint="Mínimo de 8 caracteres, com letras e números." :error="fieldErrors.password || ''">
          <div class="input-icon">
            <Icon name="lock" :size="16" />
            <input v-model="password" class="input" :type="show ? 'text' : 'password'" autocomplete="new-password" required :disabled="pending" />
            <button type="button" class="eye" :aria-label="show ? 'Ocultar senha' : 'Mostrar senha'" @click="show = !show"><Icon name="eye" :size="16" /></button>
          </div>
        </Field>
        <Field label="Confirmar senha" :error="fieldErrors.password_confirmation || ''">
          <div class="input-icon">
            <Icon name="lock" :size="16" />
            <input v-model="confirmation" class="input" :type="show ? 'text' : 'password'" autocomplete="new-password" required :disabled="pending" />
          </div>
        </Field>
        <button class="btn full" type="submit" :disabled="pending || !password || !confirmation">{{ pending ? 'Salvando…' : action }}</button>
      </template>
    </form>
  </AuthFrame>
</template>
