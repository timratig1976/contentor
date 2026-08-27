<script setup>
import AppLayout from '../Layouts/AppLayout.vue';

defineProps({
    units: Array,
    topAngles: Array,
    weekPlan: Array,
    stats: Object,
});

const statusColors = {
    idee: 'bg-gray-500',
    angle: 'bg-blue-500',
    in_produktion: 'bg-yellow-500',
    review: 'bg-purple-500',
    geplant: 'bg-cyan-500',
    live: 'bg-green-500',
};
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-white">Dashboard</h2>
            <p class="text-gray-400 mt-1">Übersicht über das Content System</p>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-gray-900 rounded-xl p-6 border border-gray-800">
                <div class="text-3xl font-bold text-indigo-400">{{ stats.total_angles }}</div>
                <div class="text-sm text-gray-400 mt-1">Angles</div>
            </div>
            <div class="bg-gray-900 rounded-xl p-6 border border-gray-800">
                <div class="text-3xl font-bold text-cyan-400">{{ stats.total_content }}</div>
                <div class="text-sm text-gray-400 mt-1">Content Items</div>
            </div>
            <div class="bg-gray-900 rounded-xl p-6 border border-gray-800">
                <div class="text-3xl font-bold text-emerald-400">{{ stats.total_media }}</div>
                <div class="text-sm text-gray-400 mt-1">Medien</div>
            </div>
            <div class="bg-gray-900 rounded-xl p-6 border border-gray-800">
                <div class="text-3xl font-bold text-amber-400">{{ units?.length || 0 }}</div>
                <div class="text-sm text-gray-400 mt-1">Units</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Top Angles -->
            <div class="bg-gray-900 rounded-xl border border-gray-800">
                <div class="p-6 border-b border-gray-800">
                    <h3 class="text-lg font-semibold text-white">🎯 Top Angles</h3>
                </div>
                <div class="divide-y divide-gray-800">
                    <div v-for="angle in topAngles" :key="angle.id" class="p-4 hover:bg-gray-800/50">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <p class="text-sm text-white line-clamp-2">{{ angle.angle }}</p>
                                <div class="flex items-center gap-2 mt-2">
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-indigo-600/20 text-indigo-400">{{ angle.icp }}</span>
                                    <span class="text-xs text-gray-500">{{ angle.pain_cluster }}</span>
                                </div>
                            </div>
                            <div class="ml-4 text-right">
                                <div class="text-lg font-bold" :class="angle.ranking_score >= 10 ? 'text-green-400' : angle.ranking_score >= 7 ? 'text-yellow-400' : 'text-red-400'">
                                    {{ angle.ranking_score ?? '—' }}
                                </div>
                                <div class="text-xs text-gray-500">Score</div>
                            </div>
                        </div>
                    </div>
                    <div v-if="!topAngles?.length" class="p-8 text-center text-gray-500">
                        Noch keine bewerteten Angles
                    </div>
                </div>
            </div>

            <!-- Diese Woche -->
            <div class="bg-gray-900 rounded-xl border border-gray-800">
                <div class="p-6 border-b border-gray-800">
                    <h3 class="text-lg font-semibold text-white">📋 Diese Woche</h3>
                </div>
                <div class="divide-y divide-gray-800">
                    <div v-for="entry in weekPlan" :key="entry.id" class="p-4 hover:bg-gray-800/50">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-white">{{ entry.content_item?.title || entry.content_item?.content?.substring(0, 60) }}</p>
                                <p class="text-xs text-gray-500 mt-1">{{ entry.planned_date }} · {{ entry.channel }}</p>
                            </div>
                            <span class="w-2 h-2 rounded-full" :class="statusColors[entry.status] || 'bg-gray-500'"></span>
                        </div>
                    </div>
                    <div v-if="!weekPlan?.length" class="p-8 text-center text-gray-500">
                        Keine Einträge diese Woche
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
