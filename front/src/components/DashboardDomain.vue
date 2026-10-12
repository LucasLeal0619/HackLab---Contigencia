<script setup>
import { go } from '@/js/router'

defineProps({
  title: { type: String, required: true },
  to: { type: String, required: true },
  action: { type: String, required: true },
  items: { type: Array, required: true },
})
</script>

<template>
  <section class="card dash-block dashboard-domain">
    <h2 class="dash-block-title">{{ title }}</h2>
    <ul class="dash-lines">
      <li v-for="item in items" :key="item.label">
        <a :href="`#/${item.to}`" @click.prevent="go(item.to)">
          <span>{{ item.label }}</span>
          <b>{{ item.value }}</b>
        </a>
      </li>
    </ul>
    <a class="dash-more" :href="`#/${to}`" @click.prevent="go(to)">{{ action }} →</a>
  </section>
</template>
