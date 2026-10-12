<script setup>
import Badge from '../components/Badge.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import { useEvaluate } from '@/js/pages/evaluate'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  request, entry, criteria, evaluation, status, locked, jurorInactive,
  scores, comments, notes, filled, complete, dirty, isFilled,
  setScore, setComment, save, submit, ask, scoreErrors, generalError,
  scaleOf, back, label, tone, formatDateTime, formatScore,
} = useEvaluate(props)
</script>

<template>
  <div v-if="!request.loaded.value || request.error.value" class="page">
    <LoadState :loading="request.loading.value" :loaded="request.loaded.value" :error="request.error.value" @retry="request.load()" />
  </div>
  <Page v-else-if="entry" :crumbs="`Área do Jurado / ${entry.team.name}`" :title="`Avaliar ${entry.team.name}`" :subtitle="locked ? 'Avaliação somente para consulta.' : 'Preencha os critérios, salve o rascunho e envie quando terminar.'">
    <template #actions>
      <button class="btn ghost" type="button" @click="back">Voltar às avaliações</button>
    </template>
    <div class="eval-head">
      <div>
        <p><b>Equipe</b> {{ entry.team.name }}</p>
        <p><b>Empresa</b> {{ entry.company?.name || '—' }}</p>
        <p><b>Desafio</b> {{ entry.challenge?.title || '—' }}</p>
      </div>
      <div>
        <Badge v-if="status === 'NOT_STARTED'">Não iniciada</Badge>
        <Badge v-else :tone="tone('evaluationStatus', status)">{{ label('evaluationStatus', status) }}</Badge>
        <p v-if="criteria.length" class="eval-progress">{{ filled }} de {{ criteria.length }} critérios preenchidos</p>
        <p v-if="evaluation?.percent !== null && evaluation?.percent !== undefined" class="eval-progress">Percentual: {{ formatScore(evaluation.percent) }}%</p>
        <p v-if="evaluation?.submitted_at" class="eval-progress">Enviada em {{ formatDateTime(evaluation.submitted_at) }}</p>
      </div>
    </div>

    <div v-if="jurorInactive" class="banner warn" role="status">Seu cadastro de jurado está inativo: a avaliação não pode ser alterada.</div>
    <div v-if="status === 'REVISION_REQUESTED' && evaluation?.revision" class="banner warn" role="status">
      <div><b>Revisão solicitada pela organização</b> em {{ formatDateTime(evaluation.revision.requested_at) }}<br />{{ evaluation.revision.reason }}</div>
    </div>
    <div v-if="status === 'SUBMITTED'" class="banner ok" role="status">Avaliação enviada. Ela só pode ser alterada se a organização solicitar revisão.</div>
    <div v-if="generalError" class="banner err" role="alert">{{ generalError }}</div>

    <h3 class="ops-title">Critérios</h3>
    <Empty v-if="criteria.length === 0" title="Nenhum critério configurado." text="A organização ainda não definiu os critérios de avaliação." />
    <div v-for="item in criteria" :key="item.id" class="eval-row">
      <div>
        <b>{{ item.name }}</b>
        <p>{{ item.description || 'Sem descrição.' }}</p>
        <p>Peso {{ item.weight }}</p>
      </div>
      <Field :label="`Nota (${scaleOf(item)})`" :error="scoreErrors[item.id]">
        <input class="input" type="number" inputmode="decimal" step="any" :min="item.min_score" :max="item.max_score" :value="scores[item.id] ?? ''" :disabled="locked" :aria-label="`Nota de ${item.name}`" @input="setScore(item.id, $event.target.value)" />
      </Field>
      <span class="stat-hint">{{ isFilled(item.id) ? 'Preenchido' : 'Pendente' }}</span>
      <Field label="Comentário do critério (opcional)" class-name="eval-row-comment">
        <input class="input" :value="comments[item.id] ?? ''" :disabled="locked" maxlength="2000" @input="setComment(item.id, $event.target.value)" />
      </Field>
    </div>
    <Field label="Comentário geral" :error="submit.fieldErrors.value.comments || save.fieldErrors.value.comments"><textarea v-model="notes" class="input" :disabled="locked" maxlength="5000" /></Field>

    <div v-if="!locked" class="page-actions eval-actions">
      <span v-if="dirty" class="stat-hint">Alterações não salvas</span>
      <button class="btn ghost" type="button" :disabled="!criteria.length || save.pending.value || submit.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : 'Salvar rascunho' }}</button>
      <button class="btn" type="button" :disabled="!criteria.length || save.pending.value || submit.pending.value" @click="ask = true">{{ status === 'REVISION_REQUESTED' ? 'Reenviar avaliação' : 'Enviar avaliação' }}</button>
    </div>

    <Modal v-if="ask" :title="status === 'REVISION_REQUESTED' ? 'Reenviar avaliação?' : 'Enviar avaliação?'" subtitle="Depois de enviada, a avaliação não pode ser alterada, a menos que a organização solicite revisão." @close="ask = false">
      <p>{{ entry.team.name }} · {{ filled }} de {{ criteria.length }} critérios preenchidos</p>
      <p v-if="!complete" class="banner warn">Preencha todos os critérios antes de enviar.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="ask = false">Continuar avaliando</button>
        <button class="btn" type="button" :disabled="!complete || submit.pending.value" @click="submit.run()">{{ submit.pending.value ? 'Enviando…' : 'Enviar' }}</button>
      </template>
    </Modal>
  </Page>
  <Page v-else title="Avaliação" subtitle="Esta equipe não está entre as suas avaliações.">
    <Empty title="Equipe não atribuída a você." text="Volte para Minhas avaliações para ver as equipes que você avalia." />
    <button class="btn" type="button" @click="back">Voltar às avaliações</button>
  </Page>
</template>
