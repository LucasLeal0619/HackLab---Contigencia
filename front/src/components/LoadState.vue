<script setup>
// Estados de uma área que depende da API: carregando, erro (com tentar de novo), sem permissão e vazio.
// Com dados, mostra o slot padrão (e mantém os dados visíveis durante recargas).
import { computed } from 'vue'
import Empty from './Empty.vue'
import Icon from './Icon.vue'

const props = defineProps({
  loading: { type: Boolean, default: false },
  error: { type: Object, default: null },
  empty: { type: Boolean, default: false },
  loaded: { type: Boolean, default: true },
  emptyTitle: { type: String, default: 'Nenhum registro encontrado' },
  emptyText: { type: String, default: '' },
  loadingText: { type: String, default: 'Carregando…' },
})
const emit = defineEmits(['retry'])

const forbidden = computed(() => props.error?.kind === 'forbidden')
</script>

<template>
  <div v-if="error" class="load-state banner" :class="forbidden ? 'warn' : 'err'" role="alert">
    <Icon name="info" :size="16" />
    <div class="load-state-body">
      <strong>{{ forbidden ? 'Sem permissão' : 'Não foi possível carregar' }}</strong>
      <span>{{ error.message }}</span>
    </div>
    <button v-if="!forbidden" class="btn ghost small" type="button" :disabled="loading" @click="emit('retry')">{{ loading ? 'Tentando…' : 'Tentar novamente' }}</button>
  </div>
  <div v-else-if="loading && !loaded" class="load-state loading" role="status" aria-live="polite">
    <span class="spinner" aria-hidden="true" /> {{ loadingText }}
  </div>
  <template v-else-if="empty">
    <slot name="empty">
      <Empty :title="emptyTitle" :text="emptyText"><template v-if="$slots['empty-action']" #action><slot name="empty-action" /></template></Empty>
    </slot>
  </template>
  <div v-else class="load-state-content" :class="{ refreshing: loading }" :aria-busy="loading ? 'true' : undefined"><slot /></div>
</template>
