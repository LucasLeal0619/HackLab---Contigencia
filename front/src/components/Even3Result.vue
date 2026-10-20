<script setup>
// Resultado resumido da última sincronização (sem detalhes técnicos).
defineProps({
  result: { type: Object, required: true },
})
</script>

<template>
  <div class="even3-result" :class="{ failed: result.status === 'FAILED' }" role="status">
    <b>{{ result.status === 'FAILED' ? '✕ A sincronização falhou' : '✓ Sincronização concluída' }}</b>
    <ul v-if="result.lines.length">
      <li v-for="line in result.lines" :key="line.text"><b>{{ line.count }}</b> {{ line.text }}</li>
    </ul>
    <p v-else-if="result.status !== 'FAILED'" class="stat-hint">Nada mudou na Even3.</p>
    <p v-if="result.attention" class="even3-result-warn">⚠ {{ result.attention }} item(ns) precisam de atenção</p>
  </div>
</template>
