<script setup>
import Badge from '../components/Badge.vue'
import ChallengeFormModal from '../components/ChallengeFormModal.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import { useChallengeDetail } from '@/js/pages/challenge-detail'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  id, detail, challenge, canManage, canViewCompanies, form, FLOW, statusOptions, nextStep, teamLocked, canDistribute, canMove,
  modal, nextStatus, openStatus, changeStatus, teams, teamId, openTeam, assign, askUnassign, unassign, go, label, tone,
} = useChallengeDetail(props)
</script>

<template>
  <Page v-if="!id" title="Desafio">
    <Empty title="Desafio não informado." text="Abra o desafio a partir da lista.">
      <template #action><button class="btn" type="button" @click="go('desafios')">Voltar</button></template>
    </Empty>
  </Page>
  <Page
    v-else
    :crumbs="`HackLab / Organização / Desafios / ${challenge?.title || 'Desafio'}`"
    title="Detalhes do Desafio"
    :subtitle="challenge ? `${challenge.title} · ${challenge.company?.name || 'Sem empresa'}` : ''"
  >
    <template #actions>
      <button class="btn ghost" type="button" @click="go('desafios')">Voltar</button>
      <template v-if="challenge && canManage">
        <button class="btn ghost" type="button" @click="form.start(challenge)">Editar</button>
        <button class="btn ghost" type="button" @click="openStatus()">Alterar status</button>
        <button v-if="canDistribute" class="btn" type="button" @click="openTeam">Distribuir para equipe</button>
        <button v-else-if="nextStep" class="btn" type="button" @click="openStatus(nextStep.status)">{{ nextStep.text }}</button>
      </template>
    </template>

    <LoadState :loading="detail.loading.value" :loaded="detail.loaded.value" :error="detail.error.value" :empty="detail.loaded.value && !challenge" empty-title="Desafio não encontrado" @retry="detail.load()">
      <template v-if="challenge">
        <ol class="challenge-flow" aria-label="Etapas do desafio">
          <li v-for="code in FLOW" :key="code" :class="{ on: code === challenge.status, done: FLOW.indexOf(code) < FLOW.indexOf(challenge.status) }" :aria-current="code === challenge.status ? 'step' : undefined">{{ label('challengeStatus', code) }}</li>
        </ol>
        <p>Status atual: <Badge :tone="tone('challengeStatus', challenge.status)">{{ label('challengeStatus', challenge.status) }}</Badge></p>
        <div class="grid cols-2">
          <article class="card">
            <p>
              <b>Empresa</b> {{ challenge.company?.name || 'Sem empresa' }}
              <Badge v-if="challenge.company?.status === 'INACTIVE'" tone="danger">Inativa</Badge>
              <button v-if="challenge.company && canViewCompanies" class="linkish" type="button" @click="go(`empresa?id=${challenge.company.id}`)">Ver empresa</button>
            </p>
            <p><b>Problema</b><br /><span class="pre-line">{{ challenge.problem || '—' }}</span></p>
            <p><b>Objetivo</b><br /><span class="pre-line">{{ challenge.objective || '—' }}</span></p>
            <p><b>Requisitos</b><br /><span class="pre-line">{{ challenge.requirements || '—' }}</span></p>
            <p><b>Restrições</b><br /><span class="pre-line">{{ challenge.restrictions || '—' }}</span></p>
            <p><b>Resultado esperado</b><br /><span class="pre-line">{{ challenge.expected_outcome || '—' }}</span></p>
            <p><b>Observações</b><br /><span class="pre-line">{{ challenge.notes || '—' }}</span></p>
          </article>
          <article class="card">
            <h3>Equipe responsável</h3>
            <template v-if="challenge.team">
              <p>{{ challenge.team.name }}<template v-if="challenge.team.code"> · {{ challenge.team.code }}</template> <Badge v-if="challenge.team.status === 'INACTIVE'" tone="danger">Inativa</Badge></p>
              <div v-if="canManage" class="row-actions">
                <button v-if="canMove" class="btn ghost small" type="button" @click="openTeam">Trocar equipe</button>
                <button v-if="canMove" class="btn ghost small" type="button" @click="askUnassign">Retirar equipe</button>
              </div>
              <p v-if="teamLocked" class="stat-hint">Em "{{ label('challengeStatus', challenge.status) }}" a equipe não pode ser trocada nem retirada. Volte o status para Distribuído antes.</p>
            </template>
            <template v-else>
              <p>Este desafio ainda não foi distribuído.</p>
              <p v-if="challenge.status !== 'APPROVED'" class="stat-hint">Aprove o desafio para liberar a distribuição a uma equipe.</p>
            </template>
          </article>
        </div>
      </template>
    </LoadState>

    <ChallengeFormModal v-if="form.open.value" :ctrl="form" />

    <Modal v-if="modal === 'status'" title="Alterar status do desafio" :subtitle="challenge.title" @close="modal = null">
      <Field label="Novo status" :error="changeStatus.fieldErrors.value.status">
        <select v-model="nextStatus" class="input">
          <option v-for="code in statusOptions" :key="code" :value="code" :disabled="code === challenge.status">{{ label('challengeStatus', code) }}{{ code === challenge.status ? ' (atual)' : '' }}</option>
        </select>
      </Field>
      <p class="stat-hint">
        <template v-if="challenge.team">Com equipe vinculada, o status fica entre Distribuído, Em desenvolvimento e Finalizado. Para voltar a etapas anteriores, retire a equipe.</template>
        <template v-else>Distribuído e Em desenvolvimento exigem equipe: use "Distribuir para equipe" com o desafio aprovado.</template>
      </p>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="changeStatus.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="changeStatus.pending.value || !nextStatus || nextStatus === challenge.status" @click="changeStatus.run()">{{ changeStatus.pending.value ? 'Salvando…' : 'Confirmar' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'team'" :title="challenge.team ? 'Trocar equipe' : 'Distribuir para equipe'" subtitle="Só equipes ativas e sem desafio podem receber este desafio." @close="modal = null">
      <p><b>Desafio</b> {{ challenge.title }}<br /><b>Empresa</b> {{ challenge.company?.name || '—' }}<template v-if="challenge.team"><br /><b>Equipe atual</b> {{ challenge.team.name }}</template></p>
      <Field label="Equipe" required :error="assign.fieldErrors.value.team_id || teams.error.value?.message">
        <select v-model="teamId" class="input" :disabled="teams.loading.value">
          <option value="">{{ teams.loading.value ? 'Carregando equipes…' : 'Selecione' }}</option>
          <option v-for="team in teams.data.value" :key="team.id" :value="team.id">{{ team.name }}{{ team.code ? ` · ${team.code}` : '' }}</option>
        </select>
      </Field>
      <p v-if="teams.loaded.value && !teams.data.value.length" class="stat-hint">Nenhuma equipe ativa sem desafio.</p>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="assign.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="assign.pending.value || !teamId" @click="assign.run()">{{ assign.pending.value ? 'Salvando…' : 'Confirmar' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'unassign'" title="Retirar equipe?" subtitle="O desafio volta para Aprovado e pode ser distribuído de novo." @close="modal = null">
      <p>{{ challenge.title }} deixa de estar com a equipe {{ challenge.team?.name }}.</p>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="unassign.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="unassign.pending.value" @click="unassign.run()">{{ unassign.pending.value ? 'Salvando…' : 'Retirar equipe' }}</button>
      </template>
    </Modal>
  </Page>
</template>
