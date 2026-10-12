<script setup>
import Badge from '../components/Badge.vue'
import Field from '../components/Field.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import { useMeetings } from '@/js/pages/meetings'

defineProps({
  params: { type: Object, default: () => ({}) },
})
const { access, sectors, activeSectors, list, editor, go, label, tone, options, formatDateTime } = useMeetings()
const { filters, page, items, meta, loading, error, loaded } = list
const { form, save } = editor
</script>

<template>
  <Page title="Reuniões" subtitle="Reuniões gerais da organização e reuniões de cada setor.">
    <template #actions>
      <button v-if="access.canManageAny.value" class="btn" type="button" @click="editor.openCreate()">Nova reunião</button>
      <Badge v-else>Consulta</Badge>
    </template>

    <p class="inline-links">
      <button class="linkish" type="button" @click="go('pendencias')">Pendências da organização</button>
      <button class="linkish" type="button" @click="go('setores')">Setores</button>
    </p>

    <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
      <select v-model="filters.place" class="input" aria-label="Tipo de reunião">
        <option value="">Todas as reuniões</option>
        <option value="general">Somente gerais</option>
        <option v-for="item in sectors.data.value" :key="item.id" :value="item.id">{{ item.name }}{{ item.active ? '' : ' (inativo)' }}</option>
      </select>
    </FilterPanel>

    <LoadState
      :loading="loading"
      :loaded="loaded"
      :error="error"
      :empty="items.length === 0"
      empty-title="Nenhuma reunião encontrada"
      :empty-text="access.canManageAny.value ? 'Agende a primeira reunião.' : ''"
      @retry="list.load()"
    >
      <div class="table-wrap">
        <table>
          <thead><tr><th>Reunião</th><th>Data e hora</th><th>Tipo</th><th>Status</th><th>Ações</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td class="meeting-title">{{ item.title }}<small v-if="item.location" class="stat-hint">{{ item.location }}</small></td>
              <td>{{ formatDateTime(item.scheduled_at) }}</td>
              <td>
                <span class="meeting-scope">
                  <Badge :tone="tone('meetingScope', item.scope)">{{ label('meetingScope', item.scope) }}</Badge>
                  <small v-if="item.sector" class="stat-hint">{{ item.sector.name }}</small>
                </span>
              </td>
              <td><Badge :tone="tone('meetingStatus', item.status)">{{ label('meetingStatus', item.status) }}</Badge></td>
              <td>
                <div class="row-actions">
                  <button class="btn ghost small" type="button" @click="go(`reuniao?id=${item.id}`)">Visualizar</button>
                  <button v-if="access.canManage(item)" class="btn ghost small" type="button" @click="editor.openEdit(item)">Editar</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="meta" :disabled="loading" @page="page = $event" />
    </LoadState>

    <Modal v-if="editor.open.value" wide :title="editor.editing.value ? 'Editar reunião' : 'Nova reunião'" :subtitle="editor.editing.value?.title || 'Horário no fuso do evento.'" @close="editor.close()">
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
          <small v-if="!access.globalScope.value" class="stat-hint">Seu perfil agenda reuniões do próprio setor.</small>
        </div>
        <Field v-if="form.scope === 'SECTOR'" label="Setor" required :error="save.fieldErrors.value.sector_id">
          <select v-model="form.sector_id" class="input">
            <option value="">Selecione</option>
            <option v-if="editor.editing.value?.sector && !activeSectors.some((item) => item.id === editor.editing.value.sector.id)" :value="editor.editing.value.sector.id">{{ editor.editing.value.sector.name }} (inativo)</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Data e hora" required hint="No fuso do evento." :error="save.fieldErrors.value.scheduled_at"><input v-model="form.scheduled_at" class="input" type="datetime-local" required /></Field>
        <Field label="Local" :error="save.fieldErrors.value.location"><input v-model="form.location" class="input" maxlength="255" placeholder="Sala, link ou endereço" /></Field>
        <Field v-if="editor.editing.value" label="Status" :error="save.fieldErrors.value.status">
          <select v-model="form.status" class="input">
            <option v-for="[code, name] in options('meetingStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field label="Pauta / descrição" class-name="span-2" :error="save.fieldErrors.value.description"><textarea v-model="form.description" class="input" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="editor.close()">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : editor.editing.value ? 'Salvar alterações' : 'Agendar reunião' }}</button>
      </template>
    </Modal>
  </Page>
</template>
