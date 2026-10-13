<script setup>
import SetPasswordForm from '../components/SetPasswordForm.vue'

defineProps({
  params: { type: Object, default: () => ({}) },
})
</script>

<template>
  <SetPasswordForm
    kind="activation"
    :params="params"
    title="Criar minha senha"
    lead="Defina a senha que você vai usar para entrar no HackLab."
    action="Criar minha senha"
    done-title="Acesso ativado"
    done-text="Acesso ativado. Agora você pode entrar no HackLab."
    restart="primeiro-acesso"
  />
</template>
