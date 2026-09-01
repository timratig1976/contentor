<script setup>
import { ref, computed, onMounted } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ events: Array, stats: Object, monitoredSources: Array });

const filters = ref({ type: '', status: '' });
const types = ['search', 'scrape', 'crawl_start', 'crawl_result'];
const statuses = ['success', 'changed', 'unchanged', 'error'];

const typeLabels = {
    search: { label: 'Web Search', icon: '🔍' },
    scrape: { label: 'Scrape', icon: '📄' },
    crawl_start: { label: 'Crawl Start', icon: '🕷️' },
    crawl_result: { label: 'Crawl Ergebnis', icon: '✅' },
};
const statusStyles = {
    success: 'bg-green-50 text-green-700 border-green-200',
    changed: 'bg-blue-50 text-blue-700 border-blue-200',
    unchanged: 'bg-gray-100 text-gray-600 border-gray-200',
    error: 'bg-red-50 text-red-700 border-red-200',
};
const statusLabels = {
    success: 'OK',
    changed: 'Geändert',
    unchanged: 'Unverändert',
    error: 'Fehler',
};

const visibleEvents = computed(() =>
    (props.events || []).filter(e =>
        (!filters.value.type || e.type === filters.value.type) &&
        (!filters.value.status || e.status === filters.value.status)
    )
);

const expanded = ref(new Set());
function toggle(id) {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
}

function fmtDate(d) {
    return new Date(d).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}
function fmtDuration(ms) {
    return ms >= 1000 ? (ms / 1000).toFixed(1) + 's' : ms + 'ms';
}

// Run now
const running = ref(false);
const runResult = ref(null);
async function runMonitoring(force = false) {
    running.value = true; runResult.value = null;
    try {
        const res = await fetch('/api/monitoring/run' + (force ? '?force=1' : ''), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        runResult.value = await res.json();
        refresh();
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { running.value = false; }
}

// Single source check
const checking = ref(null);
async function checkSource(id) {
    checking.value = id;
    try {
        await fetch(`/api/monitoring/sources/${id}/check`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        refresh();
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { checking.value = null; }
}

// Test search
const testQuery = ref('');
const testLoading = ref(false);
const testResults = ref(null);
async function runTestSearch() {
    testLoading.value = true; testResults.value = null;
    try {
        const res = await fetch('/api/monitoring/test-search', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ query: testQuery.value }),
        });
        const data = await res.json();
        if (data.error) { testResults.value = { error: data.error }; }
        else { testResults.value = data; }
        refresh();
    } catch (e) { testResults.value = { error: e.message }; }
    finally { testLoading.value = false; }
}

// Pagination for events (lazy via page reload from server)
const page = ref(1);
const perPage = 50;
const pagedEvents = computed(() => visibleEvents.value.slice((page.value - 1) * perPage, page.value * perPage));
const totalPages = computed(() => Math.max(1, Math.ceil(visibleEvents.value.length / perPage)));

// Reload data via full page refresh (simplest: use Inertia router)
import { router } from '@inertiajs/vue3';
function refresh() { router.get('/monitoring', {}, { preserveState: true, preserveScroll: true }); }
</script>

<template>
    <AppLayout>
        <div class="max-w-7xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-800 tracking-tight">📡 Monitoring</h1>
                    <p class="text-sm text-gray-400 mt-1">Alle Web-Aufrufe (Search, Scrape, Crawl) und überwachte Quellen</p>
                </div>
                <button @click="runMonitoring(false)" :disabled="running"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-colors">
                    {{ running ? 'Wird geprüft…' : '▶ Fällige Quellen prüfen' }}
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <div class="neu-card p-4 text-center">
                    <p class="text-2xl font-semibold text-gray-800">{{ stats.total }}</p>
                    <p class="text-xs text-gray-400 mt-1">Events gesamt</p>
                </div>
                <div class="neu-card p-4 text-center">
                    <p class="text-2xl font-semibold text-gray-800">{{ stats.last_7d }}</p>
                    <p class="text-xs text-gray-400 mt-1">Letzte 7 Tage</p>
                </div>
                <div class="neu-card p-4 text-center">
                    <p class="text-2xl font-semibold" :class="stats.changed > 0 ? 'text-blue-600' : 'text-gray-800'">{{ stats.changed }}</p>
                    <p class="text-xs text-gray-400 mt-1">Quellen geändert</p>
                </div>
                <div class="neu-card p-4 text-center">
                    <p class="text-2xl font-semibold" :class="stats.errors > 0 ? 'text-red-600' : 'text-gray-800'">{{ stats.errors }}</p>
                    <p class="text-xs text-gray-400 mt-1">Fehler</p>
                </div>
                <div class="neu-card p-4 text-center">
                    <p class="text-2xl font-semibold text-gray-800">{{ stats.credits_7d }}</p>
                    <p class="text-xs text-gray-400 mt-1">Credits (7 Tage)</p>
                </div>
            </div>

            <!-- Run result -->
            <div v-if="runResult" class="neu-card p-4 mb-6">
                <h3 class="text-sm font-medium text-gray-800 mb-2">Letzter Monitoring-Lauf</h3>
                <p class="text-xs text-gray-400">
                    Geprüft: <strong class="text-gray-800">{{ runResult.checked }}</strong> ·
                    Geändert: <strong class="text-blue-600">{{ runResult.changed }}</strong> ·
                    Fehler: <strong :class="runResult.errors > 0 ? 'text-red-600' : 'text-gray-800'">{{ runResult.errors }}</strong>
                </p>
                <div v-for="d in runResult.details" :key="d.source" class="text-xs text-gray-500 mt-1">
                    {{ d.source }} → <span :class="d.status === 'error' ? 'text-red-600' : d.status === 'changed' ? 'text-blue-600' : 'text-gray-400'">{{ d.status }}</span>
                    <span v-if="d.angles_created > 0" class="text-green-600 ml-2">+{{ d.angles_created }} Angles</span>
                    <span v-if="d.error" class="text-red-500 ml-2">({{ d.error }})</span>
                </div>
                <button @click="runResult = null" class="text-xs text-gray-400 hover:text-red-500 mt-1">✕ Ausblenden</button>
            </div>

            <!-- Monitored sources -->
            <div class="neu-card p-5 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-medium text-gray-800">Überwachte Quellen ({{ monitoredSources.length }})</h2>
                    <a href="/quellen" class="text-xs text-gray-400 hover:text-green-600">Verwalten auf Quellen-Seite →</a>
                </div>
                <div v-if="monitoredSources.length === 0" class="text-sm text-gray-400 py-4 text-center">
                    Noch keine überwachten Quellen. In <a href="/quellen" class="text-blue-600 hover:underline">Quellen</a> Monitoring für eine Quelle aktivieren.
                </div>
                <div v-else class="space-y-2">
                    <div v-for="s in monitoredSources" :key="s.id" class="flex items-center gap-3 py-2 border-b border-gray-100 last:border-0">
                        <span class="text-lg">🌐</span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-800 truncate">{{ s.title }}</p>
                            <p class="text-xs text-gray-400 truncate">{{ s.url }}</p>
                        </div>
                        <span class="text-xs text-gray-400">📅 {{ s.frequency }}</span>
                        <span class="text-xs" :class="s.last_checked_at ? 'text-gray-400' : 'text-yellow-600'">
                            {{ s.last_checked_at ? 'Zuletzt: ' + fmtDate(s.last_checked_at) : 'Noch nie geprüft' }}
                        </span>
                        <button @click="checkSource(s.id)" :disabled="checking === s.id"
                            class="px-2 py-1 text-xs bg-gray-100 text-gray-600 rounded-md hover:bg-green-50 hover:text-green-600 disabled:opacity-50">
                            {{ checking === s.id ? '…' : 'Jetzt prüfen' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Test search -->
            <div class="neu-card p-5 mb-6">
                <h2 class="text-sm font-medium text-gray-800 mb-3">🔍 Test-Suche (EdenAI/Firecrawl)</h2>
                <div class="flex gap-2">
                    <input v-model="testQuery" @keyup.enter="runTestSearch"
                        class="flex-1 bg-neu border-0 rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400"
                        placeholder="z. B. 'CRM Datenqualität B2B SaaS'" />
                    <button @click="runTestSearch" :disabled="testLoading || !testQuery"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                        {{ testLoading ? 'Suche…' : 'Suchen' }}
                    </button>
                </div>
                <div v-if="testResults" class="mt-4">
                    <div v-if="testResults.error" class="text-red-600 text-sm bg-red-50 rounded-lg p-3">⚠ {{ testResults.error }}</div>
                    <div v-else>
                        <p class="text-xs text-gray-400 mb-2">{{ testResults.results.length }} Treffer · Kosten {{ testResults.cost }}</p>
                        <div class="space-y-2">
                            <a v-for="r in testResults.results" :key="r.url" :href="r.url" target="_blank" rel="noopener"
                                class="block bg-neu rounded-lg p-3 hover:bg-gray-50 transition-colors">
                                <p class="text-sm font-medium text-gray-800">{{ r.title }}</p>
                                <p class="text-xs text-gray-400 mt-0.5 truncate">{{ r.url }}</p>
                                <p class="text-xs text-gray-500 mt-1 line-clamp-2" v-if="r.content">{{ r.content }}</p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Event feed -->
            <div class="neu-card p-5">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                    <h2 class="text-sm font-medium text-gray-800">Event-Feed</h2>
                    <div class="flex items-center gap-2">
                        <select v-model="filters.type" class="bg-neu border-0 rounded-lg p-1.5 text-xs text-gray-700 focus:outline-none">
                            <option value="">Alle Typen</option>
                            <option v-for="t in types" :key="t" :value="t">{{ typeLabels[t]?.label || t }}</option>
                        </select>
                        <select v-model="filters.status" class="bg-neu border-0 rounded-lg p-1.5 text-xs text-gray-700 focus:outline-none">
                            <option value="">Alle Status</option>
                            <option v-for="s in statuses" :key="s" :value="s">{{ statusLabels[s] || s }}</option>
                        </select>
                        <button @click="refresh" class="text-xs text-gray-400 hover:text-green-600 px-2 py-1">🔄 Neu laden</button>
                    </div>
                </div>

                <div v-if="pagedEvents.length === 0" class="text-sm text-gray-400 py-4 text-center">Keine Events. Starte eine Suche oder aktiviere Quellen-Monitoring.</div>
                <div v-else class="divide-y divide-gray-100">
                    <div v-for="e in pagedEvents" :key="e.id">
                        <button @click="toggle(e.id)" class="w-full flex items-center gap-3 py-2 px-2 -mx-2 text-left hover:bg-gray-50 rounded transition-colors">
                            <span class="text-lg shrink-0">{{ typeLabels[e.type]?.icon || '🔹' }}</span>
                            <span class="text-xs text-gray-400 w-28 shrink-0">{{ fmtDate(e.created_at) }}</span>
                            <span class="flex-1 min-w-0">
                                <span class="text-sm text-gray-800 block truncate">{{ typeLabels[e.type]?.label || e.type }}</span>
                                <span class="text-xs text-gray-400 truncate block">{{ e.query || e.url || '—' }}</span>
                            </span>
                            <span v-if="e.duration_ms" class="text-xs text-gray-300 shrink-0 hidden md:inline">{{ fmtDuration(e.duration_ms) }}</span>
                            <span v-if="e.credits" class="text-xs text-gray-400 shrink-0 hidden md:inline">{{ e.credits }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full border shrink-0" :class="statusStyles[e.status] || 'bg-gray-100 text-gray-500'">
                                {{ statusLabels[e.status] || e.status }}
                            </span>
                        </button>
                        <div v-if="expanded.has(e.id)" class="mb-3 mt-1 ml-7 border-l-2 border-gray-100 pl-3 space-y-2">
                            <div v-if="e.error">
                                <p class="text-xs text-gray-400">Fehler</p>
                                <p class="text-xs text-red-600 whitespace-pre-wrap">{{ e.error }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400 mb-0.5">Parameter</p>
                                <pre class="text-xs text-gray-600 whitespace-pre-wrap bg-neu rounded-lg p-2 max-h-48 overflow-y-auto">{{ JSON.stringify(e.input, null, 2) }}</pre>
                            </div>
                            <p class="text-xs text-gray-300">Modell: {{ e.model }} · Provider: {{ e.provider }} · Dauer: {{ fmtDuration(e.duration_ms || 0) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="totalPages > 1" class="flex items-center justify-center gap-2 mt-4 pt-3 border-t border-gray-100">
                    <button @click="page--" :disabled="page <= 1" class="text-xs px-2 py-1 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30">‹ Vorherige</button>
                    <span class="text-xs text-gray-400">Seite {{ page }} / {{ totalPages }}</span>
                    <button @click="page++" :disabled="page >= totalPages" class="text-xs px-2 py-1 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30">Nächste ›</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
