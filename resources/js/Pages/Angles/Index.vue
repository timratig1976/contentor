<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    angles: Object,
    strategies: Array,
    batches: Array,
    filters: Object,
});

const filterUnit = ref(props.filters?.unit || '');
const filterBatch = ref(props.filters?.batch || '');
const filterIcp = ref(props.filters?.icp || '');
const filterStatus = ref(props.filters?.status || '');
const filterFunnel = ref(props.filters?.funnel || '');

function applyFilters() {
    router.get('/angles', {
        unit: filterUnit.value || undefined,
        batch: filterBatch.value || undefined,
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
    await router.delete(`/api/angles/${angle.id}`, { preserveState: true });
}

const statusOptions = ['neu', 'bewertet', 'approved', 'verworfen'];

async function setStatus(angle, status) {
    if (status === angle.status) return;
    await router.patch(`/api/angles/${angle.id}`, { status }, { preserveState: true });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Angles</h2>
                <p class="text-gray-400 mt-1">{{ angles?.total || 0 }} Angles gesamt</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="neu-card p-4  mb-6 flex flex-wrap gap-3">
            <select v-model="filterUnit" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Strategies</option>
                <option v-for="u in strategies" :key="u.key" :value="u.key">{{ u.name }}</option>
            </select>
            <select v-model="filterBatch" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Batches</option>
                <option v-for="b in batches" :key="b" :value="b">{{ b }}</option>
            </select>
            <select v-model="filterIcp" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle ICPs</option>
                <option value="B2B-1">B2B-1</option>
                <option value="B2B-2">B2B-2</option>
                <option value="B2B-3">B2B-3</option>
                <option value="B2C">B2C</option>
                <option value="UNI">UNI</option>
                <option value="BK">BK</option>
            </select>
            <select v-model="filterStatus" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Status</option>
                <option value="neu">Neu</option>
                <option value="bewertet">Bewertet</option>
                <option value="approved">Approved</option>
                <option value="verworfen">Verworfen</option>
            </select>
            <select v-model="filterFunnel" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Funnel</option>
                <option value="ToFu">ToFu</option>
                <option value="MoFu">MoFu</option>
                <option value="BoFu">BoFu</option>
            </select>
        </div>

        <!-- Table -->
        <div class="neu-card overflow-hidden">
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
                    :class="link.active ? 'bg-neu text-gray-800' : 'text-gray-400 hover:bg-gray-300'"
                    v-html="link.label"
                    :disabled="!link.url"
                />
            </div>
        </div>
    </AppLayout>
</template>
