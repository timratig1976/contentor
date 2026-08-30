<script setup>
import AppLayout from '../Layouts/AppLayout.vue';

defineProps({ strategies: Array, topAngles: Array, weekPlan: Array, stats: Object });
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-2xl font-semibold text-gray-800 tracking-tight">Dashboard</h1>
                <p class="text-sm text-gray-400 mt-1">Übersicht über das Content-System</p>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-4 gap-4 mb-8">
                <div class="neu-card p-5">
                    <div class="text-2xl font-semibold text-gray-800">{{ stats.total_angles }}</div>
                    <div class="text-xs text-gray-400 mt-1">Angles</div>
                </div>
                <div class="neu-card p-5">
                    <div class="text-2xl font-semibold text-gray-800">{{ stats.total_content }}</div>
                    <div class="text-xs text-gray-400 mt-1">Content Items</div>
                </div>
                <div class="neu-card p-5">
                    <div class="text-2xl font-semibold text-gray-800">{{ stats.total_media }}</div>
                    <div class="text-xs text-gray-400 mt-1">Medien</div>
                </div>
                <div class="neu-card p-5">
                    <div class="text-2xl font-semibold text-gray-800">{{ strategies?.length || 0 }}</div>
                    <div class="text-xs text-gray-400 mt-1">Strategien</div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <!-- Top Angles -->
                <div class="neu-card">
                    <div class="px-5 py-4 border-b border-neu-border">
                        <h3 class="text-sm font-medium text-gray-800">Top Angles</h3>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="angle in topAngles" :key="angle.id" class="px-5 py-3 hover:bg-gray-300 transition-colors">
                            <p class="text-sm text-gray-800 line-clamp-2">{{ angle.angle }}</p>
                            <div class="flex items-center gap-2 mt-1.5">
                                <span class="text-xs text-gray-400">{{ angle.icp }}</span>
                                <span class="text-xs text-gray-400">·</span>
                                <span class="text-xs text-gray-400">{{ angle.pain_cluster }}</span>
                            </div>
                        </div>
                        <div v-if="!topAngles?.length" class="p-8 text-center text-sm text-gray-400">Noch keine Angles</div>
                    </div>
                </div>

                <!-- Wochenplan -->
                <div class="neu-card">
                    <div class="px-5 py-4 border-b border-neu-border">
                        <h3 class="text-sm font-medium text-gray-800">Diese Woche</h3>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="entry in weekPlan" :key="entry.id" class="px-5 py-3 hover:bg-gray-300 transition-colors flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full" :class="{
                                'bg-green-400': entry.contentItem?.status === 'live',
                                'bg-yellow-400': entry.contentItem?.status === 'geplant',
                                'bg-gray-300': true,
                            }"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 truncate">{{ entry.contentItem?.title || entry.contentItem?.angle?.angle || '—' }}</p>
                                <p class="text-xs text-gray-400">{{ entry.planned_date }} · {{ entry.channel }}</p>
                            </div>
                        </div>
                        <div v-if="!weekPlan?.length" class="p-8 text-center text-sm text-gray-400">Keine Einträge diese Woche</div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>