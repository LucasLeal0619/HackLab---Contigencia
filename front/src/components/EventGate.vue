<script setup>
// Páginas que dependem do evento ativo só aparecem quando existe um evento.
import Empty from './Empty.vue'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
</script>

<template>
  <slot v-if="eventContext.activeEventId" />
  <div v-else class="page">
    <Empty title="Nenhum evento disponível" text="Ainda não há um evento cadastrado ou visível para o seu perfil.">
      <template v-if="hasPermission('events.manage')" #action>
        <button class="btn" type="button" @click="go('config')">Cadastrar evento</button>
      </template>
    </Empty>
  </div>
</template>
