<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    angles: Object,
    units: Array,
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
    if (score === null) return 'text-gray-500';
    if (score >= 10) return 'text-green-400';
    if (score >= 7) return 'text-yellow-400';
    return 'text-red-400';
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-white">Angles</h2>
                <p class="text-gray-400 mt-1">{{ angles?.total || 0 }} Angles gesamt</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-gray-900 rounded-xl p-4 border border-gray-800 mb-6 flex flex-wrap gap-3">
            <select v-model="filterUnit" @change="applyFilters" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Alle Units</option>
                <option v-for="u in units" :key="u.key" :value="u.key">{{ u.name }}</option>
            </select>
            <select v-model="filterBatch" @change="applyFilters" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Alle Batches</option>
                <option v-for="b in batches" :key="b" :value="b">{{ b }}</option>
            </select>
            <select v-model="filterIcp" @change="applyFilters" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Alle ICPs</option>
                <option value="B2B-1">B2B-1</option>
                <option value="B2B-2">B2B-2</option>
                <option value="B2B-3">B2B-3</option>
                <option value="B2C">B2C</option>
                <option value="UNI">UNI</option>
                <option value="BK">BK</option>
            </select>
            <select v-model="filterStatus" @change="applyFilters" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Alle Status</option>
                <option value="neu">Neu</option>
                <option value="bewertet">Bewertet</option>
                <option value="approved">Approved</option>
                <option value="verworfen">Verworfen</option>
            </select>
            <select v-model="filterFunnel" @change="applyFilters" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Alle Funnel</option>
                <option value="ToFu">ToFu</option>
                <option value="MoFu">MoFu</option>
                <option value="BoFu">BoFu</option>
            </select>
        </div>

        <!-- Table -->
        <div class="bg-gray-900 rounded-xl border border-gray-800 overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-800">
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Angle</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">ICP</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Cluster</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Funnel</th>
                        <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Score</th>
                        <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Rang</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Status</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Unit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="angle in angles?.data" :key="angle.id" class="hover:bg-gray-800/50 cursor-pointer" @click="router.visit(`/angles/${angle.id}`)">
                        <td class="px-6 py-4">
                            <p class="text-sm text-white line-clamp-2 max-w-md">{{ angle.angle }}</p>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs px-2 py-0.5 rounded-full bg-indigo-600/20 text-indigo-400">{{ angle.icp }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ angle.pain_cluster }}</td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ angle.funnel || '—' }}</td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-lg font-bold" :class="scoreColor(angle.ranking_score)">{{ angle.ranking_score ?? '—' }}</span>
                        </td>
                        <td class="px-6 py-4 text-center text-sm text-gray-400">{{ angle.ranking_rang ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <span class="text-xs px-2 py-1 rounded-full"
                                :class="{
                                    'bg-gray-700 text-gray-300': angle.status === 'neu',
                                    'bg-blue-600/20 text-blue-400': angle.status === 'bewertet',
                                    'bg-green-600/20 text-green-400': angle.status === 'approved',
                                    'bg-red-600/20 text-red-400': angle.status === 'verworfen',
                                }"
                            >{{ angle.status }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ angle.unit?.name }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="angles?.links?.length > 3" class="px-6 py-4 border-t border-gray-800 flex justify-center gap-1">
                <button
                    v-for="link in angles.links"
                    :key="link.label"
                    @click="link.url && router.get(link.url)"
                    class="px-3 py-1 rounded text-sm"
                    :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:bg-gray-800'"
                    v-html="link.label"
                    :disabled="!link.url"
                />
            </div>
        </div>
    </AppLayout>
</template>
