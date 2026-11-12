<script setup>
// Moldura das telas públicas de acesso (login, primeiro acesso, redefinição de senha): mesmo visual do login.
import { ref } from 'vue'
import Icon from './Icon.vue'
import Modal from './Modal.vue'
import senacLockupWhite from '@/assets/senac-lockup-white.png'
import laptopBackdrop from '@/assets/hacklab-login-laptop.jpg'

const a11y = ref(false)
</script>

<template>
  <div class="login">
    <section class="login-side">
      <div class="login-brand">
        <div class="senac-mark">
          <img class="senac-logo" :src="senacLockupWhite" alt="Senac Fecomércio Sesc" />
        </div>
      </div>

      <div class="hero-corner-blob" aria-hidden="true" />
      <img class="hero-photo" :src="laptopBackdrop" alt="" aria-hidden="true" />

      <div class="login-copy">
        <div class="hero-title">
          <span class="hero-main">HACKATHON</span>
          <span class="hero-accent">SENAC</span>
        </div>

        <p class="hero-tag">Inovação que conecta<br><strong>pessoas e soluções.</strong></p>
        <p class="hero-description">O HackLab é o espaço para transformar ideias em projetos reais. Faça parte dessa jornada de inovação!</p>

        <div class="hero-items">
          <div class="hero-item">
            <Icon name="lightbulb" :size="30" class="hero-icon" />
            <small>Ideias<br>que inspiram</small>
          </div>
          <div class="hero-item">
            <Icon name="users" :size="30" class="hero-icon" />
            <small>Talentos<br>que unem</small>
          </div>
          <div class="hero-item">
            <Icon name="rocket" :size="30" class="hero-icon" />
            <small>Soluções<br>que transformam</small>
          </div>
        </div>
      </div>
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
