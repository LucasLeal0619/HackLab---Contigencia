<script setup>
// Moldura das telas públicas de acesso (login, primeiro acesso, redefinição de senha): mesmo visual do login.
import { ref } from 'vue'
import Icon from './Icon.vue'
import Logo from './Logo.vue'
import Modal from './Modal.vue'

const a11y = ref(false)
</script>

<template>
  <div class="login">
    <section class="login-side">
      <Logo />
      <div class="login-copy">
        <div class="bar" />
        <h1>Organização do Hackathon em um só lugar.</h1>
        <p>Planejamento, acompanhamento durante o evento e consulta de resultados e presença.</p>
      </div>
      <div class="login-foot">Hackathon Senac · Plataforma de gestão</div>
    </section>
    <section class="login-main">
      <div class="login-tools">
        <button class="a11y-btn" type="button" @click="a11y = true"><Icon name="access" :size="16" /> Acessibilidade</button>
      </div>
      <slot />
    </section>
    <Modal v-if="a11y" title="Acessibilidade" subtitle="As preferências completas ficam disponíveis depois do login." @close="a11y = false">
      <p>Alto contraste, tamanho da fonte, foco reforçado, redução de animações e espaçamento podem ser ajustados no topo de qualquer tela depois de entrar.</p>
      <template #footer><button class="btn" type="button" @click="a11y = false">Fechar</button></template>
    </Modal>
  </div>
</template>
