<script setup>
import Badge from '../components/Badge.vue'
import Drawer from '../components/Drawer.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Stat from '../components/Stat.vue'
import Tabs from '../components/Tabs.vue'
import { useEvaluations } from '@/js/pages/evaluations'

defineProps({
  params: { type: Object, default: () => ({}) },
})

const {
  tabs, tab, canAll, canJudgeArea,
  progress, totals, teamStatus,
  technical,
  list, jurors, teamOptions,
  detailId, detail, openDetail, closeDetail, canAskRevision,
  modal, reason, openRevision, revision,
  scoreText, formatScore, formatDateTime, label, tone, options, go,
} = useEvaluations()
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page title="Avaliações" subtitle="Acompanhe as avaliações técnicas das equipes feitas pelos jurados.">
    <template #actions>
      <button v-if="canJudgeArea" class="btn ghost" type="button" @click="go('area-jurado')">Abrir Área do Jurado</button>
    </template>

    <Tabs v-if="tabs.length > 1" v-model="tab" :tabs="tabs" />

    <!-- Progresso agregado: sem notas nem comentários -->
    <template v-if="tab === 'progresso'">
      <LoadState :loading="progress.loading.value" :loaded="progress.loaded.value" :error="progress.error.value" :empty="progress.data.value.length === 0" empty-title="Nenhuma equipe no evento" empty-text="O progresso aparece quando houver equipes e jurados atribuídos." @retry="progress.load()">
        <div class="grid cols-4">
          <Stat icon="check" label="Avaliações enviadas" :value="`${totals.submitted} de ${totals.assigned}`" :hint="totals.percent === null ? 'Nenhuma atribuição ainda.' : `${totals.percent}% concluído`" />
          <Stat icon="edit" label="Em rascunho" :value="totals.draft" />
          <Stat icon="alert" label="Revisão solicitada" :value="totals.revision" />
          <Stat icon="users" label="Equipes sem jurado" :value="totals.withoutJurors" :hint="`${totals.teams} equipe(s) no evento`" />
        </div>
        <div class="table-wrap mt">
          <table>
            <thead><tr><th>Equipe</th><th>Jurados</th><th>Enviadas</th><th>Rascunho</th><th>Em revisão</th><th>Não iniciadas</th><th>Conclusão</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="row in progress.data.value" :key="row.team.id">
                <td>{{ row.team.name }}</td>
                <td>{{ row.assigned_jurors || '—' }}</td>
                <td>{{ row.submitted }}</td>
                <td>{{ row.draft }}</td>
                <td>{{ row.revision_requested }}</td>
                <td>{{ row.not_started }}</td>
                <td>{{ row.completion_percent === null ? '—' : `${Math.round(row.completion_percent)}%` }}</td>
                <td><Badge :tone="teamStatus(row).tone">{{ teamStatus(row).text }}</Badge></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="!canAll" class="stat-hint">Notas e comentários dos jurados são visíveis apenas para a administração.</p>
      </LoadState>
    </template>

    <!-- Resultado técnico parcial -->
    <template v-if="tab === 'tecnico' && canAll">
      <div class="banner warn" role="status">{{ technical.data.value.note || 'Resultado técnico parcial (somente jurados). Não inclui voto público nem é o resultado final.' }}</div>
      <LoadState :loading="technical.loading.value" :loaded="technical.loaded.value" :error="technical.error.value" :empty="technical.data.value.rows.length === 0" empty-title="Nenhuma equipe no evento" @retry="technical.load()">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Equipe</th><th>Avaliações válidas</th><th>Nota técnica (0–100)</th></tr></thead>
            <tbody>
              <tr v-for="row in technical.data.value.rows" :key="row.team.id">
                <td>{{ row.team.name }}</td>
                <td>{{ row.submitted_evaluations || '—' }}</td>
                <td>{{ row.technical_score === null ? 'Sem avaliação enviada' : formatScore(row.technical_score) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="stat-hint">Média dos percentuais das avaliações enviadas com atribuição ativa. Rascunhos e avaliações em revisão não contam.</p>
      </LoadState>
    </template>

    <!-- Avaliações individuais (com notas) -->
    <template v-if="tab === 'individuais' && canAll">
      <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
        <select v-model="filters.status" class="input" aria-label="Status">
          <option value="">Todos os status</option>
          <option v-for="[code, name] in options('evaluationStatus')" :key="code" :value="code">{{ name }}</option>
        </select>
        <select v-model="filters.team_id" class="input" aria-label="Equipe">
          <option value="">Todas as equipes</option>
          <option v-for="team in teamOptions" :key="team.id" :value="team.id">{{ team.name }}</option>
        </select>
        <select v-model="filters.juror_id" class="input" aria-label="Jurado">
          <option value="">Todos os jurados</option>
          <option v-for="item in jurors.data.value" :key="item.id" :value="item.id">{{ item.person?.full_name }}</option>
        </select>
      </FilterPanel>
      <LoadState :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0" empty-title="Nenhuma avaliação encontrada" :empty-text="list.activeFilters.value ? 'Ajuste os filtros.' : 'As avaliações aparecem quando os jurados começarem a avaliar.'" @retry="list.load()">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Equipe</th><th>Jurado</th><th>Status</th><th>Percentual</th><th>Enviada em</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in items" :key="item.id">
                <td>{{ item.team?.name || '—' }}</td>
                <td>{{ item.juror?.name || '—' }}</td>
                <td><Badge :tone="tone('evaluationStatus', item.status)">{{ label('evaluationStatus', item.status) }}</Badge></td>
                <td>{{ item.percent === null ? '—' : `${formatScore(item.percent)}%` }}</td>
                <td>{{ formatDateTime(item.submitted_at) }}</td>
                <td><button class="btn ghost small" type="button" @click="openDetail(item)">Visualizar</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pager :meta="meta" :disabled="loading" @page="page = $event" />
      </LoadState>
    </template>

    <Empty v-if="!tabs.length" title="Sem acesso" text="Seu perfil não acompanha as avaliações." />

    <Drawer v-if="detailId" :title="detail.data.value?.team?.name || 'Avaliação'" :subtitle="detail.data.value?.juror?.name ? `Jurado: ${detail.data.value.juror.name}` : ''" @close="closeDetail">
      <LoadState :loading="detail.loading.value" :loaded="detail.loaded.value && !!detail.data.value" :error="detail.error.value" @retry="detail.load()">
        <template v-if="detail.data.value">
          <p>
            <b>Status</b> <Badge :tone="tone('evaluationStatus', detail.data.value.status)">{{ label('evaluationStatus', detail.data.value.status) }}</Badge><br />
            <b>Percentual</b> {{ detail.data.value.percent === null ? '— (critérios incompletos)' : `${formatScore(detail.data.value.percent)}%` }}<br />
            <b>Enviada em</b> {{ formatDateTime(detail.data.value.submitted_at) }}
          </p>
          <p v-if="detail.data.value.assignment_status === 'REVOKED'" class="banner warn">Atribuição revogada: esta avaliação fica só no histórico e não entra no resultado técnico.</p>
          <div v-if="detail.data.value.revision" class="banner">
            <div>
              <b>Última revisão solicitada</b> em {{ formatDateTime(detail.data.value.revision.requested_at) }}<br />
              {{ detail.data.value.revision.reason }}
            </div>
          </div>
          <h3 class="ops-title">Notas por critério</h3>
          <Empty v-if="!detail.data.value.scores?.length" title="Nenhuma nota registrada." />
          <div v-else class="table-wrap">
            <table>
              <thead><tr><th>Critério</th><th>Nota</th><th>Comentário</th></tr></thead>
              <tbody>
                <tr v-for="score in detail.data.value.scores" :key="score.criterion_id">
                  <td>{{ score.criterion_name || `Critério ${score.criterion_id}` }}</td>
                  <td>{{ scoreText(score.score) }}</td>
                  <td>{{ score.comment || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p><b>Comentário geral</b><br />{{ detail.data.value.comments || '—' }}</p>
          <p class="stat-hint">A administração não altera notas. Para corrigir, solicite revisão: o próprio jurado ajusta e reenvia.</p>
        </template>
      </LoadState>
      <template v-if="canAskRevision" #footer>
        <button class="btn" type="button" @click="openRevision">Solicitar revisão</button>
      </template>
    </Drawer>

    <Modal v-if="modal === 'revision'" title="Solicitar revisão?" subtitle="A avaliação volta para o jurado corrigir e reenviar. Enquanto isso, ela não conta no resultado técnico." @close="modal = null">
      <p>{{ detail.data.value?.team?.name }} · {{ detail.data.value?.juror?.name }}</p>
      <Field label="Motivo da revisão" required hint="O jurado verá este motivo." :error="revision.fieldErrors.value.reason || revision.fieldErrors.value.status">
        <textarea v-model="reason" class="input" maxlength="2000" />
      </Field>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="revision.pending.value || !reason.trim()" @click="revision.run()">{{ revision.pending.value ? 'Enviando…' : 'Solicitar revisão' }}</button>
      </template>
    </Modal>
  </Page>
</template>
