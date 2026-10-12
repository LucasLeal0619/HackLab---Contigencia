<script setup>
import { computed } from 'vue'
import Badge from '../components/Badge.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Stat from '../components/Stat.vue'
import { useDistribution } from '@/js/pages/distribution'

const {
  canManage, data, challenges, teams, waiting, freeTeams, stats, cards, isLocked,
  modal, target, choice, openFromChallenge, openFromTeam, askUnassign, assign, unassign, go, label, tone,
} = useDistribution()

const rows = computed(() => challenges.value.filter((item) => item.team || item.status === 'APPROVED'))
</script>

<template>
  <Page
    crumbs="HackLab / Organização / Desafios / Distribuição"
    title="Distribuição dos Desafios"
    subtitle="Relação entre equipes, empresas e desafios. Cada equipe recebe no máximo um desafio, e só desafios aprovados são distribuídos."
  >
    <template #actions>
      <button class="btn ghost" type="button" @click="go('desafios')">Voltar</button>
      <button class="btn ghost" type="button" :disabled="data.loading.value" @click="data.load()">{{ data.loading.value ? 'Atualizando…' : 'Atualizar' }}</button>
    </template>

    <LoadState
      :loading="data.loading.value" :loaded="data.loaded.value" :error="data.error.value"
      :empty="challenges.length === 0 && teams.length === 0"
      empty-title="Nenhum desafio ou equipe neste evento"
      empty-text="Cadastre os desafios e as equipes para distribuí-los."
      @retry="data.load()"
    >
      <div class="grid cols-4">
        <Stat v-for="item in stats" :key="item.label" :label="item.label" :value="item.value" />
      </div>

      <h2 class="section-title">Desafios</h2>
      <div v-if="rows.length === 0" class="card"><p class="stat-hint">Nenhum desafio aprovado ou distribuído ainda. Aprove os desafios na página de cada um.</p></div>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Desafio</th><th>Empresa</th><th>Status</th><th>Equipe</th><th v-if="canManage">Ações</th></tr></thead>
          <tbody>
            <tr v-for="item in rows" :key="item.id">
              <td><button class="linkish" type="button" @click="go(`desafio?id=${item.id}`)">{{ item.title }}</button></td>
              <td>{{ item.company?.name || '—' }}</td>
              <td><Badge :tone="tone('challengeStatus', item.status)">{{ label('challengeStatus', item.status) }}</Badge></td>
              <td>{{ item.team?.name || '—' }}</td>
              <td v-if="canManage">
                <div class="row-actions">
                  <button v-if="!item.team" class="btn small" type="button" :disabled="!freeTeams.length" @click="openFromChallenge(item)">Distribuir</button>
                  <template v-else-if="!isLocked(item)">
                    <button class="btn ghost small" type="button" :disabled="!freeTeams.length" @click="openFromChallenge(item)">Trocar equipe</button>
                    <button class="btn ghost small" type="button" @click="askUnassign(item)">Retirar</button>
                  </template>
                  <small v-else class="stat-hint">Equipe travada em "{{ label('challengeStatus', item.status) }}"</small>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <h2 class="section-title">Equipes</h2>
      <div v-if="cards.length === 0" class="card"><p class="stat-hint">Nenhuma equipe cadastrada neste evento.</p></div>
      <div v-else class="grid cols-2 distribution-cards">
        <article v-for="card in cards" :key="card.team.id" class="card">
          <div class="row-between">
            <h3>{{ card.team.name }}<small v-if="card.team.code" class="stat-hint"> · {{ card.team.code }}</small></h3>
            <Badge v-if="card.team.status === 'INACTIVE'" tone="danger">Inativa</Badge>
          </div>
          <p>Empresa: {{ card.company }}</p>
          <p>Desafio: {{ card.challenge?.title || '—' }}</p>
          <Badge v-if="card.challenge" :tone="tone('challengeStatus', card.challenge.status)">{{ label('challengeStatus', card.challenge.status) }}</Badge>
          <Badge v-else tone="warn">Sem desafio</Badge>
          <div v-if="canManage && !card.challenge && card.team.status === 'ACTIVE'" class="row-actions distribution-card-actions">
            <button class="btn ghost small" type="button" :disabled="!waiting.length" @click="openFromTeam(card.team)">Distribuir desafio</button>
            <small v-if="!waiting.length" class="stat-hint">Nenhum desafio aprovado aguardando equipe.</small>
          </div>
        </article>
      </div>
    </LoadState>

    <Modal v-if="modal === 'by-challenge'" :title="target.team ? 'Trocar equipe' : 'Distribuir desafio'" subtitle="Só equipes ativas e sem desafio podem receber." @close="modal = null">
      <p><b>Desafio</b> {{ target.challenge.title }}<br /><b>Empresa</b> {{ target.challenge.company?.name || '—' }}<template v-if="target.team"><br /><b>Equipe atual</b> {{ target.team.name }}</template></p>
      <Field label="Equipe" required :error="assign.fieldErrors.value.team_id">
        <select v-model="choice" class="input">
          <option value="">Selecione</option>
          <option v-for="team in freeTeams" :key="team.id" :value="team.id">{{ team.name }}{{ team.code ? ` · ${team.code}` : '' }}</option>
        </select>
      </Field>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="assign.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="assign.pending.value || !choice" @click="assign.run()">{{ assign.pending.value ? 'Salvando…' : 'Confirmar' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'by-team'" title="Distribuir desafio" :subtitle="`Equipe ${target.team.name}`" @close="modal = null">
      <Field label="Desafio aprovado" required :error="assign.fieldErrors.value.team_id">
        <select v-model="choice" class="input">
          <option value="">Selecione</option>
          <option v-for="item in waiting" :key="item.id" :value="item.id">{{ item.title }}{{ item.company ? ` · ${item.company.name}` : '' }}</option>
        </select>
      </Field>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="assign.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="assign.pending.value || !choice" @click="assign.run()">{{ assign.pending.value ? 'Salvando…' : 'Confirmar' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'unassign'" title="Retirar equipe?" subtitle="O desafio volta para Aprovado e pode ser distribuído de novo." @close="modal = null">
      <p>{{ target.challenge.title }} deixa de estar com a equipe {{ target.team?.name }}.</p>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="unassign.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="unassign.pending.value" @click="unassign.run()">{{ unassign.pending.value ? 'Salvando…' : 'Retirar equipe' }}</button>
      </template>
    </Modal>
  </Page>
</template>
