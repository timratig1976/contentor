<template>
  <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium"
    :class="{
      'bg-green-50 text-green-700 border border-green-200': variant === 'success',
      'bg-red-50 text-red-700 border border-red-200': variant === 'error',
      'bg-yellow-50 text-yellow-700 border border-yellow-200': variant === 'warning',
      'bg-gray-100 text-gray-700': variant === 'default',
    }">
    <slot />
  </span>
</template>

<script setup>
defineProps({ variant: { type: String, default: 'default' } });
</script>