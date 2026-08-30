<template>
  <div class="bg-white border border-gray-200 rounded-xl" :class="padding ? 'p-6' : ''">
    <div v-if="title" class="flex items-center justify-between mb-4">
      <h3 class="text-sm font-semibold text-gray-900">{{ title }}</h3>
      <slot name="actions" />
    </div>
    <slot />
  </div>
</template>

<script setup>
defineProps({ title: String, padding: { type: Boolean, default: true } });
</script>