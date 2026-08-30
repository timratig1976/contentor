<template>
  <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <table class="w-full">
      <thead v-if="$slots.header">
        <tr class="bg-gray-50 border-b border-gray-200">
          <slot name="header" />
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <slot />
      </tbody>
    </table>
  </div>
</template>