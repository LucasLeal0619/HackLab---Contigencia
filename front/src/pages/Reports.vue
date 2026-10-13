<script setup>
import Icon from '../components/Icon.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import { useReports } from '@/js/pages/reports'

const { REPORT_MODELS, rows, preview, view, exportPdf, retry, save } = useReports()
</script>

<template>
  <Page title="Relatórios" subtitle="Documentos consolidados do Hackathon, gerados com os dados atuais do HackLab.">
    <section class="report-center" aria-labelledby="report-models">
      <div class="report-center-head">
        <h2 id="report-models" class="report-center-title">Modelos de relatório</h2>
        <p>Escolha o relatório que deseja visualizar ou baixar em PDF.</p>
      </div>
      <ul class="report-list">
        <li v-for="item in REPORT_MODELS" :key="item.id" class="report-row" :class="{ featured: item.recommended }">
          <div class="report-info">
            <h3>{{ item.title }} <span v-if="item.recommended" class="badge info">Recomendado</span></h3>
            <p>{{ item.text }}</p>
            <p v-if="rows[item.id].error" class="report-error" role="alert">
              <Icon name="info" :size="14" />
              {{ rows[item.id].error.kind === 'forbidden' ? 'Você não tem permissão para este relatório.' : rows[item.id].error.message }}
              <button v-if="rows[item.id].error.kind !== 'forbidden'" class="linkish" type="button" @click="retry(item.id)">Tentar novamente</button>
            </p>
          </div>
          <div class="report-actions">
            <button class="btn ghost" type="button" :disabled="Boolean(rows[item.id].busy)" @click="view(item.id)">
              {{ rows[item.id].busy === 'preview' ? 'Gerando…' : 'Visualizar' }}
            </button>
            <button class="btn" type="button" :disabled="Boolean(rows[item.id].busy)" @click="exportPdf(item.id)">
              {{ rows[item.id].busy === 'pdf' ? 'Gerando…' : rows[item.id].result ? 'Baixar PDF' : 'Gerar PDF' }}
            </button>
          </div>
        </li>
      </ul>
      <p class="report-foot">Os PDFs são montados neste navegador, em A4, a partir dos dados consolidados pelo servidor no momento da geração. Nada é guardado.</p>
    </section>

    <Modal v-if="preview" class="report-preview" :title="preview.title" :subtitle="preview.fileName" wide @close="preview = null">
      <iframe class="report-frame" :src="preview.url" :title="`Pré-visualização: ${preview.title}`" />
      <template #footer>
        <button class="btn ghost" type="button" @click="preview = null">Voltar</button>
        <button class="btn" type="button" @click="save(preview)">Baixar PDF</button>
      </template>
    </Modal>
  </Page>
</template>
