<script setup>
import Badge from '../components/Badge.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import { useMeetingDetail } from '@/js/pages/meeting-detail'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const { access, meeting, canManage, sectors, activeSectors, editor, openEdit, confirming, changeStatus, go, label, tone, options, formatDateTime } = useMeetingDetail(props)
const { form, save } = editor
const STATUS_ACTIONS = {
  DONE: { title: 'Marcar reunião como realizada?', text: 'A reunião passa a constar como realizada.', button: 'Marcar como realizada' },
  CANCELLED: { title: 'Cancelar reunião?', text: 'A reunião continua registrada, com status cancelada.', button: 'Cancelar reunião' },
  SCHEDULED: { title: 'Reagendar reunião?', text: 'A reunião volta ao status agendada. Ajuste a data em "Editar" se precisar.', button: 'Voltar para agendada' },
}
</script>

<template>
  <Page
    :crumbs="`Reuniões / ${meeting.data.value?.title || 'Reunião'}`"
    :title="meeting.data.value?.title || 'Reunião'"
    :subtitle="meeting.data.value ? `${label('meetingScope', meeting.data.value.scope)}${meeting.data.value.sector ? ` · ${meeting.data.value.sector.name}` : ''}` : ''"
  >
    <template #actions>
      <button class="btn ghost" type="button" @click="go('reunioes')">Voltar</button>
      <button v-if="canManage" class="btn" type="button" @click="openEdit">Editar reunião</button>
    </template>

    <LoadState :loading="meeting.loading.value" :loaded="meeting.loaded.value" :error="meeting.error.value?.kind === 'not_found' ? null : meeting.error.value" :empty="!meeting.data.value" @retry="meeting.load()">
      <template #empty>
        <Empty title="Reunião não encontrada" text="A reunião não existe ou está fora do seu escopo de acesso.">
          <template #action><button class="btn ghost" type="button" @click="go('reunioes')">Ver reuniões</button></template>
        </Empty>
      </template>

      <template v-if="meeting.data.value">
        <section class="card meeting-detail">
          <h3>Informações</h3>
          <dl class="kv">
            <dt>Status</dt><dd><Badge :tone="tone('meetingStatus', meeting.data.value.status)">{{ label('meetingStatus', meeting.data.value.status) }}</Badge></dd>
            <dt>Tipo</dt><dd><Badge :tone="tone('meetingScope', meeting.data.value.scope)">{{ label('meetingScope', meeting.data.value.scope) }}</Badge></dd>
            <dt>Setor</dt><dd>{{ meeting.data.value.sector?.name || 'Reunião geral (todos os setores)' }}</dd>
            <dt>Data e hora</dt><dd>{{ formatDateTime(meeting.data.value.scheduled_at) }}</dd>
            <dt>Local</dt><dd>{{ meeting.data.value.location || '—' }}</dd>
            <dt>Criada por</dt><dd>{{ meeting.data.value.created_by?.name || '—' }}</dd>
            <dt>Criada em</dt><dd>{{ formatDateTime(meeting.data.value.created_at) }}</dd>
            <dt>Atualizada em</dt><dd>{{ formatDateTime(meeting.data.value.updated_at) }}</dd>
          </dl>
          <div v-if="canManage" class="meeting-status-actions">
            <button v-if="meeting.data.value.status !== 'DONE'" class="btn ghost small" type="button" @click="confirming = 'DONE'">Marcar como realizada</button>
            <button v-if="meeting.data.value.status !== 'CANCELLED'" class="btn ghost small" type="button" @click="confirming = 'CANCELLED'">Cancelar reunião</button>
            <button v-if="meeting.data.value.status !== 'SCHEDULED'" class="btn ghost small" type="button" @click="confirming = 'SCHEDULED'">Voltar para agendada</button>
          </div>
          <p v-else-if="!access.canManageAny.value" class="stat-hint mt">Este perfil consulta as reuniões.</p>
        </section>

        <section class="card mt">
          <h3>Pauta / descrição</h3>
          <p class="meeting-description">{{ meeting.data.value.description || 'Sem pauta registrada.' }}</p>
        </section>

        <p class="stat-hint mt">
          Pendências combinadas na reunião são registradas na tela de
          <button class="linkish" type="button" @click="go('pendencias')">Pendências</button>.
        </p>
      </template>
    </LoadState>

    <Modal v-if="confirming && meeting.data.value" :title="STATUS_ACTIONS[confirming].title" :subtitle="STATUS_ACTIONS[confirming].text" @close="confirming = null">
      <p>{{ meeting.data.value.title }} · {{ formatDateTime(meeting.data.value.scheduled_at) }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="confirming = null">Voltar</button>
        <button class="btn" :class="{ danger: confirming === 'CANCELLED' }" type="button" :disabled="changeStatus.pending.value" @click="changeStatus.run(confirming)">
          {{ changeStatus.pending.value ? 'Salvando…' : STATUS_ACTIONS[confirming].button }}
        </button>
      </template>
    </Modal>

    <Modal v-if="editor.open.value" wide title="Editar reunião" :subtitle="editor.editing.value?.title || ''" @close="editor.close()">
      <form class="form-grid" novalidate @submit.prevent="save.run()">
        <Field label="Título" required class-name="span-2" :error="save.fieldErrors.value.title"><input v-model="form.title" class="input" maxlength="255" required /></Field>
        <div class="field span-2">
          <span>Tipo</span>
          <div class="chips" role="group" aria-label="Tipo de reunião">
            <button
              v-for="code in editor.scopeChoices.value"
              :key="code"
              type="button"
              class="chip"
              :class="{ on: form.scope === code }"
              :aria-pressed="form.scope === code"
              @click="form.scope = code"
            >{{ label('meetingScope', code) }}</button>
          </div>
        </div>
        <Field v-if="form.scope === 'SECTOR'" label="Setor" required :error="save.fieldErrors.value.sector_id" :hint="sectors.loading.value ? 'Carregando setores…' : ''">
          <select v-model="form.sector_id" class="input">
            <option value="">Selecione</option>
            <option v-if="editor.editing.value?.sector && !activeSectors.some((item) => item.id === editor.editing.value.sector.id)" :value="editor.editing.value.sector.id">
              {{ editor.editing.value.sector.name }}{{ sectors.loaded.value ? ' (inativo)' : '' }}
            </option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Data e hora" required hint="No fuso do evento." :error="save.fieldErrors.value.scheduled_at"><input v-model="form.scheduled_at" class="input" type="datetime-local" required /></Field>
        <Field label="Local" :error="save.fieldErrors.value.location"><input v-model="form.location" class="input" maxlength="255" placeholder="Sala, link ou endereço" /></Field>
        <Field label="Status" :error="save.fieldErrors.value.status">
          <select v-model="form.status" class="input">
            <option v-for="[code, name] in options('meetingStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field label="Pauta / descrição" class-name="span-2" :error="save.fieldErrors.value.description"><textarea v-model="form.description" class="input" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="editor.close()">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : 'Salvar alterações' }}</button>
      </template>
    </Modal>
  </Page>
</template>
