<script setup>
import Badge from '../components/Badge.vue'
import Empty from '../components/Empty.vue'
import LoadState from '../components/LoadState.vue'
import { useJudgeArea } from '@/js/pages/judge-area'

defineProps({
  params: { type: Object, default: () => ({}) },
})

const { request, items, juror, inactive, summary, statusOf, actionOf, open, formatDateTime } = useJudgeArea()
</script>

<template>
  <section class="judge-home">
    <header class="judge-head">
      <h1>Minhas avaliações</h1>
      <p>Escolha a equipe para avaliar. Suas notas ficam visíveis apenas para a organização.</p>
    </header>
    <LoadState :loading="request.loading.value" :loaded="request.loaded.value" :error="request.error.value" @retry="request.load()">
      <Empty v-if="!juror" title="Você não está cadastrado como jurado neste evento." text="Se você vai avaliar equipes, fale com a organização do Hackathon." />
      <template v-else>
        <div v-if="inactive" class="banner warn" role="status">Seu cadastro de jurado está inativo: as avaliações ficam somente para consulta.</div>
        <p v-if="summary.total" class="stat-hint judge-summary">{{ summary.submitted }} de {{ summary.total }} avaliação(ões) enviada(s)<template v-if="summary.revision"> · {{ summary.revision }} com revisão solicitada</template></p>
        <Empty v-if="items.length === 0" title="Nenhuma avaliação atribuída a você no momento." text="Quando a organização atribuir equipes, elas aparecerão aqui." />
        <ul v-else class="judge-list">
          <li v-for="item in items" :key="item.assignment.id" class="card judge-card">
            <div class="judge-card-main">
              <h2>{{ item.team.name }}</h2>
              <p>{{ item.challenge?.title || 'Desafio a definir' }}</p>
              <p class="stat-hint">{{ item.company?.name || 'Empresa a definir' }}</p>
              <p v-if="item.progress?.status !== 'SUBMITTED' && item.progress?.total_criteria" class="stat-hint">{{ item.progress.filled_criteria }} de {{ item.progress.total_criteria }} critérios preenchidos</p>
              <p v-if="item.progress?.status === 'SUBMITTED' && item.evaluation?.submitted_at" class="stat-hint">Enviada em {{ formatDateTime(item.evaluation.submitted_at) }}</p>
              <p v-if="item.progress?.status === 'REVISION_REQUESTED' && item.evaluation?.revision" class="judge-revision"><b>Revisão solicitada:</b> {{ item.evaluation.revision.reason }}</p>
            </div>
            <Badge :tone="statusOf(item).tone">{{ statusOf(item).text }}</Badge>
            <button class="btn" :class="{ ghost: actionOf(item).ghost || inactive }" type="button" @click="open(item)">{{ inactive ? 'Visualizar' : actionOf(item).text }}</button>
          </li>
        </ul>
      </template>
    </LoadState>
  </section>
</template>
