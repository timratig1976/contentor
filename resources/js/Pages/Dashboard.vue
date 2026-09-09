<script setup>
import { computed } from 'vue';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array, topAngles: Array, weekPlan: Array, stats: Object });

const statCards = computed(() => [
    { icon: '🎯', iconBg: 'bg-rose-50', label: 'Angles', value: props.stats?.total_angles ?? 0 },
    { icon: '📄', iconBg: 'bg-blue-50', label: 'Content Items', value: props.stats?.total_content ?? 0 },
    { icon: '🖼️', iconBg: 'bg-amber-50', label: 'Medien', value: props.stats?.total_media ?? 0 },
    { icon: '🧠', iconBg: 'bg-violet-50', label: 'Strategien', value: props.strategies?.length ?? 0 },
]);

function scorePill(score) {
    if (score >= 10) return 'bg-green-100 text-green-700';
    if (score >= 7) return 'bg-amber-100 text-amber-700';
    return 'bg-red-100 text-red-600';
}
function rankBadge(i) {
    if (i === 0) return 'bg-amber-100 text-amber-700';
    if (i === 1) return 'bg-gray-200 text-gray-600';
    if (i === 2) return 'bg-orange-100 text-orange-700';
    return 'bg-gray-100 text-gray-400';
}
function weekday(d) { return new Date(d + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short' }); }
function dayOfMonth(d) { return new Date(d + 'T00:00:00').getDate(); }
function statusDot(entry) {
    const s = entry.contentItem?.status;
    if (s === 'live') return 'bg-green-400';
    if (s === 'geplant') return 'bg-amber-400';
    return 'bg-gray-300';
}
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
                <div v-for="s in statCards" :key="s.label" class="neu-card neu-card-hover p-5 flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center text-xl shrink-0" :class="s.iconBg">{{ s.icon }}</div>
                    <div class="min-w-0">
                        <div class="text-2xl font-semibold text-gray-800 tabular-nums leading-none">{{ s.value }}</div>
                        <div class="text-xs text-gray-400 mt-1.5 truncate">{{ s.label }}</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <!-- Top Angles -->
                <div class="neu-card">
                    <div class="px-5 py-4 border-b border-neu-border flex items-center justify-between">
                        <h3 class="text-sm font-medium text-gray-800">Top Angles</h3>
                        <span class="text-[11px] text-gray-400">nach Score</span>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <a v-for="(angle, i) in topAngles" :key="angle.id" :href="'/angles/' + angle.id"
                            class="block px-5 py-3.5 hover:bg-gray-50 transition-colors group">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0" :class="rankBadge(i)">{{ i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-gray-800 line-clamp-2 leading-snug group-hover:text-gray-900">{{ angle.angle }}</p>
                                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                                        <span class="neu-badge neu-badge-info">{{ angle.icp }}</span>
                                        <span class="text-xs text-gray-400 truncate">{{ angle.pain_cluster }}</span>
                                    </div>
                                </div>
                                <span class="text-xs font-bold px-2 py-1 rounded-lg shrink-0 tabular-nums" :class="scorePill(angle.ranking_score)">{{ angle.ranking_score ?? '—' }}</span>
                            </div>
                        </a>
                        <div v-if="!topAngles?.length" class="p-8 text-center text-sm text-gray-400">Noch keine Angles</div>
                    </div>
                </div>

                <!-- Wochenplan -->
                <div class="neu-card">
                    <div class="px-5 py-4 border-b border-neu-border flex items-center justify-between">
                        <h3 class="text-sm font-medium text-gray-800">Diese Woche</h3>
                        <span class="text-[11px] text-gray-400">{{ weekPlan?.length || 0 }} Einträge</span>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="entry in weekPlan" :key="entry.id" class="px-5 py-3.5 hover:bg-gray-50 transition-colors flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-gray-50 border border-neu-border flex flex-col items-center justify-center shrink-0">
                                <span class="text-[9px] font-semibold text-gray-400 uppercase leading-none">{{ weekday(entry.planned_date) }}</span>
                                <span class="text-sm font-bold text-gray-700 leading-tight tabular-nums">{{ dayOfMonth(entry.planned_date) }}</span>
                            </div>
                            <span class="w-2 h-2 rounded-full shrink-0" :class="statusDot(entry)"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 truncate">{{ entry.contentItem?.title || entry.contentItem?.angle?.angle || '—' }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ entry.channel }}</p>
                            </div>
                        </div>
                        <div v-if="!weekPlan?.length" class="p-8 text-center text-sm text-gray-400">Keine Einträge diese Woche</div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>