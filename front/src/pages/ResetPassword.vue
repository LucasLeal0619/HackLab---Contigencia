<script setup>
import SetPasswordForm from '../components/SetPasswordForm.vue'

defineProps({
  params: { type: Object, default: () => ({}) },
})
</script>

<template>
  <SetPasswordForm
    kind="reset"
    :params="params"
    title="Redefinir senha"
    lead="Escolha uma nova senha para a sua conta."
    action="Salvar nova senha"
    done-title="Senha redefinida"
    done-text="Senha redefinida. Entre com a nova senha; as sessões abertas foram encerradas."
    restart="esqueci-senha"
  />
</template>
