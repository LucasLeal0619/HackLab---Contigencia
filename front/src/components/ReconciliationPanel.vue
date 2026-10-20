<script setup>
// Fila de revisão da Even3 embutida numa página (Jurados, Usuários, Empresas, Evento). Ver js/pages/reconciliation.js.
import Badge from './Badge.vue'
import CompanyFormModal from './CompanyFormModal.vue'
import Field from './Field.vue'
import LoadState from './LoadState.vue'
import Modal from './Modal.vue'
import Pager from './Pager.vue'
import Search from './Search.vue'
import { useReconciliation } from '@/js/pages/reconciliation'

const props = defineProps({
  fila: { type: String, required: true },
})
const {
  GRANT_ROLES, section, count, canAct, candidates, stateLabel,
  modal, selected, note, openIgnore, ignore, restore, companies, jurorForm, openJuror, confirmJuror,
  sectors, grantForm, grantNeedsSector, openGrant, grant, revokeGrant, linkForm, openLink, link, companyForm, createCompanyFor,
  metadata, metadataRows, chosenFields, applyMetadata, formatDate, formatDateTime,
} = useReconciliation(props)
const filters = candidates.filters
</script>

<template>
  <section class="card even3-queue" :aria-label="section.title">
    <div class="row-between even3-queue-head">
      <h3><span class="even3-tag">Even3</span> {{ section.title }}</h3>
      <Badge v-if="count(section.key)" tone="warn">{{ count(section.key) }} para revisar</Badge>
    </div>

    <!-- Evento: divergências -->
    <template v-if="section.id === 'evento'">
      <LoadState :loading="metadata.loading.value" :loaded="metadata.loaded.value" :error="metadata.error.value" :empty="!metadataRows.length" empty-title="Integração não configurada" @retry="metadata.load()">
        <p class="stat-hint">Comparado com o último retrato lido da Even3<template v-if="metadata.data.value?.synced_at"> ({{ formatDateTime(metadata.data.value.synced_at) }})</template>. Nada muda sem você escolher.</p>
        <div class="table-wrap">
          <table class="recon-diff">
            <thead><tr><th>Aplicar</th><th>Campo</th><th>HackLab</th><th>Even3</th><th></th></tr></thead>
            <tbody>
              <tr v-for="row in metadataRows" :key="row.field">
                <td><input v-model="chosenFields" type="checkbox" :value="row.field" :disabled="!row.different" :aria-label="`Aplicar ${row.label}`" /></td>
                <td>{{ row.label }}</td>
                <td>{{ row.field === 'name' ? row.hacklab : formatDate(row.hacklab) }}</td>
                <td>{{ row.even3 === null ? '—' : row.field === 'name' ? row.even3 : formatDate(row.even3) }}</td>
                <td><Badge v-if="row.different" tone="warn">Diferente</Badge><Badge v-else-if="row.even3 !== null" tone="ok">Igual</Badge></td>
              </tr>
            </tbody>
          </table>
        </div>
        <button class="btn" type="button" :disabled="!chosenFields.length || applyMetadata.pending.value" @click="applyMetadata.run()">
          {{ applyMetadata.pending.value ? 'Aplicando…' : 'Aplicar selecionados' }}
        </button>
        <p v-if="applyMetadata.fieldErrors.value.start_date || applyMetadata.fieldErrors.value.end_date" class="banner warn">
          {{ applyMetadata.fieldErrors.value.start_date || applyMetadata.fieldErrors.value.end_date }} Ajuste os dias do evento antes de mudar as datas.
        </p>
      </LoadState>
    </template>

    <!-- Filas de pessoas -->
    <template v-else>
      <p class="stat-hint">Vieram da Even3 na última sincronização. Nada é criado sozinho: confirme, vincule, libere acesso ou ignore.</p>
      <div class="recon-bar">
        <div class="chips" role="tablist" aria-label="Visão">
          <button v-for="[id, name] in [['pending', 'Pendentes'], ['ignored', 'Ignorados'], ['all', 'Todos']]" :key="id" type="button" class="chip" :class="{ on: filters.view === id }" @click="filters.view = id">{{ name }}</button>
        </div>
        <Search v-model="filters.search" placeholder="Buscar por nome ou e-mail" />
      </div>
      <LoadState :loading="candidates.loading.value" :loaded="candidates.loaded.value" :error="candidates.error.value" :empty="candidates.items.value.length === 0"
        :empty-title="filters.view === 'ignored' ? 'Nenhum candidato ignorado' : filters.view === 'all' ? 'Nenhum inscrito nesta categoria' : 'Nada para revisar'" @retry="candidates.load()">
        <ul class="recon-list">
          <li v-for="item in candidates.items.value" :key="item.person.id" class="recon-item">
            <div>
              <b>{{ item.person.full_name }}</b>
              <small class="stat-hint">{{ item.person.email || 'sem e-mail' }} · Inscrição: {{ item.tickets.join(', ') || section.label }}</small>
              <small><Badge :tone="item.state === 'PENDING' ? 'warn' : item.state === 'IGNORED' ? '' : 'ok'">{{ stateLabel(item) }}</Badge></small>
              <small v-if="item.decision?.note" class="stat-hint">Nota: {{ item.decision.note }}</small>
            </div>
            <div class="recon-item-actions">
              <template v-if="item.state === 'IGNORED'">
                <button class="btn ghost small" type="button" :disabled="restore.pending.value" @click="restore.run(item)">Restaurar</button>
              </template>
              <template v-else-if="item.state === 'PENDING' && canAct">
                <button v-if="section.id === 'jurados'" class="btn small" type="button" @click="openJuror(item)">Cadastrar como Jurado</button>
                <button v-if="section.id === 'organizacao'" class="btn small" type="button" :disabled="!item.person.email" :title="item.person.email ? '' : 'Sem e-mail não há primeiro acesso.'" @click="openGrant(item)">Liberar acesso</button>
                <template v-if="section.id === 'empresas'">
                  <button class="btn small" type="button" @click="openLink(item)">Vincular a empresa existente</button>
                  <button class="btn ghost small" type="button" @click="createCompanyFor(item)">Criar empresa e vincular</button>
                </template>
                <button class="btn ghost small" type="button" @click="openIgnore(item)">Ignorar</button>
              </template>
              <button v-else-if="section.id === 'organizacao' && item.internal?.grant" class="btn ghost small" type="button" :disabled="revokeGrant.pending.value" @click="revokeGrant.run(item)">Revogar liberação</button>
            </div>
          </li>
        </ul>
        <Pager :meta="candidates.meta.value" :disabled="candidates.loading.value" @page="candidates.page.value = $event" />
      </LoadState>
    </template>

    <!-- Modais -->
    <Modal v-if="modal === 'ignore' && selected" title="Ignorar candidato" :subtitle="selected.person.full_name" @close="modal = null">
      <Field label="Nota (opcional)" :error="ignore.fieldErrors.value.note">
        <textarea v-model="note" class="input" rows="3" maxlength="1000" />
      </Field>
      <p class="stat-hint">Sai da lista. Você pode restaurar depois em "Ignorados". Nada interno é alterado.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="ignore.pending.value" @click="ignore.run()">Ignorar</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'juror' && selected" title="Cadastrar como Jurado" :subtitle="selected.person.full_name" @close="modal = null">
      <Field label="Empresa (opcional)" :error="confirmJuror.fieldErrors.value.company_id">
        <select v-model="jurorForm.company_id" class="input">
          <option value="">Sem empresa</option>
          <option v-for="item in companies.data.value.filter((c) => c.status !== 'INACTIVE')" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
        </select>
      </Field>
      <Field label="Observações (opcional)" :error="confirmJuror.fieldErrors.value.notes">
        <textarea v-model="jurorForm.notes" class="input" rows="3" maxlength="2000" />
      </Field>
      <p v-if="confirmJuror.fieldErrors.value.person" class="banner warn">{{ confirmJuror.fieldErrors.value.person }}</p>
      <p class="stat-hint">Cria o Jurado do evento. Não cria conta: a pessoa ativa o acesso pelo primeiro acesso. As equipes são atribuídas aqui na tela de Jurados.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="confirmJuror.pending.value" @click="confirmJuror.run()">{{ confirmJuror.pending.value ? 'Cadastrando…' : 'Cadastrar como Jurado' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'grant' && selected" title="Liberar acesso" :subtitle="`${selected.person.full_name} · ${selected.person.email}`" @close="modal = null">
      <Field label="Perfil" required :error="grant.fieldErrors.value.role">
        <select v-model="grantForm.role" class="input">
          <option v-for="[code, name] in GRANT_ROLES" :key="code" :value="code">{{ name }}</option>
        </select>
      </Field>
      <Field v-if="grantNeedsSector" label="Setor" required :error="grant.fieldErrors.value.sector_id">
        <select v-model="grantForm.sector_id" class="input">
          <option value="">Escolha o setor</option>
          <option v-for="item in sectors.data.value" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
        </select>
      </Field>
      <p v-if="grant.fieldErrors.value.person" class="banner warn">{{ grant.fieldErrors.value.person }}</p>
      <p class="stat-hint">Sem senha: a pessoa recebe o link pelo "Primeiro acesso" com este e-mail e cria a própria senha. Você pode revogar enquanto ela não ativar.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="grant.pending.value || (grantNeedsSector && !grantForm.sector_id)" @click="grant.run()">{{ grant.pending.value ? 'Liberando…' : 'Liberar acesso' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'link' && selected" title="Vincular a empresa existente" :subtitle="selected.person.full_name" @close="modal = null">
      <Field label="Empresa" required :error="link.fieldErrors.value.company_id || link.fieldErrors.value.company">
        <select v-model="linkForm.company_id" class="input">
          <option value="">Escolha a empresa</option>
          <option v-for="item in companies.data.value" :key="item.id" :value="String(item.id)" :disabled="item.status === 'INACTIVE'">{{ item.name }}{{ item.status === 'INACTIVE' ? ' (inativa)' : '' }}</option>
        </select>
      </Field>
      <Field label="Cargo (opcional)" :error="link.fieldErrors.value.title">
        <input v-model="linkForm.title" class="input" maxlength="120" />
      </Field>
      <label class="check"><input v-model="linkForm.is_primary" type="checkbox" /> Representante principal</label>
      <p v-if="link.fieldErrors.value.person || link.fieldErrors.value.is_primary" class="banner warn">{{ link.fieldErrors.value.person || link.fieldErrors.value.is_primary }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="link.pending.value || !linkForm.company_id" @click="link.run()">{{ link.pending.value ? 'Vinculando…' : 'Vincular' }}</button>
      </template>
    </Modal>

    <CompanyFormModal v-if="companyForm.open.value" :ctrl="companyForm" />
  </section>
</template>
