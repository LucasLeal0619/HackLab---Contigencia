<script setup>
import Badge from '../components/Badge.vue'
import Drawer from '../components/Drawer.vue'
import Field from '../components/Field.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import { useTeams } from '@/js/pages/teams'

const {
  list, modal, editing, form, viewing, members, activeMembers, pastMembers, pausedMembers, mix,
  openDetail, openCreate, openEdit, save, statusTarget, askStatus, toggleStatus, openBuild,
  canManage, countText, label, tone, options, formatDateTime,
} = useTeams()
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page title="Equipes" subtitle="Organize as equipes participantes e a composição de cada uma.">
    <template #actions>
      <button v-if="canManage" class="btn" type="button" @click="openCreate">+ Nova equipe</button>
      <span v-else class="badge">Consulta</span>
    </template>
    <p class="stat-hint">A composição é montada participante a participante. Só participantes disponíveis entram em equipe, e cada participante fica em uma equipe por vez.</p>

    <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
      <select v-model="filters.status" class="input" aria-label="Status">
        <option value="">Todos os status</option>
        <option v-for="[code, name] in options('teamStatus')" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.has_challenge" class="input" aria-label="Desafio">
        <option value="">Com ou sem desafio</option>
        <option value="true">Com desafio</option>
        <option value="false">Sem desafio</option>
      </select>
    </FilterPanel>

    <LoadState
      :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0"
      :empty-title="list.activeFilters.value ? 'Nenhuma equipe encontrada' : 'Nenhuma equipe cadastrada'"
      :empty-text="list.activeFilters.value ? 'Ajuste ou limpe os filtros.' : 'Crie as equipes e monte a composição de cada uma.'"
      @retry="list.load()"
    >
      <template v-if="canManage && !list.activeFilters.value" #empty-action>
        <button class="btn" type="button" @click="openCreate">+ Nova equipe</button>
      </template>
      <div class="team-grid mt">
        <article v-for="team in items" :key="team.id" class="card">
          <div class="row-between">
            <strong>{{ team.name }}</strong>
            <Badge :tone="tone('teamStatus', team.status)">{{ label('teamStatus', team.status) }}</Badge>
          </div>
          <small v-if="team.code" class="stat-hint">Código {{ team.code }}</small>
          <p>{{ countText(team.members_count ?? 0, 'participante', 'participantes') }}</p>
          <p class="stat-hint">{{ team.challenge ? `Desafio: ${team.challenge.title}` : 'Sem desafio definido' }}</p>
          <div class="page-actions">
            <button class="btn ghost small" type="button" @click="openDetail(team)">Ver equipe</button>
            <button v-if="canManage && team.status === 'ACTIVE'" class="btn ghost small" type="button" @click="openBuild(team)">Montar equipe</button>
            <button v-if="canManage" class="btn ghost small" type="button" @click="openEdit(team)">Editar</button>
          </div>
        </article>
      </div>
      <Pager :meta="meta" :disabled="loading" @page="page = $event" />
    </LoadState>

    <!-- Detalhe -->
    <Drawer v-if="viewing" :title="viewing.name" :subtitle="viewing.code ? `Código ${viewing.code}` : label('teamStatus', viewing.status)" @close="viewing = null">
      <dl class="kv">
        <dt>Status</dt><dd><Badge :tone="tone('teamStatus', viewing.status)">{{ label('teamStatus', viewing.status) }}</Badge></dd>
        <dt>Desafio</dt><dd>{{ viewing.challenge?.title || '—' }}</dd>
        <dt>Criada em</dt><dd>{{ formatDateTime(viewing.created_at) }}</dd>
      </dl>
      <LoadState :loading="members.loading.value" :loaded="members.loaded.value" :error="members.error.value" @retry="members.load()">
        <h3 class="mt">{{ countText(activeMembers.length, 'participante', 'participantes') }}</h3>
        <ul v-if="mix.length" class="team-mix">
          <li v-for="[name, total] in mix" :key="name"><span>{{ name }}</span><b>{{ total }}</b></li>
        </ul>
        <p v-if="pausedMembers.length" class="banner warn mt">Atenção. {{ countText(pausedMembers.length, 'membro não está disponível', 'membros não estão disponíveis') }}. Nenhuma alteração é feita automaticamente.</p>
        <p v-if="!activeMembers.length" class="stat-hint">Nenhum participante nesta equipe.</p>
        <div v-for="member in activeMembers" :key="member.id" class="person">
          <span>{{ member.participant?.full_name || '—' }}<br /><small>{{ member.participant?.class?.name || 'Sem turma' }} · desde {{ formatDateTime(member.joined_at) }}</small></span>
          <Badge :tone="tone('participantStatus', member.participant?.status)">{{ label('participantStatus', member.participant?.status) }}</Badge>
        </div>
        <template v-if="pastMembers.length">
          <h3 class="mt">Histórico de membros</h3>
          <div v-for="member in pastMembers" :key="member.id" class="person">
            <span>{{ member.participant?.full_name || '—' }}<br /><small>{{ formatDateTime(member.joined_at) }} – {{ formatDateTime(member.left_at) }}</small></span>
            <Badge>Saiu</Badge>
          </div>
        </template>
      </LoadState>
      <template #footer>
        <button v-if="canManage" class="btn" :class="viewing.status === 'ACTIVE' ? 'danger' : 'ghost'" type="button" @click="askStatus(viewing)">{{ viewing.status === 'ACTIVE' ? 'Inativar equipe' : 'Reativar equipe' }}</button>
        <button v-if="canManage" class="btn ghost" type="button" @click="openEdit(viewing)">Editar</button>
        <button v-if="canManage && viewing.status === 'ACTIVE'" class="btn" type="button" @click="openBuild(viewing)">Montar equipe</button>
        <button v-if="!canManage" class="btn ghost" type="button" @click="viewing = null">Fechar</button>
      </template>
    </Drawer>

    <!-- Criar / editar -->
    <Modal v-if="modal === 'form'" :title="editing ? 'Editar equipe' : 'Nova equipe'" subtitle="Nome e código são únicos no evento. O desafio é definido na distribuição de desafios." @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="save.run()">
        <Field label="Nome da equipe" required :error="save.fieldErrors.value.name"><input v-model="form.name" class="input" maxlength="120" required /></Field>
        <Field label="Código" hint="Opcional. Ex.: EQ01" :error="save.fieldErrors.value.code"><input v-model="form.code" class="input" maxlength="32" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : (editing ? 'Salvar alterações' : 'Criar equipe') }}</button>
      </template>
    </Modal>

    <!-- Inativar / reativar -->
    <Modal v-if="modal === 'status' && statusTarget" :title="statusTarget.status === 'ACTIVE' ? 'Inativar equipe?' : 'Reativar equipe?'" :subtitle="statusTarget.name" @close="modal = null">
      <template v-if="statusTarget.status === 'ACTIVE'">
        <p>A equipe deixa de receber participantes. O cadastro e o histórico continuam registrados.</p>
        <p class="stat-hint">Só é possível inativar uma equipe sem membros ativos: remova ou mova os participantes antes.</p>
      </template>
      <p v-else>A equipe volta a poder receber participantes.</p>
      <p v-if="toggleStatus.fieldErrors.value.status" class="banner err">{{ toggleStatus.fieldErrors.value.status }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" :class="{ danger: statusTarget.status === 'ACTIVE' }" type="button" :disabled="toggleStatus.pending.value" @click="toggleStatus.run()">{{ toggleStatus.pending.value ? 'Salvando…' : (statusTarget.status === 'ACTIVE' ? 'Inativar' : 'Reativar') }}</button>
      </template>
    </Modal>
  </Page>
</template>
