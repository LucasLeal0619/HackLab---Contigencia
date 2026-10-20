<script setup>
import Badge from './Badge.vue'
import Icon from './Icon.vue'
import { useEven3Center } from '@/js/components/even3-center'
import { options } from '@/js/labels'

const { root, open, scope, visible, canSync, canManage, busy, state, canRunSync, rows, history, seenNews, toggle, navigate, runSync, reload, even3, formatDateTime, label, tone } = useEven3Center()
</script>

<template>
  <div v-if="visible" ref="root" class="tool even3-center">
    <button class="even3-btn" :class="`is-${state.tone}`" type="button" :aria-expanded="open" aria-haspopup="dialog" @click="toggle">
      <Icon name="refresh" :size="16" :class="{ spinning: busy }" />
      <span class="even3-btn-label">{{ canSync ? 'Sincronizar Even3' : 'Even3' }}</span>
      <span class="even3-btn-state"><span class="even3-dot" aria-hidden="true" />{{ state.text }}</span>
    </button>

    <div v-if="open" class="popover even3-panel" role="dialog" aria-label="Centro Even3">
      <div class="row-between">
        <h3>Centro Even3</h3>
        <button class="icon-btn" type="button" aria-label="Fechar" title="Fechar" @click="open = false"><Icon name="x" :size="16" /></button>
      </div>

      <div v-if="even3.error && !even3.loaded" class="banner err even3-error" role="alert">
        <span>Não foi possível consultar o estado da integração.</span>
        <button class="btn ghost small" type="button" :disabled="even3.loading" @click="reload()">Tentar novamente</button>
      </div>

      <template v-else-if="even3.loaded && !even3.configured">
        <p class="stat-hint">Even3 não configurada para este evento.</p>
        <button v-if="canManage" class="btn" type="button" @click="navigate('integracao')">Configurar integração</button>
      </template>

      <template v-else-if="even3.loaded">
        <p class="even3-status">
          <b>{{ even3.integrationStatus === 'ACTIVE' ? 'Integração ativa' : 'Integração desativada' }}</b>
          <span class="stat-hint">Última sincronização: {{ even3.lastSyncAt ? formatDateTime(even3.lastSyncAt) : 'nunca' }}<template v-if="even3.lastSyncStatus"> · {{ label('syncStatus', even3.lastSyncStatus) }}</template></span>
        </p>

        <template v-if="canSync">
          <button class="btn even3-sync" type="button" :disabled="!canRunSync" @click="runSync">
            {{ busy ? 'Sincronizando…' : 'Sincronizar tudo' }}
          </button>
          <p v-if="even3.running && !even3.syncing" class="stat-hint">Sincronização já em andamento.</p>
          <p v-if="even3.integrationStatus !== 'ACTIVE'" class="stat-hint">Ative a integração para sincronizar.</p>
          <details class="even3-advanced">
            <summary>Opções avançadas</summary>
            <label class="stat-hint" for="even3-scope">Escopo</label>
            <select id="even3-scope" v-model="scope" class="input" :disabled="busy">
              <option v-for="[code, name] in options('syncScope')" :key="code" :value="code">{{ code === 'ALL' ? 'Tudo' : name }}</option>
            </select>
          </details>
        </template>
        <p v-if="even3.syncError" class="banner warn" role="alert">{{ even3.syncError.message }}</p>
        <p v-if="canSync" class="stat-hint">Sincroniza sozinho a cada 10 minutos enquanto você usa o HackLab.</p>

        <div v-if="seenNews" class="even3-news" role="status">
          <h4 class="even3-subtitle">Novidades da última sincronização</h4>
          <ul><li v-for="item in seenNews.items" :key="item.key"><b>{{ item.count }}</b> {{ item.text }}</li></ul>
        </div>

        <h4 class="even3-subtitle">Pendências</h4>
        <ul class="even3-queues">
          <li v-for="row in rows" :key="row.key">
            <button type="button" :class="{ zero: !row.value }" @click="navigate(row.to)">
              <span>{{ row.label }}<small v-if="row.ignored" class="stat-hint"> · {{ row.ignored }} ignorado(s)</small></span>
              <b>{{ row.value }}</b>
            </button>
          </li>
        </ul>
        <p v-if="even3.unmappedTickets" class="stat-hint even3-unmapped">
          {{ even3.unmappedTickets }} entrada(s) sem mapeamento ficam fora das filas.
          <button v-if="canManage" class="link-btn" type="button" @click="navigate('entradas')">Mapear</button>
        </p>
      </template>

      <p v-else class="stat-hint">Carregando…</p>

      <template v-if="even3.loaded && even3.configured">
        <h4 class="even3-subtitle">Últimas sincronizações</h4>
        <p v-if="history.loading.value && !history.data.value.length" class="stat-hint">Carregando…</p>
        <p v-else-if="!history.data.value.length" class="stat-hint">Nenhuma sincronização ainda.</p>
        <ul v-else class="even3-history">
          <li v-for="run in history.data.value" :key="run.id">
            <span>{{ formatDateTime(run.started_at) }}<small class="stat-hint"> · {{ label('syncScope', run.scope) }}<template v-if="run.counters?.participants_created"> · {{ run.counters.participants_created }} participante(s) novo(s)</template></small></span>
            <Badge :tone="tone('syncStatus', run.status)">{{ label('syncStatus', run.status) }}</Badge>
          </li>
        </ul>
      </template>

      <div class="even3-links">
        <button class="btn ghost small" type="button" @click="navigate('integracao')">Abrir Inscrições e Presença</button>
      </div>
    </div>
  </div>
</template>
