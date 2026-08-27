<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    sources: Object,
    units: Array,
    filters: Object,
});

const filterUnit = ref(props.filters?.unit || '');
const filterType = ref(props.filters?.type || '');

function applyFilters() {
    router.get('/quellen', {
        unit: filterUnit.value || undefined,
        type: filterType.value || undefined,
    }, { preserveState: true });
}

const typeIcons = {
    pdf: '📄',
    url: '🔗',
    interview: '🎤',
    intern: '🏠',
    research: '🔬',
};
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-white">Quellen</h2>
            <p class="text-gray-400 mt-1">{{ sources?.total || 0 }} Quellen</p>
        </div>

        <!-- Filters -->
        <div class="bg-gray-900 rounded-xl p-4 border border-gray-800 mb-6 flex flex-wrap gap-3">
            <select v-model="filterUnit" @change="applyFilters" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Alle Units</option>
                <option v-for="u in units" :key="u.key" :value="u.key">{{ u.name }}</option>
            </select>
            <select v-model="filterType" @change="applyFilters" class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white">
                <option value="">Alle Typen</option>
                <option value="pdf">PDF</option>
                <option value="url">URL</option>
                <option value="interview">Interview</option>
                <option value="intern">Intern</option>
                <option value="research">Research</option>
            </select>
        </div>

        <!-- Table -->
        <div class="bg-gray-900 rounded-xl border border-gray-800 overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-800">
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">ID</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Titel</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Typ</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Sichtbarkeit</th>
                        <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Angles</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Batch</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Unit</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Datum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="s in sources?.data" :key="s.id" class="hover:bg-gray-800/50">
                        <td class="px-6 py-4 text-sm font-mono text-indigo-400">{{ s.id }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <span>{{ typeIcons[s.type] || '📄' }}</span>
                                <span class="text-sm text-white">{{ s.title }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ s.type }}</td>
                        <td class="px-6 py-4">
                            <span class="text-xs px-2 py-0.5 rounded-full"
                                :class="s.visibility === 'intern' ? 'bg-yellow-600/20 text-yellow-400' : 'bg-green-600/20 text-green-400'"
                            >{{ s.visibility }}</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-bold text-white">{{ s.angles?.length || 0 }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ s.batch_key || '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ s.unit?.name }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ new Date(s.created_at).toLocaleDateString('de-DE') }}</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="sources?.links?.length > 3" class="px-6 py-4 border-t border-gray-800 flex justify-center gap-1">
                <button
                    v-for="link in sources.links"
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
