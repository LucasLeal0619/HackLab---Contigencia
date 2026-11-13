<script setup>
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Stat from '../components/Stat.vue'
import { useFinance } from '@/js/pages/finance'

const {
  records, form, selected, deleting, search, categoryFilter, statusFilter, sortBy,
  previewTotal, categories, statuses, visibleItems, summary, categoryTotals,
  save, remove, resetForm, edit, formatMoney, formatQuantity, formatStatus, canManage, go,
} = useFinance()
</script>

<template>
  <Page
    crumbs="Setores / Setor Financeiro"
    title="Setor Financeiro"
    subtitle="Gerenciamento de compras, orçamentos, fornecedores e despesas do Hackathon."
  >
    <template #actions>
      <button class="btn ghost" type="button" @click="go('setores')">Voltar para Setores</button>
    </template>

    <section class="finance-summary" aria-labelledby="finance-summary-title">
      <h2 id="finance-summary-title" class="ops-title">Resumo Financeiro</h2>
      <div class="grid cols-4">
        <Stat icon="chart" label="Total Estimado" :value="summary.total" hint="Soma dos itens com valor informado" />
        <Stat icon="grid" label="Itens Cadastrados" :value="summary.registered" />
        <Stat icon="check" label="Itens Cotados" :value="summary.quoted" hint="Com preço e status cotado" />
        <Stat icon="alert" label="Itens Pendentes" :value="summary.pending" hint="Sem preço ou aguardando cotação" />
      </div>
    </section>

    <section v-if="canManage" class="card finance-form-card" aria-labelledby="finance-form-title">
      <div class="row-between">
        <div>
          <h2 id="finance-form-title">{{ selected ? 'Editar Item' : 'Cadastro de Itens' }}</h2>
          <p class="stat-hint">O valor total é calculado automaticamente; deixe o valor unitário vazio enquanto aguarda cotação.</p>
        </div>
        <button v-if="selected" class="btn ghost small" type="button" @click="resetForm">Cancelar edição</button>
      </div>

      <form class="finance-form-grid" novalidate @submit.prevent="save.run()">
        <Field label="Categoria" required :error="save.fieldErrors.value.category">
          <input v-model="form.category" class="input" maxlength="120" required />
        </Field>
        <Field label="Item" required :error="save.fieldErrors.value.item">
          <input v-model="form.item" class="input" maxlength="180" required />
        </Field>
        <Field label="Especificação" required class-name="finance-span-2" :error="save.fieldErrors.value.specification">
          <textarea v-model="form.specification" class="input finance-textarea" required />
        </Field>
        <Field label="Quantidade" required hint="Use valores não negativos." :error="save.fieldErrors.value.quantity">
          <input v-model="form.quantity" class="input" type="number" min="0" max="99999999.9999" step="0.0001" required />
        </Field>
        <Field label="Unidade" required :error="save.fieldErrors.value.unit">
          <input v-model="form.unit" class="input" maxlength="24" placeholder="un, pacote, m, L, kit…" required />
        </Field>
        <Field label="Valor Unitário (R$)" hint="Vazio enquanto aguarda cotação." :error="save.fieldErrors.value.unit_price">
          <input v-model="form.unit_price" class="input" type="number" min="0" max="99999999.9999" step="0.0001" />
        </Field>
        <Field label="Valor Total (R$)" hint="Calculado pelo sistema.">
          <input class="input" :value="previewTotal" readonly aria-readonly="true" />
        </Field>
        <Field label="Fornecedor" :error="save.fieldErrors.value.supplier">
          <input v-model="form.supplier" class="input" maxlength="180" />
        </Field>
        <Field label="Status" required :error="save.fieldErrors.value.status">
          <input v-model="form.status" class="input" maxlength="80" placeholder="A cotar, Estimado, Cotado…" required />
        </Field>
        <Field label="Observação" class-name="finance-span-2" :error="save.fieldErrors.value.notes">
          <textarea v-model="form.notes" class="input finance-textarea" />
        </Field>
        <div class="finance-form-actions finance-span-2">
          <button class="btn" type="submit" :disabled="save.pending.value">
            {{ save.pending.value ? 'Salvando…' : selected ? 'Salvar alterações' : 'Adicionar Item' }}
          </button>
        </div>
      </form>
    </section>

    <section class="finance-records" aria-labelledby="finance-record-title">
      <div class="row-between finance-record-heading">
        <div>
          <h2 id="finance-record-title" class="ops-title">Controle de Compras e Despesas</h2>
          <p class="stat-hint">Pesquise pelo nome do item, filtre por categoria ou status e ordene os registros.</p>
        </div>
        <button class="btn ghost small" type="button" :disabled="records.loading.value" @click="records.load()">Atualizar</button>
      </div>

      <div class="finance-filters">
        <Field label="Pesquisar item">
          <input v-model="search" class="input" type="search" placeholder="Nome do produto ou serviço" />
        </Field>
        <Field label="Categoria">
          <select v-model="categoryFilter" class="input"><option value="">Todas</option><option v-for="category in categories" :key="category" :value="category">{{ category }}</option></select>
        </Field>
        <Field label="Status">
          <select v-model="statusFilter" class="input"><option value="">Todos</option><option v-for="status in statuses" :key="status" :value="status">{{ status }}</option></select>
        </Field>
        <Field label="Ordenar por">
          <select v-model="sortBy" class="input">
            <option value="category">Categoria (A–Z)</option>
            <option value="item">Item (A–Z)</option>
            <option value="total_desc">Maior valor</option>
            <option value="total_asc">Menor valor</option>
          </select>
        </Field>
      </div>

      <LoadState :loading="records.loading.value" :loaded="records.loaded.value" :error="records.error.value" :empty="visibleItems.length === 0" empty-title="Nenhum item encontrado" empty-text="Ajuste os filtros ou cadastre um novo item." @retry="records.load()">
        <div class="table-wrap finance-items-wrap">
          <table class="finance-items-table">
            <thead>
              <tr>
                <th>Categoria</th><th>Item</th><th>Especificação</th><th>Quantidade por Unidade</th>
                <th>Valor Unitário (R$)</th><th>Valor Total (R$)</th><th>Fornecedor</th><th>Status</th><th>Observação</th><th>Ações</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in visibleItems" :key="item.id">
                <td>{{ item.category }}</td>
                <td><strong>{{ item.item }}</strong></td>
                <td>{{ item.specification }}</td>
                <td>{{ formatQuantity(item.quantity) }} {{ item.unit }}</td>
                <td class="finance-money">{{ formatMoney(item.unit_price) }}</td>
                <td class="finance-money">{{ formatMoney(item.total_amount) }}</td>
                <td>{{ item.supplier || '—' }}</td>
                <td><span class="finance-status" :class="`is-${formatStatus(item.status)}`">{{ item.status }}</span></td>
                <td>{{ item.notes || '—' }}</td>
                <td>
                  <div class="finance-row-actions">
                    <button v-if="canManage" class="btn ghost small" type="button" :aria-label="`Editar ${item.item}`" @click="edit(item)">Editar</button>
                    <button v-if="canManage" class="btn ghost small danger" type="button" :aria-label="`Excluir ${item.item}`" @click="deleting = item">Excluir</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>

    <section class="finance-categories" aria-labelledby="finance-category-title">
      <h2 id="finance-category-title" class="ops-title">Total Estimado por Categoria</h2>
      <LoadState :loading="records.loading.value" :loaded="records.loaded.value" :error="records.error.value" :empty="categoryTotals.length === 0" empty-title="Nenhum valor por categoria" empty-text="Cadastre itens para acompanhar o orçamento." @retry="records.load()">
        <div v-if="categoryTotals.length" class="table-wrap">
          <table class="finance-category-table">
            <thead><tr><th>Categoria</th><th class="finance-money">Total Estimado</th></tr></thead>
            <tbody>
              <tr v-for="row in categoryTotals" :key="row.category"><td>{{ row.category }}</td><td class="finance-money">{{ row.total }}</td></tr>
            </tbody>
            <tfoot><tr><th>Total Geral</th><th class="finance-money">{{ summary.total }}</th></tr></tfoot>
          </table>
        </div>
      </LoadState>
    </section>
  </Page>

  <Modal v-if="deleting" title="Excluir item financeiro?" :subtitle="`Esta ação removerá “${deleting.item}” do controle de compras e despesas.`" @close="deleting = null">
    <p>O registro será excluído permanentemente e os totais serão recalculados.</p>
    <template #footer>
      <button class="btn ghost" type="button" @click="deleting = null">Cancelar</button>
      <button class="btn danger" type="button" :disabled="remove.pending.value" @click="remove.run()">{{ remove.pending.value ? 'Excluindo…' : 'Excluir item' }}</button>
    </template>
  </Modal>
</template>
