<script setup lang="ts">
import AppShell from "./components/AppShell.vue";
</script>
<template>
  <RouterView v-slot="{ Component, route }">
    <component :is="Component" v-if="route.meta.public" />
    <AppShell v-else><component :is="Component" /></AppShell>
  </RouterView>
</template>
