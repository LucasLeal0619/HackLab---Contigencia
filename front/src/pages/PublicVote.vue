<script setup>
import Badge from '../components/Badge.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import { usePublicVote } from '@/js/pages/public-vote'

const { sessions, sheet, selectedId, selectSession, load, status, alreadyVoted, canVote, candidates, choice, pick, cast, confirm, label, tone, formatDateTime } = usePublicVote()
</script>

<template>
  <section class="vote-wrap">
    <header class="vote-head">
      <div class="row-between">
        <h1>Votação do Público</h1>
        <button class="btn ghost small" type="button" :disabled="sessions.loading.value || sheet.loading.value" @click="load">{{ sessions.loading.value || sheet.loading.value ? 'Atualizando…' : 'Atualizar' }}</button>
      </div>
      <p>Escolha a solução que deseja apoiar. Cada eleitor vota uma única vez em cada votação.</p>
    </header>

    <LoadState
      :loading="sessions.loading.value"
      :loaded="sessions.loaded.value"
      :error="sessions.error.value"
      :empty="sessions.data.value.length === 0"
      empty-title="Nenhuma votação disponível para você"
      empty-text="Quando a organização incluir sua conta em uma votação, ela aparece aqui."
      @retry="load"
    >
      <div v-if="sessions.data.value.length > 1" class="chips vote-sessions" role="group" aria-label="Votações">
        <button v-for="item in sessions.data.value" :key="item.id" type="button" class="chip" :class="{ on: item.id === selectedId }" @click="selectSession(item.id)">
          {{ item.name }} · {{ label('votingStatus', item.status) }}<template v-if="item.has_voted"> · votou</template>
        </button>
      </div>

      <LoadState :loading="sheet.loading.value" :loaded="Boolean(sheet.data.value)" :error="sheet.error.value" @retry="sheet.load()">
        <template v-if="sheet.data.value">
          <p class="vote-session-name">
            <b>{{ sheet.data.value.session.name }}</b>
            <Badge :tone="tone('votingStatus', status)">{{ label('votingStatus', status) }}</Badge>
          </p>

          <div v-if="alreadyVoted" class="vote-done" role="status">
            <span class="vote-check" aria-hidden="true">✓</span>
            <h2>Voto registrado</h2>
            <p v-if="sheet.data.value.my_vote">Você votou em <b>{{ sheet.data.value.my_vote.team.name }}</b> em {{ formatDateTime(sheet.data.value.my_vote.cast_at) }}.</p>
            <p>O voto é definitivo e não pode ser alterado. Obrigado pela participação.</p>
          </div>

          <div v-else-if="status === 'DRAFT'" class="vote-done vote-wait" role="status">
            <span class="vote-check" aria-hidden="true">…</span>
            <h2>Votação ainda não iniciada</h2>
            <p>Aguarde a abertura da votação do público e toque em Atualizar.</p>
          </div>

          <div v-else-if="status === 'CLOSED'" class="vote-done vote-wait" role="status">
            <span class="vote-check" aria-hidden="true">–</span>
            <h2>Votação encerrada</h2>
            <p>O período de votação foi finalizado sem voto registrado por você.</p>
          </div>

          <template v-else>
            <p v-if="candidates.length === 0" class="banner">Nenhuma equipe disponível para votação.</p>
            <ul class="vote-board">
              <li v-for="item in candidates" :key="item.team.id" class="card vote-card">
                <h2>{{ item.team.name }}</h2>
                <dl>
                  <dt>Empresa</dt><dd>{{ item.company?.name || 'A definir' }}</dd>
                  <dt>Desafio</dt><dd>{{ item.challenge?.title || 'A definir' }}</dd>
                </dl>
                <button class="btn vote-btn" type="button" :disabled="!canVote" @click="pick(item)">Votar nesta equipe</button>
              </li>
            </ul>
          </template>
        </template>
      </LoadState>
    </LoadState>

    <Modal v-if="choice" title="Confirmar voto" subtitle="Depois de confirmado, o voto não pode ser alterado." @close="choice = null">
      <p class="vote-confirm">{{ choice.team.name }}</p>
      <p v-if="choice.challenge" class="stat-hint">{{ choice.company?.name || '—' }} · {{ choice.challenge.title }}</p>
      <p v-for="(message, field) in cast.fieldErrors.value" :key="field" class="field-error">{{ message }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="choice = null">Voltar</button>
        <button class="btn" type="button" :disabled="cast.pending.value" @click="confirm">{{ cast.pending.value ? 'Enviando…' : 'Confirmar voto' }}</button>
      </template>
    </Modal>
  </section>
</template>
