<script setup>
// Abas entre páginas irmãs do mesmo item de menu (Pessoas e Equipes, Empresas e Desafios, Julgamento,
// Votação e Resultados). Só navegação: cada aba é a página/rota que já existia.
import { computed } from 'vue'
import { sectionTabsFor } from '@/js/config/access'
import { go } from '@/js/router'
import { auth } from '@/js/stores/auth'

const props = defineProps({
  path: { type: String, default: '' },
})
const tabs = computed(() => sectionTabsFor(props.path, auth.user))
</script>

<template>
  <nav v-if="tabs.length" class="tabs section-tabs" aria-label="Seções">
    <button v-for="tab in tabs" :key="tab.id" type="button" class="tab" :class="{ active: tab.active, on: tab.active }" :aria-current="tab.active ? 'page' : undefined" @click="go(tab.id)">
      {{ tab.label }}
    </button>
  </nav>
</template>
