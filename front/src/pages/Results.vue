<script setup>
import Badge from '../components/Badge.vue'
import Icon from '../components/Icon.vue'
import LoadState from '../components/LoadState.vue'
import Page from '../components/Page.vue'
import ResultSnapshot from '../components/ResultSnapshot.vue'
import { useResults } from '@/js/pages/results'

const { published, current, go, can, formatDateTime } = useResults()
</script>

<template>
  <Page crumbs="HackLab / Evento / Painel de Resultados" title="Painel de Resultados" subtitle="Resultado oficial publicado do Hackathon.">
    <template #actions>
      <button v-if="can('resultados')" class="btn ghost" type="button" @click="go('resultados')">Voltar</button>
      <button class="btn ghost" type="button" :disabled="published.loading.value" @click="published.load()">{{ published.loading.value ? 'Atualizando…' : 'Atualizar' }}</button>
      <button v-if="can('apresentacao')" :class="current ? 'btn' : 'btn ghost'" type="button" @click="go('apresentacao')">Modo Apresentação</button>
    </template>
    <LoadState :loading="published.loading.value" :loaded="published.loaded.value" :error="published.error.value" :empty="!current" @retry="published.load()">
      <template #empty>
        <div class="results-stage">
          <div class="results-wait">
            <div class="results-mark" aria-hidden="true"><Icon name="trophy" :size="32" /></div>
            <h2>Resultados ainda não divulgados</h2>
            <p>Aguarde a publicação oficial dos resultados.</p>
          </div>
        </div>
      </template>
      <template v-if="current">
        <p class="results-meta">
          <Badge tone="ok">Resultado oficial · versão {{ current.version }}</Badge>
          <span class="stat-hint">Publicado em {{ formatDateTime(current.published_at) }}</span>
        </p>
        <ResultSnapshot :result="current.snapshot" />
      </template>
    </LoadState>
  </Page>
</template>
