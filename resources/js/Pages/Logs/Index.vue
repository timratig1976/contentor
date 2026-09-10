<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ agentLogs: Array, stats: Object });

function modelLabel(provider, model) {
    const m = (model || '').replace(new RegExp('^' + (provider || '') + '/'), '');
    return m ? `${provider}/${m}` : provider;
}

const filterAgent = ref('');
const filterStatus = ref('');
const filterSearch = ref('');

const agentTypes = computed(() => [...new Set((props.agentLogs || []).map(l => l.agent))].sort());
const filteredLogs = computed(() => {
    const q = filterSearch.value.trim().toLowerCase();
    return (props.agentLogs || []).filter(l =>
        (!filterAgent.value || l.agent === filterAgent.value) &&
        (!filterStatus.value || l.status === filterStatus.value) &&
        (!q || (l.input || '').toLowerCase().includes(q) || (l.output || '').toLowerCase().includes(q))
    );
});

const expanded = ref(new Set());
function toggleLog(id) {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
}

function fmtTs(ts) {
    return new Date(ts).toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">LLM-Logs</h1>
                <p class="text-sm text-gray-500 mt-1">Alle KI-Aufrufe — Agents, Quality-Gate, Bild-Generierung, Assistant</p>
            </div>
            <div class="flex gap-2">
                <button @click="router.reload({ only: ['agentLogs', 'stats'] })"
                    class="px-3 py-1.5 rounded-lg text-sm font-medium text-gray-700 bg-white border border-gray-200 hover:bg-gray-50 transition-colors">
                    ↻ Aktualisieren
                </button>
            </div>
        </div>

        <!-- Stat-Kacheln -->
        <div class="grid grid-cols-3 gap-3 mb-5">
            <div class="neu-card p-4">
                <div class="text-2xl font-bold text-gray-900 tabular-nums">{{ stats?.total ?? 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">Gesamt</div>
            </div>
            <div class="neu-card p-4">
                <div class="text-2xl font-bold text-green-600 tabular-nums">{{ stats?.success ?? 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">Erfolgreich</div>
            </div>
            <div class="neu-card p-4">
                <div class="text-2xl font-bold text-red-600 tabular-nums">{{ stats?.error ?? 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">Fehler</div>
            </div>
        </div>

        <!-- Filter -->
        <div class="neu-card p-4 mb-4">
            <div class="flex flex-wrap items-center gap-2">
                <select v-model="filterAgent"
                    class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm text-gray-700 focus:outline-none focus:border-green-500">
                    <option value="">Alle Typen</option>
                    <option v-for="a in agentTypes" :key="a" :value="a">{{ a }}</option>
                </select>
                <select v-model="filterStatus"
                    class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm text-gray-700 focus:outline-none focus:border-green-500">
                    <option value="">Alle Status</option>
                    <option value="success">✓ success</option>
                    <option value="error">✗ error</option>
                </select>
                <input v-model="filterSearch"
                    class="flex-1 min-w-[200px] bg-white border border-gray-200 rounded-lg px-3 py-1.5 text-sm text-gray-700 focus:outline-none focus:border-green-500"
                    placeholder="In Input/Output suchen…" />
            </div>
        </div>

        <!-- Log-Liste -->
        <div class="neu-card overflow-hidden">
            <div class="divide-y divide-gray-100">
                <div v-for="log in filteredLogs" :key="log.id">
                    <button @click="toggleLog(log.id)"
                        class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 transition-colors">
                        <span class="w-2 h-2 rounded-full shrink-0" :class="log.status === 'success' ? 'bg-green-500' : 'bg-red-500'"></span>
                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 shrink-0 font-medium">{{ log.agent }}</span>
                        <span class="text-xs text-gray-400 shrink-0 w-36 hidden md:inline">{{ fmtTs(log.created_at) }}</span>
                        <span class="text-sm text-gray-800 truncate flex-1">{{ log.input }}</span>
                        <span v-if="log.duration_ms" class="text-xs text-gray-400 shrink-0 hidden sm:inline">{{ (log.duration_ms / 1000).toFixed(1) }}s</span>
                        <span class="text-gray-400 text-xs shrink-0 transition-transform" :class="expanded.has(log.id) ? 'rotate-90' : ''">▸</span>
                    </button>
                    <div v-if="expanded.has(log.id)" class="px-4 pb-4 pt-1 space-y-3">
                        <div>
                            <p class="text-xs text-gray-400 mb-1">Input</p>
                            <pre class="text-xs text-gray-800 whitespace-pre-wrap bg-gray-50 rounded-lg p-3 max-h-64 overflow-y-auto">{{ log.input }}</pre>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 mb-1">Output</p>
                            <pre class="text-xs text-gray-800 whitespace-pre-wrap bg-gray-50 rounded-lg p-3 max-h-64 overflow-y-auto">{{ log.output }}</pre>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-400">
                            <span>{{ modelLabel(log.provider, log.model) }}</span>
                            <span>·</span>
                            <span>{{ log.status }}</span>
                            <span v-if="log.tokens_used">· {{ log.tokens_used }} Tokens</span>
                        </div>
                    </div>
                </div>
                <p v-if="!filteredLogs.length" class="text-sm text-gray-400 italic text-center py-12">Keine Einträge für diese Filter.</p>
            </div>
        </div>
    </AppLayout>
</template>