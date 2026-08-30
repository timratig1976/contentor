<template>
  <td class="px-4 py-3 text-sm" :class="textClass"><slot /></td>
</template>

<script setup>
defineProps({ textClass: { type: String, default: 'text-gray-900' } });
</script>