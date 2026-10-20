<script setup>
import Icon from './Icon.vue'
import Even3Result from './Even3Result.vue'
import { useEven3Center } from '@/js/components/even3-center'

const { root, open, visible, canSync, busy, attention, state, canRunSync, toggle, navigate, runSync, reload, even3 } = useEven3Center()
</script>

<template>
  <div v-if="visible" ref="root" class="tool even3-center">
    <button class="even3-btn" :class="`is-${state.tone}`" type="button" :aria-expanded="open" aria-haspopup="dialog" @click="toggle">
      <span class="even3-name">Even3</span>
      <span class="even3-btn-state"><span class="even3-dot" aria-hidden="true" />{{ state.text }}</span>
      <span v-if="attention" class="even3-attention" :title="`${attention} item(ns) precisam de atenção`">⚠ {{ attention }}</span>
    </button>
    <button v-if="canSync && even3.configured" class="icon-btn even3-sync-btn" type="button" :disabled="!canRunSync" :aria-label="busy ? 'Sincronizando…' : 'Sincronizar agora'" :title="busy ? 'Sincronizando…' : 'Sincronizar agora'" @click="runSync">
      <Icon name="refresh" :size="16" :class="{ spinning: busy }" />
    </button>

    <div v-if="open" class="popover even3-panel" role="dialog" aria-label="Even3">
      <div v-if="even3.error && !even3.loaded" class="banner err even3-error" role="alert">
        <span>Não foi possível consultar a Even3 no HackLab.</span>
        <button class="btn ghost small" type="button" :disabled="even3.loading" @click="reload()">Tentar novamente</button>
      </div>
      <template v-else-if="even3.loaded && !even3.configured">
        <p class="stat-hint">Even3 não configurada para este evento.</p>
        <button v-if="canSync" class="btn small" type="button" @click="navigate('presenca?config=1')">Configurar</button>
      </template>
      <template v-else-if="even3.loaded">
        <p class="even3-status"><b>{{ state.text }}</b></p>
        <Even3Result v-if="even3.lastResult" :result="even3.lastResult" />
        <button v-if="attention" class="even3-attention-link" type="button" @click="navigate('presenca')">⚠ {{ attention }} item(ns) precisam de atenção</button>
        <div class="even3-links">
          <button class="btn ghost small" type="button" @click="navigate('presenca')">Abrir Presença</button>
          <button class="btn ghost small" type="button" @click="navigate('presenca?config=1')">⚙ Configuração</button>
        </div>
      </template>
      <p v-else class="stat-hint">Carregando…</p>
    </div>
  </div>
</template>
