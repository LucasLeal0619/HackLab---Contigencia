<script setup>
import Logo from '../components/Logo.vue'
import { usePresentation } from '@/js/pages/presentation'

const { PRESENT_STEPS, published, current, technical, popular, eventName, step, stepId, goStep, percent, formatScore, go } = usePresentation()
</script>

<template>
  <div class="present">
    <div class="present-top">
      <Logo />
      <button class="btn ghost present-exit" type="button" @click="go('painel')">Sair do modo apresentação</button>
    </div>
    <div class="present-stage" aria-live="polite">
      <div v-if="published.loading.value && !published.loaded.value">
        <p>Carregando o resultado publicado…</p>
      </div>
      <div v-else-if="published.error.value" class="present-block">
        <h1>Não foi possível carregar</h1>
        <p>{{ published.error.value.message }}</p>
        <button class="btn ghost present-exit" type="button" @click="published.load()">Tentar novamente</button>
      </div>
      <div v-else-if="!current" class="present-block">
        <h1>Resultados ainda não divulgados</h1>
        <p>Aguarde a publicação oficial dos resultados.</p>
        <button class="btn ghost present-exit" type="button" :disabled="published.loading.value" @click="published.load()">{{ published.loading.value ? 'Atualizando…' : 'Atualizar' }}</button>
      </div>
      <template v-else>
        <div v-if="stepId === 'abertura'">
          <h1>Resultados {{ eventName }}</h1>
          <p>Apresentação do resultado oficial do Hackathon.</p>
        </div>
        <div v-if="stepId === 'jurados'" class="present-block">
          <h1>Resultado dos Jurados</h1>
          <p v-if="technical.length === 0">Nenhuma equipe no resultado técnico.</p>
          <div v-else class="present-cards">
            <article v-for="item in technical" :key="item.team.id" class="present-card">
              <p class="present-rank">{{ item.rank ? `${item.rank}º lugar` : 'Sem posição' }}<template v-if="item.tied"> · empate</template></p>
              <h3>{{ item.team.name }}</h3>
              <p>{{ item.technical_score === null ? 'Sem avaliação válida' : `Nota técnica ${formatScore(item.technical_score)}` }}</p>
            </article>
          </div>
        </div>
        <div v-if="stepId === 'publico'" class="present-block">
          <h1>Escolha do Público</h1>
          <p v-if="!popular || !popular.teams?.length">Nenhuma equipe candidata na votação do público.</p>
          <p v-else-if="popular.total_votes === 0">Nenhum voto registrado.</p>
          <div v-else class="present-cards">
            <article v-for="item in popular.teams" :key="item.team.id" class="present-card">
              <p class="present-rank">{{ item.rank ? `${item.rank}º lugar` : 'Sem posição' }}<template v-if="item.tied"> · empate</template></p>
              <h3>{{ item.team.name }}</h3>
              <p>{{ item.vote_count }} voto(s) · {{ percent(item.percentage) }}</p>
            </article>
          </div>
        </div>
        <div v-if="stepId === 'fim'">
          <h1>{{ eventName }}</h1>
          <p>Obrigado pela participação!</p>
        </div>
      </template>
    </div>
    <div v-if="current" class="present-nav">
      <button class="btn ghost present-exit" type="button" :disabled="step === 0" @click="goStep(step - 1)">Anterior</button>
      <span>{{ step + 1 }} de {{ PRESENT_STEPS.length }}</span>
      <button class="btn" type="button" :disabled="step === PRESENT_STEPS.length - 1" @click="goStep(step + 1)">Próximo</button>
    </div>
  </div>
</template>
