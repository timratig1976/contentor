<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    sources: Object,
    strategies: Array,
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

// ─── Monitoring: URL erfassen + aktivieren ───
const monitoringSource = ref(null);   // { id, url, frequency }
const monitoringSaving = ref(false);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

function openMonitoring(source) {
    monitoringSource.value = { id: source.id, url: source.url || '', frequency: source.frequency || 'weekly' };
}
function closeMonitoring() { monitoringSource.value = null; }

async function toggleMonitoring(source) {
    await router.patch(`/api/sources/${source.id}`, { monitor: !source.monitor }, {
        preserveState: true, preserveScroll: true,
        onSuccess: closeMonitoring,
    });
}
async function saveMonitoringUrl() {
    if (!monitoringSource.value.url) return;
    monitoringSaving.value = true;
    try {
        await router.patch(`/api/sources/${monitoringSource.value.id}`, {
            url: monitoringSource.value.url,
            frequency: monitoringSource.value.frequency,
            monitor: true,
        }, { preserveState: true, preserveScroll: true, onSuccess: closeMonitoring });
    } finally { monitoringSaving.value = false; }
}
function fmtDate(d) { return d ? new Date(d).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'; }
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Quellen</h2>
            <p class="text-gray-400 mt-1">{{ sources?.total || 0 }} Quellen</p>
        </div>

        <!-- Filters -->
        <div class="neu-card p-4  mb-6 flex flex-wrap gap-3">
            <select v-model="filterUnit" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Strategies</option>
                <option v-for="u in strategies" :key="u.key" :value="u.key">{{ u.name }}</option>
            </select>
            <select v-model="filterType" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Typen</option>
                <option value="pdf">PDF</option>
                <option value="url">URL</option>
                <option value="interview">Interview</option>
                <option value="intern">Intern</option>
                <option value="research">Research</option>
            </select>
        </div>

        <!-- Table -->
        <div class="neu-card overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-neu-border">
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">ID</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Titel</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Typ</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Sichtbarkeit</th>
                        <th class="text-center px-6 py-3 text-xs font-medium text-gray-400 uppercase">Angles</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Batch</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Unit</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">📡 Monitoring</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-400 uppercase">Datum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="s in sources?.data" :key="s.id" class="hover:bg-gray-300/50">
                        <td class="px-6 py-4 text-sm font-mono text-gray-400">{{ s.id }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <span>{{ typeIcons[s.type] || '📄' }}</span>
                                <span class="text-sm text-gray-800">{{ s.title }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ s.type }}</td>
                        <td class="px-6 py-4">
                            <span class="text-xs px-2 py-0.5 rounded-full"
                                :class="s.visibility === 'intern' ? 'bg-yellow-50 text-yellow-700' : 'bg-green-50 text-green-700'"
                            >{{ s.visibility }}</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-sm font-bold text-gray-800">{{ s.angles?.length || 0 }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ s.batch_key || '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ s.strategy?.name || '—' }}</td>
                        <td class="px-6 py-4">
                            <div v-if="s.monitor" class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 px-2 py-1 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                                    Auto-Scrape · {{ s.frequency }}
                                </span>
                                <span class="text-xs text-gray-400" :title="'Zuletzt geprüft'">{{ fmtDate(s.last_checked_at) }}</span>
                                <button @click="toggleMonitoring(s)" class="text-xs text-gray-400 hover:text-red-500" title="Monitoring stoppen">✕</button>
                            </div>
                            <span v-else-if="s.url" class="inline-flex items-center gap-1.5 text-xs text-gray-500 border border-gray-200 px-2 py-1 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                Kein Auto-Scrape
                                <button @click="toggleMonitoring(s)" class="text-gray-400 hover:text-green-600" title="Monitoring starten">▶</button>
                            </span>
                            <button v-else @click="openMonitoring(s)" class="text-xs text-gray-400 hover:text-green-600" title="URL ergänzen & überwachen">URL + Überwachen</button>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ new Date(s.created_at).toLocaleDateString('de-DE') }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- URL-Erfassungsmodal -->
            <div v-if="monitoringSource" class="fixed inset-0 bg-black/30 flex items-center justify-center z-50 p-4">
                <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
                    <h3 class="text-base font-semibold text-gray-800 mb-1">Quelle überwachen</h3>
                    <p class="text-xs text-gray-400 mb-4">URL angeben + Crawling-Frequenz wählen. Der Content wird regelmäßig geprüft; bei Änderungen entstehen automatisch neue Angles.</p>
                    <label class="block text-xs text-gray-400 mb-1">URL</label>
                    <input v-model="monitoringSource.url" type="url" placeholder="https://blog.beispiel.de/crm"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-800 focus:outline-none focus:border-green-500 mb-3" />
                    <label class="block text-xs text-gray-400 mb-1">Frequenz</label>
                    <select v-model="monitoringSource.frequency" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-800 focus:outline-none focus:border-green-500 mb-4">
                        <option value="daily">Täglich</option>
                        <option value="weekly">Wöchentlich (empfohlen)</option>
                        <option value="biweekly">Alle 2 Wochen</option>
                    </select>
                    <div class="flex justify-end gap-2">
                        <button @click="closeMonitoring" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-800">Abbrechen</button>
                        <button @click="saveMonitoringUrl" :disabled="monitoringSaving || !monitoringSource.url"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                            {{ monitoringSaving ? 'Wird gecrawlt…' : 'Überwachen & ersten Crawl starten' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="sources?.links?.length > 3" class="px-6 py-4 border-t border-neu-border flex justify-center gap-1">
                <button
                    v-for="link in sources.links"
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
