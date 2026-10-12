<script setup>
// Blocos de um resultado (prévia ou snapshot publicado), exatamente como o backend devolve.
// Técnico (jurados) e Escolha do Público ficam separados: não existe nota combinada.
import Badge from './Badge.vue'
import { formatDateTime, formatNumber, formatScore } from '@/js/utils/format'

defineProps({
  result: { type: Object, required: true },
})

function percent(value) {
  return value === null || value === undefined ? '—' : `${formatNumber(value, 2)}%`
}
</script>

<template>
  <div class="results-stage">
    <section class="results-block">
      <h2>Resultado dos Jurados</h2>
      <p class="stat-hint">Resultado técnico das avaliações enviadas. Não inclui a votação do público e não é somado a ela.</p>
      <p v-if="!result.technical?.teams?.length">Nenhuma equipe no resultado técnico.</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Posição</th><th>Equipe</th><th>Nota técnica</th><th>Avaliações válidas</th></tr></thead>
          <tbody>
            <tr v-for="item in result.technical.teams" :key="item.team.id">
              <td>{{ item.rank ? `${item.rank}º` : '—' }}</td>
              <td>{{ item.team.name }} <Badge v-if="item.tied" tone="warn">Empate</Badge></td>
              <td>{{ item.technical_score === null ? 'Sem avaliação válida' : formatScore(item.technical_score) }}</td>
              <td>{{ item.valid_evaluations }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="result.technical?.has_ties" class="stat-hint">Há empate no resultado técnico. O desempate não é automático.</p>
    </section>

    <section class="results-block">
      <h2>Escolha do Público</h2>
      <p v-if="result.voting_session" class="stat-hint">
        Votação “{{ result.voting_session.name }}”<template v-if="result.voting_session.closed_at">, encerrada em {{ formatDateTime(result.voting_session.closed_at) }}</template>.
        <template v-if="result.turnout"> Participação: {{ result.turnout.votes_cast }} de {{ result.turnout.eligible_voters }} eleitores ({{ percent(result.turnout.turnout_percent) }}).</template>
      </p>
      <p v-if="!result.popular">A contagem da votação do público só aparece com uma sessão encerrada.</p>
      <p v-else-if="!result.popular.teams?.length">Nenhuma equipe candidata nesta votação.</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Posição</th><th>Equipe</th><th>Votos</th><th>Percentual</th></tr></thead>
          <tbody>
            <tr v-for="item in result.popular.teams" :key="item.team.id">
              <td>{{ item.rank ? `${item.rank}º` : '—' }}</td>
              <td>{{ item.team.name }} <Badge v-if="item.tied" tone="warn">Empate</Badge></td>
              <td>{{ item.vote_count }}</td>
              <td>{{ percent(item.percentage) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="result.popular" class="stat-hint">Total de votos: {{ result.popular.total_votes }}.<template v-if="result.popular.has_ties"> Há empate na votação do público.</template></p>
    </section>
  </div>
</template>
