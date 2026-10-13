<script setup>
import Badge from '../components/Badge.vue'
import DashboardDomain from '../components/DashboardDomain.vue'
import Empty from '../components/Empty.vue'
import LoadState from '../components/LoadState.vue'
import Page from '../components/Page.vue'
import { useDashboard } from '@/js/pages/dashboard'

const { summary, blocks, greeting, activeEvent, activeDays, sector, dayLabel, formatDate, label, tone } = useDashboard()
</script>

<template>
  <Page title="Dashboard" :subtitle="greeting ? `Olá, ${greeting}.${sector ? ` Setor ${sector}.` : ''}` : ''">
    <Empty v-if="!activeEvent" title="Nenhum evento disponível" text="Quando houver um evento cadastrado, o resumo aparece aqui." />
    <div v-else class="dashboard-cockpit">
      <section class="card dash-block" aria-labelledby="dash-event">
        <div class="dash-block-head">
          <h2 id="dash-event" class="dash-block-title">{{ activeEvent.name }}</h2>
          <Badge :tone="tone('eventStatus', activeEvent.status)">{{ label('eventStatus', activeEvent.status) }}</Badge>
        </div>
        <p class="stat-hint">{{ formatDate(activeEvent.start_date) }} a {{ formatDate(activeEvent.end_date) }}<template v-if="activeEvent.location"> · {{ activeEvent.location }}</template></p>
        <ul v-if="activeDays.length" class="dash-lines">
          <li v-for="day in activeDays" :key="day.id"><span>{{ dayLabel(day) }}</span><b>{{ day.start_time ? `${day.start_time}–${day.end_time || '—'}` : '—' }}</b></li>
        </ul>
        <p v-else class="dash-empty">Nenhum dia cadastrado para o evento.</p>
      </section>
      <LoadState :loading="summary.loading.value" :loaded="summary.loaded.value" :error="summary.error.value" :empty="blocks.length === 0" loading-text="Carregando resumo…" empty-title="Nada para resumir" empty-text="Seu perfil não consulta os módulos resumidos aqui." @retry="summary.load()">
        <div class="editor-grid">
          <DashboardDomain v-for="item in blocks" :key="item.title" v-bind="item" />
        </div>
      </LoadState>
    </div>
  </Page>
</template>
