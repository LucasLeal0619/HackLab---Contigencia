<script setup>
// Modal de cadastro/edição de desafio. Estado e envio vêm de useChallengeForm (js/pages/challenge-form.js).
import Field from './Field.vue'
import Modal from './Modal.vue'
import { label } from '@/js/labels'
import { CREATABLE_STATUSES } from '@/js/pages/challenge-form'

const props = defineProps({
  ctrl: { type: Object, required: true },
})
const { form, editing, companies, companyOptions, save, close } = props.ctrl
</script>

<template>
  <Modal wide :title="editing ? 'Editar desafio' : 'Cadastrar desafio'" :subtitle="editing ? editing.title : 'A equipe é definida depois, na distribuição.'" @close="close">
    <form novalidate @submit.prevent="save.run()">
      <h3>Informações principais</h3>
      <div class="form-grid">
        <Field label="Título" required class-name="span-2" :error="save.fieldErrors.value.title"><input v-model="form.title" class="input" maxlength="255" required /></Field>
        <Field label="Empresa" :hint="companies.loading.value ? 'Carregando empresas…' : 'Opcional. Empresas inativas não recebem novos desafios.'" :error="save.fieldErrors.value.company_id || companies.error.value?.message">
          <select v-model="form.company_id" class="input" :disabled="companies.loading.value && !companies.loaded.value">
            <option value="">Sem empresa</option>
            <option v-for="item in companyOptions" :key="item.id" :value="item.id">{{ item.name }}{{ item.status === 'INACTIVE' ? ' (inativa)' : '' }}</option>
          </select>
        </Field>
        <Field v-if="!editing" label="Status inicial" hint="Distribuído em diante só pela distribuição." :error="save.fieldErrors.value.status">
          <select v-model="form.status" class="input">
            <option v-for="code in CREATABLE_STATUSES" :key="code" :value="code">{{ label('challengeStatus', code) }}</option>
          </select>
        </Field>
        <Field label="Problema" required class-name="span-2" :error="save.fieldErrors.value.problem"><textarea v-model="form.problem" class="input" required /></Field>
        <Field label="Objetivo" class-name="span-2" :error="save.fieldErrors.value.objective"><textarea v-model="form.objective" class="input" /></Field>
      </div>
      <h3>Detalhamento</h3>
      <div class="form-grid">
        <Field label="Requisitos" class-name="span-2" :error="save.fieldErrors.value.requirements"><textarea v-model="form.requirements" class="input" /></Field>
        <Field label="Restrições" class-name="span-2" :error="save.fieldErrors.value.restrictions"><textarea v-model="form.restrictions" class="input" /></Field>
        <Field label="Resultado esperado" class-name="span-2" :error="save.fieldErrors.value.expected_outcome"><textarea v-model="form.expected_outcome" class="input" /></Field>
        <Field label="Observações" class-name="span-2" :error="save.fieldErrors.value.notes"><textarea v-model="form.notes" class="input" /></Field>
      </div>
    </form>
    <template #footer>
      <button class="btn ghost" type="button" :disabled="save.pending.value" @click="close">Cancelar</button>
      <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : editing ? 'Salvar alterações' : 'Salvar desafio' }}</button>
    </template>
  </Modal>
</template>
