<script setup>
import Field from './Field.vue'
import Modal from './Modal.vue'
import { useDemandActivity } from '@/js/components/demand-activity'

// Setores, histórico real (DemandInteraction) e comentários de uma pendência/ocorrência.
// Ações só conforme `abilities` (meta.abilities do detalhe); o backend sempre revalida.
const props = defineProps({
  kind: { type: String, required: true }, // task | occurrence
  demand: { type: Object, required: true },
  abilities: { type: Object, default: () => ({}) },
  sectors: { type: Array, default: () => [] },
  closed: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])
const { kind, involved, history, canComment, canForward, message, forwarding, targets, sectorName, describe, send, openForward, forward, label, formatDateTime } = useDemandActivity(props, emit)
</script>

<template>
  <section class="demand-activity" aria-label="Setores e histórico">
    <h3 class="ops-title">Setores</h3>
    <dl class="detail-list">
      <dt>Origem</dt><dd>{{ sectorName(demand.origin_sector) }}</dd>
      <dt>Responsável</dt><dd><b>{{ sectorName(demand.responsible_sector) }}</b></dd>
      <dt>Envolvidos</dt><dd>{{ involved.length ? involved.join(', ') : '—' }}</dd>
    </dl>
    <button v-if="canForward" class="btn ghost small demand-forward" type="button" @click="openForward">Encaminhar</button>

    <h3 class="ops-title">Histórico</h3>
    <form v-if="canComment" class="demand-comment" @submit.prevent="send.run()">
      <label class="sr-only" :for="`demand-message-${demand.id}`">Escrever atualização</label>
      <textarea :id="`demand-message-${demand.id}`" v-model="message" class="input" rows="2" maxlength="5000" placeholder="Escrever atualização..." />
      <small v-if="send.fieldErrors.value.message" class="field-error">{{ send.fieldErrors.value.message }}</small>
      <button class="btn" type="submit" :disabled="!message.trim() || send.pending.value">{{ send.pending.value ? 'Enviando…' : 'Enviar' }}</button>
    </form>
    <p v-if="history.length === 0" class="stat-hint">Nenhuma interação registrada.</p>
    <ol v-else class="demand-history">
      <li v-for="entry in history" :key="entry.id" :class="{ comment: entry.type === 'COMMENT' }">
        <div class="demand-history-head">
          <b>{{ entry.user?.name || 'Sistema' }}</b>
          <span>{{ label('interaction', entry.type) }}</span>
          <time :datetime="entry.created_at">{{ formatDateTime(entry.created_at) }}</time>
        </div>
        <p>{{ describe(entry) }}</p>
      </li>
    </ol>

    <Modal v-if="forwarding" :title="`Encaminhar ${kind.noun}`" subtitle="O setor de origem e os envolvidos continuam acompanhando. O responsável individual é removido." @close="forwarding = null">
      <form class="form-grid" novalidate @submit.prevent="forward.run()">
        <Field label="Novo setor responsável" required class-name="span-2" :error="forward.fieldErrors.value.sector_id">
          <select v-model="forwarding.sector_id" class="input">
            <option value="">Selecione o setor</option>
            <option v-for="sector in targets" :key="sector.id" :value="sector.id">{{ sector.name }}</option>
          </select>
        </Field>
        <Field label="Motivo" required class-name="span-2" :error="forward.fieldErrors.value.reason">
          <textarea v-model="forwarding.reason" class="input" rows="3" maxlength="2000" />
        </Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="forwarding = null">Cancelar</button>
        <button class="btn" type="button" :disabled="forward.pending.value || !forwarding.sector_id || !forwarding.reason.trim()" @click="forward.run()">{{ forward.pending.value ? 'Encaminhando…' : 'Encaminhar' }}</button>
      </template>
    </Modal>
  </section>
</template>
