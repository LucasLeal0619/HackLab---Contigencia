<script setup>
import logoWhite from '@/assets/senac-logo-white.png'
import { logout } from '@/js/stores/auth'

defineProps({
  error: { type: Boolean, default: false },
  denied: { type: Boolean, default: false },
  title: { type: String, default: '' },
  message: { type: String, default: 'O servidor do HackLab não respondeu. Verifique a conexão e tente novamente.' },
})
const emit = defineEmits(['retry'])
</script>

<template>
  <div class="boot">
    <div class="boot-card" :role="error || denied ? 'alert' : 'status'" aria-live="polite">
      <div class="boot-brand">
        <img class="boot-mark" :src="logoWhite" alt="Senac" />
        <span class="boot-name"><span class="hack">Hack</span><span class="lab">Lab</span></span>
      </div>
      <template v-if="error">
        <h2>{{ title }}</h2>
        <p>{{ message }}</p>
        <button class="btn" type="button" @click="emit('retry')">Tentar novamente</button>
      </template>
      <template v-else-if="denied">
        <h2>Sem acesso às áreas do HackLab</h2>
        <p>Seu perfil não tem nenhuma área liberada. Fale com o Administrador.</p>
        <button class="btn ghost" type="button" @click="logout()">Sair</button>
      </template>
      <template v-else>
        <span class="spinner" aria-hidden="true" />
        <p>Carregando o HackLab…</p>
      </template>
    </div>
  </div>
</template>
