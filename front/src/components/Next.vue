<script setup>
import { go } from '@/js/router'

defineProps({
  label: { type: String, default: '' },
  to: { type: String, default: '' },
})
</script>

<template>
  <div v-if="label" class="nextbar">
    <button class="btn ghost" type="button" @click="go(to)">Próxima etapa: {{ label }}</button>
  </div>
</template>
