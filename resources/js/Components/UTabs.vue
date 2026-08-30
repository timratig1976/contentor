<template>
  <div class="flex gap-1 mb-6 overflow-x-auto">
    <button v-for="(tab, key) in tabs" :key="key" @click="$emit('update:modelValue', key)"
      class="px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-colors"
      :class="modelValue === key ? 'bg-white border border-gray-200 text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'">
      {{ tab }}
    </button>
  </div>
</template>

<script setup>
defineProps({ tabs: Object, modelValue: String });
defineEmits(['update:modelValue']);
</script>