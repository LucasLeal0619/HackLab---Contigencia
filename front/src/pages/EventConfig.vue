<script setup>
import Even3PageQueue from '../components/Even3PageQueue.vue'
import Badge from '../components/Badge.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import { useEventConfig } from '@/js/pages/event-config'

const { canManage, creating, form, days, sortedDays, timezones, save, startCreate, cancel, dayModal, openDay, saveDay, activeEvent, label, options, tone, formatDate } = useEventConfig()
</script>

<template>
  <Page title="Evento" :subtitle="creating ? 'Cadastre um novo evento.' : 'Informações gerais do Hackathon, datas e dias do evento.'">
    <template #actions>
      <Badge v-if="activeEvent && !creating" :tone="tone('eventStatus', activeEvent.status)">{{ label('eventStatus', activeEvent.status) }}</Badge>
      <button v-if="canManage && !creating" class="btn ghost" type="button" @click="startCreate">Novo evento</button>
    </template>
    <Even3PageQueue v-if="!creating" fila="evento" />

    <Empty v-if="!activeEvent && !creating" title="Nenhum evento cadastrado" text="Cadastre o evento para começar a organizar participantes, equipes e setores.">
      <template v-if="canManage" #action><button class="btn" type="button" @click="startCreate">Cadastrar evento</button></template>
    </Empty>

    <form v-else novalidate @submit.prevent="save.run()">
      <section class="card form-section">
        <h3>Informações gerais</h3>
        <div class="form-grid">
          <Field label="Nome" required :error="save.fieldErrors.value.name"><input v-model="form.name" class="input" :disabled="!canManage" required /></Field>
          <Field label="Status" :error="save.fieldErrors.value.status">
            <select v-model="form.status" class="input" :disabled="!canManage">
              <option v-for="[code, name] in options('eventStatus')" :key="code" :value="code">{{ name }}</option>
            </select>
          </Field>
          <Field label="Descrição" class-name="span-2" :error="save.fieldErrors.value.description"><textarea v-model="form.description" class="input" :disabled="!canManage" placeholder="Descreva brevemente o Hackathon." /></Field>
          <Field label="Local" :error="save.fieldErrors.value.location"><input v-model="form.location" class="input" :disabled="!canManage" /></Field>
          <Field label="Fuso horário" hint="Usado para exibir horários e associar check-ins aos dias." :error="save.fieldErrors.value.timezone">
            <select v-model="form.timezone" class="input" :disabled="!canManage">
              <option v-if="form.timezone && !timezones.includes(form.timezone)" :value="form.timezone">{{ form.timezone }}</option>
              <option v-for="item in timezones" :key="item" :value="item">{{ item }}</option>
            </select>
          </Field>
        </div>
      </section>
      <section class="card form-section">
        <h3>Datas</h3>
        <div class="form-grid">
          <Field label="Início" required :error="save.fieldErrors.value.start_date"><input v-model="form.start_date" class="input" type="date" :disabled="!canManage" required /></Field>
          <Field label="Término" required :error="save.fieldErrors.value.end_date"><input v-model="form.end_date" class="input" type="date" :disabled="!canManage" required /></Field>
        </div>
      </section>
      <div class="page-actions" style="margin-top: 12px">
        <button type="button" class="btn ghost" :disabled="save.pending.value" @click="cancel">Cancelar</button>
        <button v-if="canManage" class="btn" type="submit" :disabled="save.pending.value">{{ save.pending.value ? 'Salvando…' : creating ? 'Cadastrar evento' : 'Salvar alterações' }}</button>
      </div>
      <p v-if="!canManage" class="stat-hint">Este perfil consulta a configuração do evento.</p>
    </form>

    <section v-if="activeEvent && !creating" class="card form-section mt">
      <div class="row-between">
        <h3>Dias do evento</h3>
        <button v-if="canManage" class="btn ghost small" type="button" @click="openDay(null)">Adicionar dia</button>
      </div>
      <LoadState :loading="days.loading.value" :loaded="days.loaded.value" :error="days.error.value" :empty="sortedDays.length === 0" empty-title="Nenhum dia cadastrado" empty-text="Cadastre os dias do evento com data e horário." @retry="days.load()">
        <div class="day-cards">
          <div v-for="day in sortedDays" :key="day.id" class="day-card">
            <b>{{ day.label || `Dia ${day.day_number}` }}</b>
            {{ formatDate(day.date) }}<template v-if="day.start_time"> · {{ day.start_time }}–{{ day.end_time || '—' }}</template>
            <button v-if="canManage" class="btn ghost small" type="button" @click="openDay(day)">Editar</button>
          </div>
        </div>
      </LoadState>
    </section>

    <Modal v-if="dayModal" :title="dayModal.id ? 'Editar dia' : 'Novo dia'" @close="dayModal = null">
      <form class="form-grid" novalidate @submit.prevent="saveDay.run()">
        <Field label="Número do dia" required :error="saveDay.fieldErrors.value.day_number"><input v-model="dayModal.day_number" class="input" type="number" min="1" /></Field>
        <Field label="Data" required :error="saveDay.fieldErrors.value.date"><input v-model="dayModal.date" class="input" type="date" /></Field>
        <Field label="Nome" required class-name="span-2" :error="saveDay.fieldErrors.value.label"><input v-model="dayModal.label" class="input" placeholder="Ex.: Abertura e formação" /></Field>
        <Field label="Início" :error="saveDay.fieldErrors.value.start_time"><input v-model="dayModal.start_time" class="input" type="time" /></Field>
        <Field label="Término" :error="saveDay.fieldErrors.value.end_time"><input v-model="dayModal.end_time" class="input" type="time" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="dayModal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="saveDay.pending.value" @click="saveDay.run()">{{ saveDay.pending.value ? 'Salvando…' : 'Salvar dia' }}</button>
      </template>
    </Modal>
  </Page>
</template>
