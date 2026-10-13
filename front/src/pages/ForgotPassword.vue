<script setup>
import RequestLinkForm from '../components/RequestLinkForm.vue'
import { go } from '@/js/router'
</script>

<template>
  <RequestLinkForm
    kind="reset"
    title="Esqueci minha senha"
    lead="Informe o e-mail da sua conta. Se ela existir, você receberá um link para criar uma nova senha."
    action="Enviar link"
  >
    <template #links>
      <p>Ainda não criou sua conta? <button type="button" class="linkish" @click="go('primeiro-acesso')">Primeiro acesso</button></p>
    </template>
  </RequestLinkForm>
</template>
