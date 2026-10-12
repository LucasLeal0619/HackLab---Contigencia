<script setup>
import Badge from '../components/Badge.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Stat from '../components/Stat.vue'
import { useSectors } from '@/js/pages/sectors'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  sectors, statusFilter, visibleSectors, mySectors, otherSectors, opened, openedId, showBack, globalScope,
  canCreate, canChangeStatus, canEdit, canTasks, canOccurrences, canMeetings, canUsers, selectSector,
  relation, tasks, occurrences, meetings, users, usersOpen, toggleUsers,
  taskSummary, occurrenceSummary, meetingSummary, overdueTasks, urgentOccurrences, countText,
  taskPreview, occurrencePreview, meetingPreview, openTasks, openOccurrences,
  modal, selected, form, openCreate, openEdit, askStatus, save, toggleStatus, go, label, tone, formatDateTime,
} = useSectors(props)
</script>

<template>
  <!-- Lista de setores -->
  <Page v-if="!openedId" title="Setores" subtitle="Áreas responsáveis pela organização do Hackathon.">
    <template v-if="canCreate" #actions>
      <button class="btn" type="button" @click="openCreate">Novo setor</button>
    </template>

    <div class="row-between sector-toolbar">
      <p class="stat-hint">Abra um setor para ver as pendências, ocorrências e reuniões ligadas a ele.</p>
      <select v-if="globalScope" v-model="statusFilter" class="input" aria-label="Status do setor">
        <option value="">Todos os status</option>
        <option value="ACTIVE">Ativos</option>
        <option value="INACTIVE">Inativos</option>
      </select>
    </div>

    <LoadState
      :loading="sectors.loading.value"
      :loaded="sectors.loaded.value"
      :error="sectors.error.value"
      :empty="visibleSectors.length === 0"
      empty-title="Nenhum setor encontrado"
      :empty-text="canCreate ? 'Cadastre o primeiro setor do evento.' : 'Nenhum setor disponível para o seu perfil.'"
      @retry="sectors.load()"
    >
      <template v-if="mySectors.length">
        <h3 class="ops-title">Meu setor</h3>
        <div class="grid cols-3">
          <article v-for="item in mySectors" :key="item.id" class="card sector-card">
            <h3>{{ item.name }}</h3>
            <p><Badge :tone="tone('activeStatus', item.active ? 'ACTIVE' : 'INACTIVE')">{{ label('activeStatus', item.active ? 'ACTIVE' : 'INACTIVE') }}</Badge></p>
            <p class="sector-desc">{{ item.description || 'Sem descrição.' }}</p>
            <div class="row-actions">
              <button class="btn small" type="button" @click="selectSector(item)">Abrir setor</button>
              <button v-if="canEdit(item)" class="btn ghost small" type="button" @click="openEdit(item)">Editar</button>
            </div>
          </article>
        </div>
      </template>
      <template v-if="otherSectors.length">
        <h3 v-if="mySectors.length" class="ops-title">Demais setores</h3>
        <div class="grid cols-3">
          <article v-for="item in otherSectors" :key="item.id" class="card sector-card" :class="{ inactive: !item.active }">
            <h3>{{ item.name }}</h3>
            <p><Badge :tone="tone('activeStatus', item.active ? 'ACTIVE' : 'INACTIVE')">{{ label('activeStatus', item.active ? 'ACTIVE' : 'INACTIVE') }}</Badge></p>
            <p class="sector-desc">{{ item.description || 'Sem descrição.' }}</p>
            <div class="row-actions">
              <button class="btn small" type="button" @click="selectSector(item)">Abrir setor</button>
              <button v-if="canEdit(item)" class="btn ghost small" type="button" @click="openEdit(item)">Editar</button>
              <button v-if="canChangeStatus" class="btn ghost small" type="button" @click="askStatus(item)">{{ item.active ? 'Inativar' : 'Ativar' }}</button>
            </div>
          </article>
        </div>
      </template>
    </LoadState>
  </Page>

  <!-- Área de trabalho do setor -->
  <Page
    v-else
    :crumbs="`${globalScope ? 'Setores' : 'Meu Setor'} / ${opened?.name || 'Setor'}`"
    :title="opened?.name || 'Setor'"
    :subtitle="opened?.description || ''"
  >
    <template #actions>
      <button v-if="showBack" class="btn ghost" type="button" @click="go('setores')">Voltar</button>
      <template v-if="opened">
        <Badge :tone="tone('activeStatus', opened.active ? 'ACTIVE' : 'INACTIVE')">{{ label('activeStatus', opened.active ? 'ACTIVE' : 'INACTIVE') }}</Badge>
        <button v-if="canEdit(opened)" class="btn ghost" type="button" @click="openEdit(opened)">Editar setor</button>
        <button v-if="canChangeStatus" class="btn ghost" type="button" @click="askStatus(opened)">{{ opened.active ? 'Inativar' : 'Ativar' }}</button>
      </template>
    </template>

    <LoadState :loading="sectors.loading.value" :loaded="sectors.loaded.value" :error="sectors.error.value" :empty="!opened" @retry="sectors.load()">
      <template #empty>
        <Empty title="Setor não encontrado" text="O setor não existe neste evento ou está fora do seu escopo de acesso.">
          <template #action><button class="btn ghost" type="button" @click="go('setores')">Ver setores</button></template>
        </Empty>
      </template>

      <p v-if="opened && !opened.active" class="banner warn" role="status">Setor inativo: não pode receber novas reuniões, pendências, ocorrências nem usuários. O histórico continua visível.</p>

      <div class="grid cols-3 sector-stats">
        <Stat v-if="canTasks" icon="check" label="Pendências abertas" :value="tasks.loaded.value ? countText(taskSummary, taskSummary.open.length) : '…'" :hint="tasks.loaded.value ? `${overdueTasks} atrasada(s) · ${taskSummary.total} no total` : ''" />
        <Stat v-if="canOccurrences" icon="alert" label="Ocorrências abertas" :value="occurrences.loaded.value ? countText(occurrenceSummary, occurrenceSummary.open.length) : '…'" :hint="occurrences.loaded.value ? `${urgentOccurrences} alta(s)/urgente(s) · ${occurrenceSummary.total} no total` : ''" />
        <Stat v-if="canMeetings" icon="calendar" label="Próximas reuniões" :value="meetings.loaded.value ? countText(meetingSummary, meetingSummary.upcoming.length) : '…'" :hint="meetings.loaded.value ? `${meetingSummary.total} reunião(ões) do setor` : ''" />
      </div>

      <div v-if="canTasks || canOccurrences" class="row-between mt">
        <span class="stat-hint">Pendências e ocorrências em que o setor é:</span>
        <div class="chips" role="group" aria-label="Relação do setor">
          <button type="button" class="chip" :class="{ on: relation === 'responsible' }" :aria-pressed="relation === 'responsible'" @click="relation = 'responsible'">Responsável</button>
          <button type="button" class="chip" :class="{ on: relation === 'involved' }" :aria-pressed="relation === 'involved'" @click="relation = 'involved'">Envolvido</button>
        </div>
      </div>

      <div class="grid cols-2 mt sector-blocks">
        <section v-if="canTasks" class="card">
          <div class="row-between">
            <h3>Pendências</h3>
            <button class="btn ghost small" type="button" @click="openTasks">Ver todas</button>
          </div>
          <LoadState :loading="tasks.loading.value" :loaded="tasks.loaded.value" :error="tasks.error.value" :empty="taskPreview.length === 0" empty-title="Nenhuma pendência para este setor." @retry="tasks.load()">
            <ul class="related-list">
              <li v-for="item in taskPreview" :key="item.id">
                <span><b>{{ item.title }}</b><br /><small class="stat-hint">{{ item.reference }} · Prazo {{ formatDateTime(item.due_at) }}<template v-if="item.overdue"> · <span class="due late">Atrasada</span></template></small></span>
                <Badge :tone="tone('priority', item.priority)">{{ label('priority', item.priority) }}</Badge>
                <Badge :tone="tone('taskStatus', item.status)">{{ label('taskStatus', item.status) }}</Badge>
              </li>
            </ul>
          </LoadState>
        </section>

        <section v-if="canOccurrences" class="card">
          <div class="row-between">
            <h3>Ocorrências</h3>
            <button class="btn ghost small" type="button" @click="openOccurrences">Ver todas</button>
          </div>
          <LoadState :loading="occurrences.loading.value" :loaded="occurrences.loaded.value" :error="occurrences.error.value" :empty="occurrencePreview.length === 0" empty-title="Nenhuma ocorrência para este setor." @retry="occurrences.load()">
            <ul class="related-list">
              <li v-for="item in occurrencePreview" :key="item.id">
                <span><b>{{ item.title }}</b><br /><small class="stat-hint">{{ item.reference }} · {{ label('occurrenceCategory', item.category) }} · {{ formatDateTime(item.occurred_at) }}</small></span>
                <Badge :tone="tone('priority', item.priority)">{{ label('priority', item.priority) }}</Badge>
                <Badge :tone="tone('occurrenceStatus', item.status)">{{ label('occurrenceStatus', item.status) }}</Badge>
              </li>
            </ul>
          </LoadState>
        </section>

        <section v-if="canMeetings" class="card">
          <div class="row-between">
            <h3>Reuniões do setor</h3>
            <button class="btn ghost small" type="button" @click="go('reunioes')">Ver reuniões</button>
          </div>
          <LoadState :loading="meetings.loading.value" :loaded="meetings.loaded.value" :error="meetings.error.value" :empty="meetingPreview.length === 0" empty-title="Nenhuma reunião deste setor." @retry="meetings.load()">
            <ul class="related-list">
              <li v-for="item in meetingPreview" :key="item.id">
                <span><b>{{ item.title }}</b><br /><small class="stat-hint">{{ formatDateTime(item.scheduled_at) }}<template v-if="item.location"> · {{ item.location }}</template></small></span>
                <Badge :tone="tone('meetingStatus', item.status)">{{ label('meetingStatus', item.status) }}</Badge>
                <button class="btn ghost small" type="button" @click="go(`reuniao?id=${item.id}`)">Ver</button>
              </li>
            </ul>
          </LoadState>
        </section>

        <section v-if="canUsers" class="card">
          <div class="row-between">
            <h3>Usuários do setor</h3>
            <button class="btn ghost small" type="button" :aria-expanded="usersOpen" @click="toggleUsers">{{ usersOpen ? 'Ocultar' : 'Mostrar' }}</button>
          </div>
          <p v-if="!usersOpen" class="stat-hint">Contas de Gestor e Editor vinculadas a este setor.</p>
          <LoadState v-else :loading="users.loading.value" :loaded="users.loaded.value" :error="users.error.value" :empty="!(users.data.value?.items || []).length" empty-title="Nenhum usuário vinculado a este setor." @retry="users.load()">
            <ul class="related-list">
              <li v-for="item in users.data.value?.items || []" :key="item.id">
                <span><b>{{ item.person?.full_name || item.email }}</b><br /><small class="stat-hint">{{ item.email }}</small></span>
                <Badge>{{ item.role?.name || label('role', item.role?.code) }}</Badge>
                <Badge :tone="tone('activeStatus', item.status)">{{ label('activeStatus', item.status) }}</Badge>
              </li>
            </ul>
          </LoadState>
        </section>
      </div>
      <p class="stat-hint mt">Resumo somente leitura. Pendências e ocorrências são criadas, encaminhadas e resolvidas nas telas próprias.</p>
    </LoadState>
  </Page>

  <Modal v-if="modal === 'form'" :title="selected ? 'Editar setor' : 'Novo setor'" :subtitle="selected ? selected.name : 'O nome do setor é único neste evento.'" @close="modal = null">
    <form class="form-grid" novalidate @submit.prevent="save.run()">
      <Field label="Nome" required class-name="span-2" :error="save.fieldErrors.value.name"><input v-model="form.name" class="input" maxlength="120" required /></Field>
      <Field label="Descrição" class-name="span-2" :error="save.fieldErrors.value.description"><textarea v-model="form.description" class="input" placeholder="O que este setor faz no Hackathon." /></Field>
    </form>
    <template #footer>
      <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
      <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : selected ? 'Salvar alterações' : 'Cadastrar setor' }}</button>
    </template>
  </Modal>

  <Modal
    v-if="modal === 'status' && selected"
    :title="selected.active ? 'Inativar setor?' : 'Ativar setor?'"
    :subtitle="selected.active ? 'O setor deixa de receber novas reuniões e encaminhamentos. O histórico continua registrado.' : 'O setor volta a receber reuniões e encaminhamentos.'"
    @close="modal = null"
  >
    <p>{{ selected.name }}</p>
    <template #footer>
      <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
      <button class="btn" :class="{ danger: selected.active }" type="button" :disabled="toggleStatus.pending.value" @click="toggleStatus.run(selected)">
        {{ toggleStatus.pending.value ? 'Salvando…' : selected.active ? 'Inativar' : 'Ativar' }}
      </button>
    </template>
  </Modal>
</template>
