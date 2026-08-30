<template>
  <div class="space-y-1">
    <label v-if="label" class="block text-sm font-medium text-gray-900">{{ label }}</label>
    <div v-if="hint" class="text-xs text-gray-500">{{ hint }}</div>
    <input v-if="type !== 'textarea' && type !== 'select'" :type="type" :value="modelValue" @input="$emit('update:modelValue', $event.target.value)"
      :placeholder="placeholder" :disabled="disabled"
      class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-colors" />
    <textarea v-else-if="type === 'textarea'" :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" :placeholder="placeholder" :rows="rows || 3"
      class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-colors resize-y"></textarea>
    <select v-else :value="modelValue" @change="$emit('update:modelValue', $event.target.value)" :disabled="disabled"
      class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-colors cursor-pointer">
      <slot />
    </select>
  </div>
</template>

<script setup>
defineProps({ label: String, modelValue: [String, Number], type: { type: String, default: 'text' }, placeholder: String, disabled: Boolean, hint: String, rows: Number });
defineEmits(['update:modelValue']);
</script>