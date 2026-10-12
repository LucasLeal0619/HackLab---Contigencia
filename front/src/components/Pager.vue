<script setup>
// Paginação a partir de meta do Laravel (current_page, last_page, total, from, to).
defineProps({
  meta: { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['page'])
</script>

<template>
  <nav v-if="meta.total > 0" class="pager" aria-label="Paginação">
    <span class="pager-info">{{ meta.from ?? 1 }}–{{ meta.to ?? meta.total }} de {{ meta.total }}</span>
    <div v-if="meta.last_page > 1" class="pager-buttons">
      <button class="btn ghost small" type="button" :disabled="disabled || meta.current_page <= 1" @click="emit('page', meta.current_page - 1)">Anterior</button>
      <span class="pager-current">Página {{ meta.current_page }} de {{ meta.last_page }}</span>
      <button class="btn ghost small" type="button" :disabled="disabled || meta.current_page >= meta.last_page" @click="emit('page', meta.current_page + 1)">Próxima</button>
    </div>
  </nav>
</template>
