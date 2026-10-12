<script setup>
// Modal de cadastro/edição de empresa. Estado e envio vêm de useCompanyForm (js/pages/company-form.js).
import Field from './Field.vue'
import Modal from './Modal.vue'
import { options } from '@/js/labels'

const props = defineProps({
  ctrl: { type: Object, required: true },
})
const { form, editing, save, close } = props.ctrl
</script>

<template>
  <Modal wide :title="editing ? 'Editar empresa' : 'Cadastrar empresa'" :subtitle="editing ? editing.name : 'Os representantes são cadastrados na página da empresa, depois de salvar.'" @close="close">
    <form novalidate @submit.prevent="save.run()">
      <h3>Empresa</h3>
      <div class="form-grid">
        <Field label="Nome da empresa" required :error="save.fieldErrors.value.name"><input v-model="form.name" class="input" maxlength="160" required /></Field>
        <Field label="Razão social" :error="save.fieldErrors.value.legal_name"><input v-model="form.legal_name" class="input" maxlength="255" /></Field>
        <Field label="CNPJ / documento" hint="Opcional. Único por evento." :error="save.fieldErrors.value.document"><input v-model="form.document" class="input" maxlength="32" /></Field>
        <Field label="Segmento" :error="save.fieldErrors.value.segment"><input v-model="form.segment" class="input" maxlength="120" /></Field>
        <Field label="Descrição" class-name="span-2" :error="save.fieldErrors.value.description"><textarea v-model="form.description" class="input" /></Field>
      </div>
      <h3>Contato</h3>
      <div class="form-grid">
        <Field label="E-mail" :error="save.fieldErrors.value.email"><input v-model="form.email" class="input" type="email" maxlength="255" /></Field>
        <Field label="Telefone" :error="save.fieldErrors.value.phone"><input v-model="form.phone" class="input" maxlength="32" /></Field>
        <Field label="Site" :error="save.fieldErrors.value.website"><input v-model="form.website" class="input" maxlength="255" /></Field>
      </div>
      <h3>Participação</h3>
      <div class="form-grid">
        <Field label="Tipo de participação" required :error="save.fieldErrors.value.type">
          <select v-model="form.type" class="input">
            <option v-for="[code, name] in options('companyType')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field v-if="!editing" label="Status inicial" hint="Depois do cadastro, o status muda na página da empresa." :error="save.fieldErrors.value.status">
          <select v-model="form.status" class="input">
            <option value="DRAFT">Rascunho</option>
            <option value="CONFIRMED">Confirmada</option>
          </select>
        </Field>
      </div>
    </form>
    <template #footer>
      <button class="btn ghost" type="button" :disabled="save.pending.value" @click="close">Cancelar</button>
      <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : editing ? 'Salvar alterações' : 'Cadastrar empresa' }}</button>
    </template>
  </Modal>
</template>
