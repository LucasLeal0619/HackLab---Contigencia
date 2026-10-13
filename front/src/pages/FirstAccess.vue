<script setup>
import RequestLinkForm from '../components/RequestLinkForm.vue'
import { go } from '@/js/router'
</script>

<template>
  <RequestLinkForm
    kind="activation"
    title="Primeiro acesso"
    lead="Use o mesmo e-mail utilizado na inscrição do evento. Se o seu acesso estiver habilitado, você receberá um link para criar a sua senha."
    action="Enviar instruções"
  >
    <template #links>
      <p>Já tem conta e esqueceu a senha? <button type="button" class="linkish" @click="go('esqueci-senha')">Redefinir senha</button></p>
    </template>
  </RequestLinkForm>
</template>
