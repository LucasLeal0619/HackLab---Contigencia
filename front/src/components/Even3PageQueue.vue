<script setup>
// Revisão da Even3 dentro da página do assunto (Complemento 11.2). Aparece só para quem pode agir e quando há
// candidato pendente ou ignorado. Lê o resumo do Centro Even3 (já carregado pelo cabeçalho): não sincroniza.
import { computed } from 'vue'
import ReconciliationPanel from './ReconciliationPanel.vue'
import { queueVisible } from '@/js/pages/reconciliation'

const props = defineProps({
  fila: { type: String, required: true },
})
const visible = computed(() => queueVisible(props.fila))
</script>

<template>
  <ReconciliationPanel v-if="visible" :fila="fila" />
</template>
