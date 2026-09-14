<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    sources: Object,
    strategies: Array,
    filters: Object,
    queueItems: Array,
    queueCounts: Object,
});

const filterUnit = ref(props.filters?.unit || '');
const filterType = ref(props.filters?.type || '');

// Tabs: Quellen vs. Eingang (Approval-Queue)
const activeTab = ref('quellen');

// ─── Approval-Queue ───
const processing = ref(false);
const approveMsg = ref('');
const selectedAngles = reactive({}); // { queueItemId: { angleIndex: bool } }

function isAngleSelected(itemId, idx) {
    return selectedAngles[itemId]?.[idx] !== false; // Default: alles ausgewählt
}
function toggleAngle(itemId, idx) {
    if (!selectedAngles[itemId]) selectedAngles[itemId] = {};
    selectedAngles[itemId][idx] = !isAngleSelected(itemId, idx);
}
function selectedIndexes(itemId) {
    const drafts = queueItemsFor(itemId);
    return drafts.map((_, i) => i).filter(i => isAngleSelected(itemId, i));
}
function queueItemsFor(itemId) {
    return (props.queueItems || []).find(q => q.id === itemId)?.extracted_angles || [];
}

async function approveItem(item) {
    const indexes = selectedIndexes(item.id);
    if (!indexes.length) { approveMsg.value = '⚠️ Keine Angles ausgewählt.'; return; }
    const res = await fetch(`/api/source-queue/${item.id}/approve`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ angle_indexes: indexes }),
    });
    const data = await res.json();
    if (res.ok) {
        approveMsg.value = `✅ ${data.created} Angles übernommen (Batch ${data.batch_key}).`;
        router.reload({ preserveState: false, preserveScroll: true });
    } else {
        approveMsg.value = '⚠️ ' + (data.message || 'Fehler beim Übernehmen.');
    }
}
async function rejectItem(item) {
    await fetch(`/api/source-queue/${item.id}/reject`, {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json' },
    });
    router.reload({ preserveState: false, preserveScroll: true });
}
async function processQueue() {
    if (processing.value) return;
    processing.value = true;
    approveMsg.value = '';
    try {
        const res = await fetch('/api/source-queue/process', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() } });
        await res.json();
        router.reload({ preserveState: false, preserveScroll: true });
    } catch (e) { approveMsg.value = '⚠️ ' + e.message; }
    processing.value = false;
}

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
    research: '🔬',    rss: '📶',
    screenshot: '📸',
    community: '👥',
    video: '🎥',
    audio: '🎧',};

// ─── Monitoring: URL erfassen + aktivieren ───
const monitoringSource = ref(null);   // { id, url, frequency }
const monitoringSaving = ref(false);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

function openMonitoring(source) {
    monitoringSource.value = { id: source.id, url: source.url || '', frequency: source.frequency || 'weekly' };
}
function closeMonitoring() { monitoringSource.value = null; }

async function toggleMonitoring(source) {
    const res = await fetch(`/api/sources/${source.id}`, {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ monitor: !source.monitor }),
    });
    if (res.ok) {
        closeMonitoring();
        router.reload({ preserveState: true, preserveScroll: true });
    }
}

// ─── Manuelles Re-Crawlen (nur URL-Quellen) ───
const crawlingId = ref(null);
const crawlMsg = ref('');

async function crawlSource(source) {
    if (crawlingId.value) return;
    crawlingId.value = source.id;
    crawlMsg.value = '';
    try {
        const res = await fetch(`/api/sources/${source.id}/crawl`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json' },
        });
        const data = await res.json();
        if (res.ok) {
            const status = data.crawl?.status;
            if (status === 'changed') crawlMsg.value = `✅ ${source.id}: Änderung erkannt — ${data.crawl?.angles_created || 0} Angles erstellt.`;
            else if (status === 'unchanged') crawlMsg.value = `ℹ️ ${source.id}: Keine Änderung.`;
            else crawlMsg.value = `⚠️ ${source.id}: Crawl-Fehler.`;
            router.reload({ preserveState: true, preserveScroll: true });
        } else {
            crawlMsg.value = '⚠️ ' + (data.message || 'Fehler.');
        }
    } catch (e) {
        crawlMsg.value = '⚠️ ' + e.message;
    } finally {
        crawlingId.value = null;
    }
}

async function saveMonitoringUrl() {
    if (!monitoringSource.value.url) return;
    monitoringSaving.value = true;
    try {
        const res = await fetch(`/api/sources/${monitoringSource.value.id}`, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json' },
            body: JSON.stringify({
                url: monitoringSource.value.url,
                frequency: monitoringSource.value.frequency,
                monitor: true,
            }),
        });
        if (res.ok) {
            closeMonitoring();
            router.reload({ preserveState: true, preserveScroll: true });
        }
    } finally { monitoringSaving.value = false; }
}
function fmtDate(d) { return d ? new Date(d).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'; }
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Quellen</h2>
            <p class="text-gray-400 mt-1">{{ sources?.total || 0 }} Quellen</p>
            <p v-if="crawlMsg" class="text-sm mt-2" :class="crawlMsg.startsWith('✅') ? 'text-green-600' : crawlMsg.startsWith('⚠️') ? 'text-red-600' : 'text-gray-600'">{{ crawlMsg }}</p>
        </div>

        <!-- Tabs: Quellen / Eingang -->
        <div class="flex gap-1 mb-5">
            <button @click="activeTab = 'quellen'"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                :class="activeTab === 'quellen' ? 'bg-white border border-gray-200 text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'">
                📚 Quellen ({{ sources?.total || 0 }})
            </button>
            <button @click="activeTab = 'eingang'"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-colors relative"
                :class="activeTab === 'eingang' ? 'bg-white border border-gray-200 text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'">
                📥 Eingang
                <span v-if="queueCounts?.done" class="ml-1.5 text-[10px] bg-green-600 text-white px-1.5 py-0.5 rounded-full font-bold">{{ queueCounts.done }}</span>
                <span v-if="queueCounts?.pending" class="ml-1 text-[10px] bg-amber-500 text-white px-1.5 py-0.5 rounded-full" :title="queueCounts.pending + ' Items warten auf Angle-Extraktion'">{{ queueCounts.pending }}⏳</span>
            </button>
        </div>

        <template v-if="activeTab === 'quellen'">
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
                <option value="rss">RSS-Feed</option>
                <option value="screenshot">Screenshot (OCR)</option>
                <option value="community">Community</option>
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
                            <template v-if="s.type === 'url' && s.url">
                                <div v-if="s.monitor" class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 px-2 py-1 rounded-full">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
                                        Auto-Scrape · {{ s.frequency }}
                                    </span>
                                    <span class="text-xs text-gray-400" :title="'Zuletzt geprüft'">{{ fmtDate(s.last_checked_at) }}</span>
                                    <button @click="crawlSource(s)" :disabled="crawlingId === s.id"
                                        class="text-xs px-2 py-1 rounded-md border border-green-200 text-green-700 hover:bg-green-50 disabled:opacity-50"
                                        :title="'Jetzt manuell crawlen'">
                                        {{ crawlingId === s.id ? '↻ …' : '↻ Crawl' }}
                                    </button>
                                    <button @click="toggleMonitoring(s)" class="text-xs text-gray-400 hover:text-red-500" title="Monitoring stoppen">✕</button>
                                </div>
                                <span v-else class="inline-flex items-center gap-1.5 text-xs text-gray-500 border border-gray-200 px-2 py-1 rounded-full">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                    Kein Auto-Scrape
                                    <button @click="crawlSource(s)" :disabled="crawlingId === s.id"
                                        class="text-gray-400 hover:text-green-600 disabled:opacity-50 ml-1" title="Einmalig crawlen">
                                        {{ crawlingId === s.id ? '↻' : '↻' }}
                                    </button>
                                    <button @click="toggleMonitoring(s)" class="text-gray-400 hover:text-green-600" title="Monitoring starten">▶</button>
                                </span>
                            </template>
                            <button v-else-if="s.type === 'url'" @click="openMonitoring(s)"
                                class="text-xs px-2 py-1 rounded-full border border-dashed border-gray-300 text-gray-400 hover:border-green-400 hover:text-green-600"
                                title="URL ergänzen & überwachen">+ URL</button>
                            <span v-else class="text-xs text-gray-300">—</span>
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
        </template>

        <!-- ═══════════ TAB: EINGANG (Approval-Queue) ═══════════ -->
        <template v-else>
            <div class="neu-card p-4 mb-4 flex items-center justify-between gap-4 flex-wrap">
                <div class="text-sm text-gray-600">
                    <p class="font-medium text-gray-800">📥 Automatisch erkannte Inhalte</p>
                    <p class="text-xs text-gray-400 mt-0.5">
                        RSS-Feeds, Screenshots (OCR) und Community-Posts landen hier.
                        <span v-if="queueCounts?.pending">
                            <strong class="text-amber-600">{{ queueCounts.pending }}</strong> wartet noch auf Angle-Extraktion.
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="processQueue" :disabled="processing || !queueCounts?.pending"
                        class="px-4 py-2 text-sm rounded-lg bg-amber-500 text-white hover:bg-amber-600 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        title="Führt die LLM-Angle-Extraktion für wartende Items aus">
                        {{ processing ? 'Verarbeite…' : '🤖 Wartende extrahieren' }}
                    </button>
                    <a href="/quick-input" class="px-4 py-2 text-sm rounded-lg neu-btn-primary" title="Neue RSS-Feed-Quelle anlegen">
                        + Neue Quelle
                    </a>
                </div>
            </div>

            <p v-if="approveMsg" class="text-sm mb-4" :class="approveMsg.startsWith('✅') ? 'text-green-600' : 'text-amber-600'">{{ approveMsg }}</p>

            <div v-if="!queueItems?.length" class="neu-card p-10 text-center">
                <p class="text-4xl mb-2">📭</p>
                <p class="text-sm text-gray-700 font-medium">Noch keine Inhalte in der Warteschlange</p>
                <p class="text-xs text-gray-400 mt-1 max-w-md mx-auto">
                    Lege eine RSS-Feed-Quelle an (über ⚡ Quick Input → Tab „RSS-Feed").
                    Neue Artikel erscheinen dann hier mit automatisch extrahierten Angle-Vorschlägen.
                </p>
            </div>

            <div v-else class="space-y-4">
                <div v-for="item in queueItems" :key="item.id" class="neu-card p-5">
                    <!-- Kopfzeile: Quelle + Item -->
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-base">{{ typeIcons[item.source?.type] || '📄' }}</span>
                                <a v-if="item.item_url" :href="item.item_url" target="_blank" rel="noopener"
                                    class="text-sm font-semibold text-gray-800 hover:text-green-600 hover:underline truncate">
                                    {{ item.item_title || item.source?.title || '(ohne Titel)' }} ↗
                                </a>
                                <span v-else class="text-sm font-semibold text-gray-800 truncate">
                                    {{ item.item_title || item.source?.title || '(ohne Titel)' }}
                                </span>
                                <span class="text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{{ item.source?.type || '?' }}</span>
                                <span v-if="item.strategy?.key" class="text-[10px] bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full">{{ item.strategy.key }}</span>
                            </div>
                            <p class="text-[11px] text-gray-400 mt-1">
                                aus „{{ item.source?.title || '?' }}" · {{ fmtDate(item.created_at) }} ·
                                Batch <span class="font-mono">{{ item.batch_key || '—' }}</span>
                            </p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <button @click="approveItem(item)" :disabled="!selectedIndexes(item.id).length"
                                class="px-3 py-1.5 text-xs rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-40 font-medium">
                                ✓ {{ selectedIndexes(item.id).length || 0 }} übernehmen
                            </button>
                            <button @click="rejectItem(item)"
                                class="px-3 py-1.5 text-xs rounded-lg bg-gray-100 text-gray-600 hover:bg-red-50 hover:text-red-600"
                                title="Item ablehnen und aus der Liste entfernen">✕</button>
                        </div>
                    </div>

                    <!-- Draft-Angles -->
                    <div class="space-y-2">
                        <div v-for="(draft, idx) in (item.extracted_angles || [])" :key="idx"
                            @click="toggleAngle(item.id, idx)"
                            class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors"
                            :class="isAngleSelected(item.id, idx) ? 'border-green-300 bg-green-50/40' : 'border-gray-200 bg-gray-50 opacity-60'">
                            <span class="mt-0.5 w-5 h-5 rounded-full border-2 flex items-center justify-center text-[10px] shrink-0 transition-colors"
                                :class="isAngleSelected(item.id, idx) ? 'border-green-500 bg-green-500 text-white' : 'border-gray-300 text-transparent'">✓</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 leading-snug">{{ draft.angle }}</p>
                                <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                    <span v-if="draft.icp" class="neu-badge neu-badge-info text-[10px]">{{ draft.icp }}</span>
                                    <span v-if="draft.pain_cluster" class="text-[10px] text-gray-400">{{ draft.pain_cluster }}</span>
                                    <span v-if="draft.statement_type" class="text-[10px] text-gray-400">· {{ draft.statement_type }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
