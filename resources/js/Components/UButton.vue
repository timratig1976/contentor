<template>
  <button :type="type" :disabled="disabled || loading" @click="$emit('click')"
    class="px-4 py-2 rounded-lg text-sm font-semibold transition-all"
    :class="[
      variant === 'primary' ? 'bg-green-600 text-white hover:bg-green-700' :
      variant === 'danger' ? 'bg-red-500 text-white hover:bg-red-600' :
      variant === 'ghost' ? 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' :
      'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50',
      disabled || loading ? 'opacity-50 cursor-not-allowed' : '',
      size === 'sm' ? 'px-3 py-1.5 text-xs' : 'px-4 py-2 text-sm'
    ]">
    <span v-if="loading" class="mr-2">⏳</span>
    <span v-if="icon">{{ icon }}</span>
    <slot />
  </button>
</template>

<script setup>
defineProps({ variant: String, type: { type: String, default: 'button' }, disabled: Boolean, loading: Boolean, size: String, icon: String });
defineEmits(['click']);
</script>