<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    angles: Object,
    strategies: Array,
    batches: Array,
    strategyIcps: Array,
    filters: Object,
});

const viewMode = ref('table'); // 'table' | 'journey'
const filterIcp = ref(props.filters?.icp || '');
const filterStatus = ref(props.filters?.status || '');
const filterFunnel = ref(props.filters?.funnel || '');

// Gruppierung nach Funnel für Journey-Board
const journeyStages = [
    { key: 'ToFu', label: 'ToFu — Awareness', sub: 'Aufwecken, Probleme & Gegenthesen', badge: 'bg-blue-50 text-blue-700 border-blue-200' },
    { key: 'MoFu', label: 'MoFu — Consideration', sub: 'Methoden, Mechanismen & Denkfehler', badge: 'bg-amber-50 text-amber-700 border-amber-200' },
    { key: 'BoFu', label: 'BoFu — Decision', sub: 'Einwandbehandlung & Kaufentscheidung', badge: 'bg-green-50 text-green-700 border-green-200' },
];

const anglesByFunnel = computed(() => {
    const list = props.angles?.data || [];
    return {
        ToFu: list.filter(a => (a.funnel || '').toUpperCase() === 'TOFU'),
        MoFu: list.filter(a => (a.funnel || '').toUpperCase() === 'MOFU'),
        BoFu: list.filter(a => (a.funnel || '').toUpperCase() === 'BOFU'),
        Unassigned: list.filter(a => !['TOFU', 'MOFU', 'BOFU'].includes((a.funnel || '').toUpperCase())),
    };
});

function applyFilters() {
    router.get('/angles', {
        strategy: props.filters?.strategy || undefined,
        icp: filterIcp.value || undefined,
        status: filterStatus.value || undefined,
        funnel: filterFunnel.value || undefined,
    }, { preserveState: true });
}

function scoreColor(score) {
    if (score === null) return 'text-gray-400';
    if (score >= 10) return 'text-green-600';
    if (score >= 7) return 'text-yellow-600';
    return 'text-red-600';
}

const statusLabels = {
    neu: 'Neu',
    bewertet: 'Bewertet',
    approved: 'approved',
    verworfen: 'Verworfen',
};

const statusClasses = {
    neu: 'bg-gray-100 text-gray-600',
    bewertet: 'bg-blue-50 text-blue-600',
    approved: 'bg-green-50 text-green-700',
    verworfen: 'bg-red-50 text-red-700',
};

async function del(angle) {
    if (!confirm('Angle wirklich löschen?')) return;
    await fetch(`/api/angles/${angle.id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
    });
}

const statusOptions = ['neu', 'bewertet', 'approved', 'verworfen'];

async function setStatus(angle, status) {
    if (status === angle.status) return;
    await fetch(`/api/angles/${angle.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ status }),
    });
    angle.status = status;
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Angles (Thesen & Blickwinkel)</h2>
                <p class="text-gray-400 mt-1">{{ angles?.total || 0 }} Angles gesamt · Geordnet nach Themenclustern & Customer Journey</p>
            </div>
            <!-- Switcher: Tabelle vs. Customer Journey Board -->
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1">
                <button
                    @click="viewMode = 'table'"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                    :class="viewMode === 'table' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900'"
                >
                    📋 Tabelle
                </button>
                <button
                    @click="viewMode = 'journey'"
                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                    :class="viewMode === 'journey' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900'"
                >
                    🚀 Customer Journey (Funnel)
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="neu-card p-4 mb-6 flex flex-wrap items-center gap-3">
            <!-- ICP-Filter: Dynamisch nur die ICPs der aktuellen Strategie -->
            <select v-model="filterIcp" @change="applyFilters" class="bg-neu rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle ICPs</option>
                <option v-for="icp in strategyIcps" :key="icp.key" :value="icp.key">
                    {{ icp.key }} — {{ icp.name }}
                </option>
            </select>

            <!-- Status-Filter -->
            <select v-model="filterStatus" @change="applyFilters" class="bg-neu rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Status</option>
                <option value="neu">Neu</option>
                <option value="bewertet">Bewertet</option>
                <option value="approved">Approved</option>
                <option value="verworfen">Verworfen</option>
            </select>

            <!-- Funnel-Filter -->
            <select v-model="filterFunnel" @change="applyFilters" class="bg-neu rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Funnel</option>
                <option value="ToFu">ToFu — Awareness</option>
                <option value="MoFu">MoFu — Consideration</option>
                <option value="BoFu">BoFu — Decision</option>
            </select>

            <button
                v-if="filterIcp || filterStatus || filterFunnel"
                @click="filterIcp = ''; filterStatus = ''; filterFunnel = ''; applyFilters()"
                class="text-xs text-red-500 hover:text-red-700 ml-auto"
            >
                Filter zurücksetzen ✕
            </button>
        </div>

        <!-- Table View -->
        <div v-if="viewMode === 'table'" class="neu-card overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-neu-border">
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Angle</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">ICP</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Cluster</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Funnel</th>
                        <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Score</th>
                        <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Rang</th>
                        <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Duplikat</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Status</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Strategie</th>
                        <th class="text-right px-6 py-3 text-xs font-medium text-gray-400 uppercase"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="angle in angles?.data" :key="angle.id" class="hover:bg-gray-300/50 cursor-pointer" @click="router.visit(`/angles/${angle.id}`)">
                        <td class="px-6 py-4">
                            <p class="text-sm text-gray-800 line-clamp-2 max-w-md">{{ angle.angle }}</p>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-neu/20 text-gray-600">{{ angle.icp }}</span>
                                <span v-if="angle.icp_name && angle.icp_name !== angle.icp" class="text-xs text-gray-500">{{ angle.icp_name }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ angle.pain_cluster }}</td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ angle.funnel || '—' }}</td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-lg font-bold" :class="scoreColor(angle.ranking_score)">{{ angle.ranking_score ?? '—' }}</span>
                        </td>
                        <td class="px-6 py-4 text-center text-sm text-gray-400">{{ angle.ranking_rang ?? '—' }}</td>
                        <td class="px-6 py-4 text-center">
                            <span v-if="angle.duplicate_of_id"
                                class="text-xs bg-yellow-50 text-yellow-700 border border-yellow-200 px-2 py-0.5 rounded-full"
                                :title="`Ähnlichkeit ${Math.round((angle.similarity_score || 0) * 100)}% zu ${angle.duplicate_of_id}`">
                                ⚠ Ähnlich
                            </span>
                            <span v-else class="text-gray-300">—</span>
                        </td>
                        <td class="px-6 py-4" @click.stop>
                            <select
                                v-model="angle.status"
                                @change="setStatus(angle, angle.status)"
                                class="text-xs px-2 py-1 rounded-full border-0 cursor-pointer focus:outline-none focus:ring-2 focus:ring-green-500/30"
                                :class="statusClasses[angle.status] || 'bg-gray-100 text-gray-600'"
                            >
                                <option v-for="opt in statusOptions" :key="opt" :value="opt">{{ statusLabels[opt] || opt }}</option>
                            </select>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ angle.strategy?.name || '—' }}</td>
                        <td class="px-6 py-4 text-right" @click.stop>
                            <button
                                v-if="!angle.content_items_count"
                                @click="del(angle)"
                                class="text-xs text-red-500 hover:text-red-700"
                                title="Angle löschen"
                            >🗑️</button>
                            <span v-else class="text-xs text-gray-300" title="Content vorhanden">🔒</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="angles?.links?.length > 3" class="px-6 py-4 border-t border-neu-border flex justify-center gap-1">
                <button
                    v-for="link in angles.links"
                    :key="link.label"
                    @click="link.url && router.get(link.url)"
                    class="px-3 py-1 rounded text-sm"
                    :class="link.active ? 'bg-neu-accent text-white' : 'text-gray-400 hover:text-gray-800'"
                    v-html="link.label"
                />
            </div>
        </div>

        <!-- Customer Journey Funnel Board -->
        <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div v-for="stage in journeyStages" :key="stage.key" class="bg-gray-100/70 border border-gray-200 rounded-2xl p-4 flex flex-col min-h-[600px]">
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-md border" :class="stage.badge">{{ stage.label }}</span>
                        <span class="text-xs font-bold text-gray-500">{{ anglesByFunnel[stage.key]?.length || 0 }} Angles</span>
                    </div>
                    <p class="text-[11px] text-gray-500">{{ stage.sub }}</p>
                </div>

                <div class="space-y-3 flex-1 overflow-y-auto">
                    <div
                        v-for="angle in anglesByFunnel[stage.key]"
                        :key="angle.id"
                        @click="router.visit(`/angles/${angle.id}`)"
                        class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm hover:shadow hover:border-gray-300 cursor-pointer transition-all"
                    >
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-gray-100 text-gray-700">{{ angle.icp }}</span>
                            <span class="text-xs font-bold" :class="scoreColor(angle.ranking_score)">Score: {{ angle.ranking_score ?? '—' }}</span>
                        </div>
                        <p class="text-xs font-medium text-gray-900 line-clamp-3 mb-2.5 leading-relaxed">{{ angle.angle }}</p>
                        <div class="flex items-center justify-between text-[11px] text-gray-400 pt-2 border-t border-gray-100">
                            <span class="truncate max-w-[140px]" :title="angle.pain_cluster">{{ angle.pain_cluster || 'Kein Cluster' }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px]" :class="statusClasses[angle.status]">{{ statusLabels[angle.status] || angle.status }}</span>
                        </div>
                    </div>
                    <div v-if="!anglesByFunnel[stage.key]?.length" class="h-32 flex items-center justify-center border border-dashed border-gray-300 rounded-xl text-xs text-gray-400 text-center p-4">
                        Keine Angles in dieser Funnel-Stufe
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
