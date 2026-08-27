<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    units: Array,
    personas: Array,
});
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-white">Einstellungen</h2>
            <p class="text-gray-400 mt-1">Units, Personas und Konfiguration</p>
        </div>

        <!-- Units -->
        <div class="mb-8">
            <h3 class="text-lg font-semibold text-white mb-4">Units</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div v-for="unit in units" :key="unit.key" class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-lg font-semibold text-white">{{ unit.name }}</h4>
                        <span class="text-xs px-2 py-1 rounded-full bg-indigo-600/20 text-indigo-400">{{ unit.key }}</span>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">ICP Keys</span>
                            <span class="text-gray-300">{{ unit.config?.rules?.icpKeys?.join(', ') || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Default ICP</span>
                            <span class="text-gray-300">{{ unit.config?.rules?.defaultIcp || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Clusters</span>
                            <span class="text-gray-300">{{ unit.config?.rules?.clusters?.length || 0 }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Hashtags</span>
                            <span class="text-gray-300">{{ unit.config?.rules?.hashtags?.join(' ') || '—' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Personas -->
        <div>
            <h3 class="text-lg font-semibold text-white mb-4">Personas</h3>
            <div class="bg-gray-900 rounded-xl border border-gray-800 overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-800">
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Name</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Rolle</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Unit</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Kadenz</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Themen</th>
                            <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Aktiv</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="p in personas" :key="p.id" class="hover:bg-gray-800/50">
                            <td class="px-6 py-4 text-sm text-white">{{ p.name }}</td>
                            <td class="px-6 py-4 text-sm text-gray-400">{{ p.role || '—' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-400">{{ p.unit?.name }}</td>
                            <td class="px-6 py-4 text-sm text-gray-400">{{ p.cadence || '—' }}</td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="t in (p.topics || [])" :key="t" class="text-xs px-2 py-0.5 rounded bg-gray-800 text-gray-400">{{ t }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span :class="p.active ? 'text-green-400' : 'text-red-400'">{{ p.active ? '✓' : '✗' }}</span>
                            </td>
                        </tr>
                        <tr v-if="!personas?.length">
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">Noch keine Personas angelegt</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
