<script setup>
import Badge from '../components/Badge.vue'
import Empty from '../components/Empty.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import Tabs from '../components/Tabs.vue'
import { useTeamBuild } from '@/js/pages/team-build'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  teamId, team, members, active, paused, mix, wrongEvent, editable, tabs, pool, poolItems,
  add, confirm, askRemove, askTransfer, remove, transfer, busy, canParticipants, countText, label, tone, go,
} = useTeamBuild(props)
</script>

<template>
  <Page v-if="!teamId" title="Montar equipe">
    <template #actions><button class="btn ghost" type="button" @click="go('equipes')">Voltar</button></template>
    <Empty title="Nenhuma equipe selecionada" text="Escolha uma equipe na lista de equipes para montar a composição.">
      <template #action><button class="btn" type="button" @click="go('equipes')">Ir para Equipes</button></template>
    </Empty>
  </Page>
  <Page
    v-else
    :crumbs="`Equipes / ${team.data.value?.name || 'Equipe'}`"
    :title="team.data.value?.name || 'Equipe'"
    subtitle="Monte a equipe participante a participante. Cada alteração é registrada no histórico."
  >
    <template #actions><button class="btn ghost" type="button" @click="go('equipes')">Voltar</button></template>
    <LoadState :loading="team.loading.value" :loaded="team.loaded.value" :error="team.error.value" :empty="!team.data.value" empty-title="Equipe não encontrada" @retry="team.load()">
      <div class="card">
        <div class="row-between">
          <h3>{{ countText(members.length, 'participante', 'participantes') }}</h3>
          <Badge :tone="tone('teamStatus', team.data.value?.status)">{{ label('teamStatus', team.data.value?.status) }}</Badge>
        </div>
        <p v-if="mix.length" class="stat-hint">{{ mix.map(([name, total]) => `${name} ${total}`).join(' · ') }}</p>
        <p v-if="team.data.value?.challenge" class="stat-hint">Desafio: {{ team.data.value.challenge.title }}</p>
        <p v-if="paused.length">Atenção. A composição desta equipe mudou: {{ countText(paused.length, 'membro não está disponível', 'membros não estão disponíveis') }}.</p>
        <p v-if="wrongEvent" class="banner warn">Esta equipe pertence a outro evento. Troque o evento ativo para alterá-la.</p>
        <p v-else-if="team.data.value?.status !== 'ACTIVE'" class="banner warn">Equipe inativa: não recebe participantes. Reative-a em Equipes para alterar a composição.</p>
      </div>
      <div class="split mt">
        <section class="card">
          <h3>Participantes disponíveis</h3>
          <template v-if="canParticipants">
            <div class="chips">
              <button type="button" class="chip" :class="{ on: pool.filters.mode === 'free' }" @click="pool.filters.mode = 'free'">Sem equipe</button>
              <button type="button" class="chip" :class="{ on: pool.filters.mode === 'others' }" @click="pool.filters.mode = 'others'">Em outras equipes</button>
            </div>
            <Tabs v-if="tabs.length > 1" :tabs="tabs" :model-value="String(pool.filters.class_id)" @update:model-value="pool.filters.class_id = $event" />
            <Search v-model="pool.filters.search" placeholder="Buscar participante" />
            <LoadState
              :loading="pool.loading.value" :loaded="pool.loaded.value" :error="pool.error.value" :empty="poolItems.length === 0"
              :empty-title="pool.filters.mode === 'free' ? 'Nenhum participante disponível sem equipe' : 'Nenhum participante disponível em outra equipe'"
              @retry="pool.load()"
            >
              <div v-for="item in poolItems" :key="item.id" class="person">
                <span>{{ item.person?.full_name }}<br /><small>{{ item.class?.name || 'Sem turma' }}<template v-if="item.team"> · {{ item.team.name }}</template></small></span>
                <template v-if="editable">
                  <button v-if="!item.team" class="btn ghost small" type="button" :disabled="busy" @click="add.run(item)">Adicionar</button>
                  <button v-else class="btn ghost small" type="button" :disabled="busy" @click="askTransfer(item)">Transferir</button>
                </template>
              </div>
              <Pager :meta="pool.meta.value" :disabled="pool.loading.value" @page="pool.page.value = $event" />
            </LoadState>
          </template>
          <p v-else class="stat-hint">Sem permissão para consultar participantes.</p>
        </section>
        <aside class="card">
          <h3>Nesta equipe</h3>
          <p v-if="active.length === 0">Nenhum participante disponível nesta equipe.</p>
          <div v-for="member in active" :key="member.id" class="person">
            <span>{{ member.participant?.full_name }}<br /><small>{{ member.participant?.class?.name || 'Sem turma' }}</small></span>
            <button v-if="!wrongEvent" class="btn ghost small" type="button" :disabled="busy" @click="askRemove(member)">Remover</button>
          </div>
          <template v-if="paused.length">
            <h3 class="mt">Fora da formação</h3>
            <div v-for="member in paused" :key="member.id" class="person">
              <span>{{ member.participant?.full_name }}<br /><small>{{ label('participantStatus', member.participant?.status) }}</small></span>
              <button v-if="!wrongEvent" class="btn ghost small" type="button" :disabled="busy" @click="askRemove(member)">Remover</button>
            </div>
          </template>
        </aside>
      </div>
    </LoadState>

    <Modal v-if="confirm?.kind === 'remove'" title="Remover da equipe?" :subtitle="`${confirm.name} sai da ${team.data.value?.name}.`" @close="confirm = null">
      <p>O vínculo é encerrado e fica no histórico do participante. Ele pode entrar em outra equipe depois.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="confirm = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="remove.pending.value" @click="remove.run()">{{ remove.pending.value ? 'Salvando…' : 'Remover' }}</button>
      </template>
    </Modal>
    <Modal v-if="confirm?.kind === 'transfer'" title="Participante já está em outra equipe" :subtitle="`${confirm.name} pertence à ${confirm.from}.`" @close="confirm = null">
      <p>Transferir encerra o vínculo com a {{ confirm.from }} e coloca {{ confirm.name }} na {{ team.data.value?.name }}. As duas equipes ficam com o histórico registrado.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="confirm = null">Escolher outro</button>
        <button class="btn" type="button" :disabled="transfer.pending.value" @click="transfer.run()">{{ transfer.pending.value ? 'Salvando…' : 'Transferir para esta equipe' }}</button>
      </template>
    </Modal>
  </Page>
</template>
