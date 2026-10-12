<script setup>
import { go } from '@/js/router'
import Logo from './Logo.vue'

defineProps({
  title: { type: String, default: '' },
  exitTo: { type: String, default: 'dashboard' },
})
</script>

<template>
  <div class="focus-app">
    <header class="focus-bar">
      <Logo light />
      <strong>{{ title }}</strong>
      <button class="btn ghost" type="button" @click="go(exitTo)">Voltar</button>
    </header>
    <main><slot /></main>
  </div>
</template>
