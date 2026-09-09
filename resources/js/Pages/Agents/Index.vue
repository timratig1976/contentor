<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ settings: Object, strategies: Object, agentPrompts: Object, agentModels: Object, stats: Object, agentLogs: Array, personas: Array, workflowLoops: Array });

const activeAgent = ref(null);
const detailTab = ref('prompt');

// Modell-Label ohne doppelten Provider-Präfix anzeigen.
// EdenAI liefert Modell-IDs teils mit führendem "provider/" (z. B. "anthropic/claude-sonnet-4-6"),
// teils ohne — deshalb hier den Präfix einmalig normalisieren.
function modelLabel(provider, model) {
    const m = (model || '').replace(new RegExp('^' + (provider || '') + '/'), '');
    return m ? `${provider}/${m}` : provider;
}

const llmKeys = computed(() => props.settings?.llm_keys || {});
const hasEdenAI = computed(() => !!llmKeys.value?.edenai_key);

const agents = {
    research: { icon: '🔍', name: 'Research', desc: 'Web-Recherche & Quellen-Extraktion' },
    angle: { icon: '🎯', name: 'Angle', desc: 'Angle-Entwicklung & Ranking' },
    production: { icon: '✍️', name: 'Production', desc: 'Content-Produktion aus Angles' },
    review: { icon: '✅', name: 'Review', desc: 'Qualitätsprüfung & Brand-Enforcement' },
    coordinator: { icon: '🧠', name: 'Coordinator', desc: 'Orchestriert den gesamten Workflow' },
};

const prompts = reactive({
    research: props.agentPrompts?.research || '', angle: props.agentPrompts?.angle || '',
    production: props.agentPrompts?.production || '', review: props.agentPrompts?.review || '',
    coordinator: props.agentPrompts?.coordinator || '',
});
const models = reactive({
    research: { provider: props.agentModels?.research?.provider || 'anthropic', model: props.agentModels?.research?.model || 'claude-sonnet-4-6', temperature: props.agentModels?.research?.temperature ?? 0.7, max_tokens: props.agentModels?.research?.max_tokens || 4000, reasoning_effort: props.agentModels?.research?.reasoning_effort || 'medium' },
    angle: { provider: props.agentModels?.angle?.provider || 'anthropic', model: props.agentModels?.angle?.model || 'claude-sonnet-4-6', temperature: props.agentModels?.angle?.temperature ?? 0.5, max_tokens: props.agentModels?.angle?.max_tokens || 8000, reasoning_effort: props.agentModels?.angle?.reasoning_effort || 'medium' },
    production: { provider: props.agentModels?.production?.provider || 'anthropic', model: props.agentModels?.production?.model || 'claude-sonnet-4-6', temperature: props.agentModels?.production?.temperature ?? 0.8, max_tokens: props.agentModels?.production?.max_tokens || 4000, reasoning_effort: props.agentModels?.production?.reasoning_effort || 'medium' },
    review: { provider: props.agentModels?.review?.provider || 'openai', model: props.agentModels?.review?.model || 'gpt-4o', temperature: props.agentModels?.review?.temperature ?? 0.3, max_tokens: props.agentModels?.review?.max_tokens || 3000, reasoning_effort: props.agentModels?.review?.reasoning_effort || 'low' },
    coordinator: { provider: props.agentModels?.coordinator?.provider || 'openai', model: props.agentModels?.coordinator?.model || 'gpt-4o', temperature: props.agentModels?.coordinator?.temperature ?? 0.7, max_tokens: props.agentModels?.coordinator?.max_tokens || 3000, reasoning_effort: props.agentModels?.coordinator?.reasoning_effort || 'low' },
});

// ─── Workflow & Loops ───────────────────────────────────────────────────
const workflowTabs = ['loops', 'workflow'];
const workflowTab = ref('loops');
const loopsSaving = ref(false);
const loopsSaved = ref(false);

const defaultLoops = [
    { id: 'review-rework', name: 'Qualitäts-Loop', from_agent: 'review', to_agent: 'production', condition: 'verdict = "fail"', max_rounds: 2 },
];

const loops = ref((props.workflowLoops || defaultLoops).map(l => ({ ...l })));

// Sicherstellen, dass von der API kommende max_rounds als Number parsbar ist
function clampRounds(v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return 1;
    return Math.min(10, Math.max(1, Math.round(n)));
}
function setLoopRounds(l, v) { l.max_rounds = clampRounds(v); }

function newLoopId() { return 'loop-' + Math.random().toString(36).slice(2, 8); }
function addLoop() {
    loops.value.push({ id: newLoopId(), name: '', from_agent: 'review', to_agent: 'production', condition: '', max_rounds: 1 });
}
function removeLoop(id) { loops.value = loops.value.filter(l => l.id !== id); }
function moveLoop(id, dir) {
    const i = loops.value.findIndex(l => l.id === id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= loops.value.length) return;
    [loops.value[i], loops.value[j]] = [loops.value[j], loops.value[i]];
}
async function saveLoops() {
    loopsSaving.value = true; loopsSaved.value = false;
    try {
        const res = await fetch('/api/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ key: 'workflow_loops', value: loops.value }),
        });
        if (res.ok) { loopsSaved.value = true; setTimeout(() => loopsSaved.value = false, 2000); }
        else alert('Speichern fehlgeschlagen: HTTP ' + res.status);
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { loopsSaving.value = false; }
}
function agentLabel(key) { return agents[key]?.name || key; }

// Workflow-Definition: feste Phasen + dynamische Loops (generierbarer Text für den Coordinator)
const workflowPhases = computed(() => [
    { agent: 'research', label: 'RESEARCH', desc: 'Thema recherchieren, Quellen speichern' },
    { agent: 'angle', label: 'DEVELOP', desc: 'Angles bewerten & ranken' },
    { agent: 'production', label: 'PRODUCE', desc: 'Content aus Top-Angles produzieren' },
    { agent: 'review', label: 'REVIEW', desc: 'Qualität prüfen & Feedback geben' },
]);
const workflowSummary = computed(() => {
    const lines = workflowPhases.value.map((p, i) => `  ${i + 1}. ${p.label} → ${agentLabel(p.agent)}`);
    loops.value.forEach(l => {
        if (!l.name) return;
        const cond = l.condition ? `, wenn ${l.condition}` : '';
        lines.push(`  ↻ ${l.name}: ${agentLabel(l.from_agent)} → ${agentLabel(l.to_agent)} (max. ${l.max_rounds} Runde${l.max_rounds > 1 ? 'n' : ''}${cond})`);
    });
    return lines.join('\n');
});
const workflowText = computed(() =>
    'Workflow:\n' + workflowSummary.value +
    '\n\nRegeln:\n' +
    '- Phasen in der Reihenfolge ausführen, Ergebnis einer Phase abwarten\n' +
    '- Bei Fehlern abbrechen und das Problem melden' +
    (loops.value.length
        ? '\n- Bei Loop-Erreichen ohne Erfolg: abbrechen und offene Issues melden'
        : ''));
const workflowCopied = ref(false);
async function copyWorkflow() {
    try { await navigator.clipboard.writeText(workflowText.value); } catch { /* Fallback unten */ }
    workflowCopied.value = true;
    setTimeout(() => workflowCopied.value = false, 1500);
}

onMounted(() => {
    // Fallback für ältere Seeds: Defaults mitnehmen, wenn nichts gesetzt
    if (!loops.value.length) loops.value = defaultLoops.map(l => ({ ...l }));
});

// Auto-Save bei Änderungen
watch(() => JSON.parse(JSON.stringify(models)), async (newModels, oldModels) => {
    // Speichere nur wenn sich etwas geändert hat
    if (JSON.stringify(newModels) !== JSON.stringify(oldModels)) {
        await saveAllModels();
    }
}, { deep: true });

const providers = computed(() => {
    // Nur Provider zeigen, bei denen mindestens 1 Modell aktiviert ist
    const enabled = modelOptions.value || {};
    return Object.keys(enabled).filter(p => (enabled[p] || []).length > 0);
});
const modelOptions = ref({});
const modelsLoading = ref(false);

// Dynamische Modelle von EdenAI laden (nur kuratierte, mit mindestens 1 aktivierten Modell)
async function loadModels() {
    modelsLoading.value = true;
    try {
        const res = await fetch('/api/edenai/models');
        const data = await res.json();
        if (data.models) {
            // Filter: nur Provider mit mindestens 1 aktivierten Modell
            const filtered = {};
            for (const [provider, models] of Object.entries(data.models)) {
                if (models && models.length > 0) {
                    filtered[provider] = models;
                }
            }
            modelOptions.value = filtered;
        }
    } catch (e) {
        // Fallback
        modelOptions.value = {
            openai: ['gpt-4o', 'gpt-4o-mini'],
            anthropic: ['claude-3-5-sonnet-20240620'],
        };
    } finally { modelsLoading.value = false; }
}
loadModels();

const saving = ref(false); const saved = ref(false);
const modelsSaving = ref(false); const modelsSaved = ref(false);
const testResult = ref(null); const testLoading = ref(false);
const testForm = reactive({ message: '', agent: '', strategy: 'viscale', persona_id: '' });

// Personas der gewählten Strategie für das Test-Dropdown
const personasForStrategy = computed(() => {
    const strategy = (props.strategies || []).find(s => s.key === testForm.strategy);
    if (!strategy) return [];
    return (props.personas || []).filter(p => p.strategy_id === strategy.id);
});

async function savePrompt(agent) {
    saving.value = true; saved.value = false;
    try {
        await fetch('/api/agents/config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ agent, system_prompt: prompts[agent] }),
        });
        saved.value = true; setTimeout(() => saved.value = false, 2000);
    } finally { saving.value = false; }
}
async function saveAllModels() {
    try {
        await fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ key: 'agent_models', value: JSON.parse(JSON.stringify(models)) }),
        });
    } catch (e) {
        console.error('Auto-Save failed:', e);
    }
}

async function saveModels(agent) {
    modelsSaving.value = true; modelsSaved.value = false;
    try {
        await saveAllModels();
        modelsSaved.value = true; setTimeout(() => modelsSaved.value = false, 2000);
    } finally { modelsSaving.value = false; }
}
async function testAgent() {
    testLoading.value = true; testResult.value = null;
    try {
        const response = await fetch('/api/agents/test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({
                agent: testForm.agent,
                message: testForm.message,
                strategy: testForm.strategy,
                persona_id: testForm.persona_id || null,
            }),
        });
        const data = await response.json();
        // Fehler vom Backend (EdenAI) als Fehlermeldung in der Ausgabe anzeigen
        if (data.error) {
            testResult.value = { ...data, output: '⚠️ ' + data.error };
        } else {
            testResult.value = data;
        }
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { testLoading.value = false; }
}

function openAgent(agent) { activeAgent.value = agent; testForm.agent = agent; detailTab.value = 'prompt'; }
function closeAgent() { activeAgent.value = null; testResult.value = null; }
function filteredLogs(agent) { return (props.agentLogs || []).filter(l => l.agent === agent).slice(0, 10); }
function logCount(agent) { return (props.agentLogs || []).filter(l => l.agent === agent).length; }

// Aufgeklappte Log-Einträge (Details nur per Klick)
const expandedLogs = ref(new Set());
function toggleLog(id) {
    const s = new Set(expandedLogs.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expandedLogs.value = s;
}

// ─── Workflow-Runner (in-browser) + Verlauf ─────────────────────────────
const wfInput = ref('');
const wfRunning = ref(false);
const wfResult = ref(null);      // aktueller/selektierter Lauf
const workflowRuns = ref([]);
const wfLoading = ref(false);

async function loadWorkflowRuns() {
    wfLoading.value = true;
    try {
        const res = await fetch('/api/workflow/runs');
        workflowRuns.value = await res.json();
    } catch (e) { /* silent */ }
    finally { wfLoading.value = false; }
}

async function runWorkflow() {
    if (!wfInput.value.trim() || wfRunning.value) return;
    wfRunning.value = true;
    wfResult.value = null;
    try {
        const res = await fetch('/api/workflow/run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ input: wfInput.value.trim() }),
        });
        const data = await res.json();
        if (data.run) {
            wfResult.value = data.run;
            await loadWorkflowRuns();
            pollWorkflow(data.run.id);
        }
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { wfRunning.value = false; }
}

async function pollWorkflow(id, attempts = 0) {
    if (attempts > 40) return; // max ~ 6-7 min
    setTimeout(async () => {
        try {
            const res = await fetch(`/api/workflow/runs/${id}`);
            const run = await res.json();
            wfResult.value = run;
            await loadWorkflowRuns();
            if (run.status === 'running' && attempts < 40) {
                pollWorkflow(id, attempts + 1);
            }
        } catch (e) { /* silent */ }
    }, 8000);
}

function showRun(run) { wfResult.value = run; }
function fmtTs(d) { return d ? new Date(d).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'; }
function runStatusClass(s) { return s === 'success' ? 'bg-green-50 text-green-700' : s === 'error' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700'; }

onMounted(() => { loadWorkflowRuns(); });
</script>

<template>
    <AppLayout>
        <div class="max-w-6xl mx-auto px-6 py-8">
            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-800 tracking-tight">Agents</h1>
                    <p class="text-sm text-gray-400 mt-1">Multi-Agent Content-Workflow</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs px-2 py-1 rounded-full" :class="hasEdenAI ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'">EdenAI {{ hasEdenAI ? '✓' : '✗' }}</span>
                </div>
            </div>

            <!-- LIST VIEW -->
            <div v-if="!activeAgent">
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
                    <div v-for="(info, key) in agents" :key="key" @click="openAgent(key)"
                        class="neu-card p-5 cursor-pointer hover:border-neu-border hover:shadow-sm transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-2xl">{{ info.icon }}</span>
                            <span class="w-2 h-2 rounded-full" :class="hasEdenAI ? 'bg-green-400' : 'bg-yellow-400'"></span>
                        </div>
                        <h3 class="text-sm font-medium text-gray-800">{{ info.name }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">{{ info.desc }}</p>
                        <div class="mt-3 flex items-center justify-between">
                            <span class="text-xs text-gray-400">{{ modelLabel(models[key].provider, models[key].model) }}</span>
                            <span class="text-xs text-gray-400">{{ logCount(key) }} logs</span>
                        </div>
                    </div>
                </div>

                <div class="neu-card p-5 mt-6">
                    <h3 class="text-sm font-medium text-gray-800 mb-3">▶ Workflow starten & Datenfluss-Debug</h3>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <!-- Input + Run -->
                        <div class="lg:col-span-1 space-y-3">
                            <p class="text-xs text-gray-500">Führt den gesamten Multi-Agent-Workflow aus und protokolliert jeden Schritt (Prompts, Tool-Calls, Ergebnisse).</p>
                            <textarea v-model="wfInput" rows="4"
                                class="w-full bg-neu  rounded-lg p-3 text-sm text-gray-800 focus:outline-none focus:border-gray-400"
                                placeholder="z. B. Recherchiere zum Thema CRM-Datenqualität und produziere LinkedIn-Posts"></textarea>
                            <button @click="runWorkflow" :disabled="wfRunning || !wfInput.trim()"
                                class="w-full px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50">
                                {{ wfRunning ? 'Starte…' : '▶ Workflow starten' }}
                            </button>
                            <p class="text-[11px] text-gray-400">Voraussetzung: <code class="bg-neu px-1 rounded">content-agent/.env</code> mit EDENAI_API_KEY + <code class="bg-neu px-1 rounded">pip install -r requirements.txt</code></p>
                        </div>

                        <!-- Console/Debug -->
                        <div class="lg:col-span-2">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Console / Trace</h4>
                                <span v-if="wfResult" class="text-xs px-2 py-1 rounded-full" :class="runStatusClass(wfResult.status)">{{ wfResult.status }}</span>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 h-64 overflow-y-auto font-mono text-xs text-gray-200 whitespace-pre-wrap">
                                <span v-if="!wfResult" class="text-gray-500">Noch kein Lauf. Gib oben eine Aufgabe ein und starte den Workflow.</span>
                                <span v-else-if="wfResult.status === 'running'" class="text-blue-300">⏳ Workflow läuft… (Ergebnis erscheint hier automatisch)</span>
                                <pre v-else class="whitespace-pre-wrap">{{ wfResult.output || wfResult.trace || '(Kein Output)' }}</pre>
                            </div>
                        </div>
                    </div>

                    <!-- History -->
                    <div class="mt-5 border-t border-neu-border pt-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Verlauf (automatische Historie)</h4>
                            <button @click="loadWorkflowRuns" class="text-xs text-gray-400 hover:text-green-600">↻ Aktualisieren</button>
                        </div>
                        <div v-if="workflowRuns.length === 0" class="text-xs text-gray-400 py-2">Noch keine Läufe.</div>
                        <div v-else class="space-y-1.5 max-h-48 overflow-y-auto">
                            <div v-for="run in workflowRuns" :key="run.id" @click="showRun(run)"
                                class="flex items-center gap-3 px-3 py-2 rounded-lg border border-transparent hover:border-gray-200 hover:bg-gray-50 cursor-pointer">
                                <span class="text-xs px-2 py-0.5 rounded-full shrink-0" :class="runStatusClass(run.status)">#{{ run.id }} · {{ run.status }}</span>
                                <span class="text-xs text-gray-700 truncate flex-1">{{ run.input || '(ohne Input)' }}</span>
                                <span class="text-xs text-gray-400 shrink-0">{{ fmtTs(run.created_at) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WORKFLOW & LOOPS -->
                <div class="neu-card p-5 mt-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex gap-1">
                            <button v-for="t in workflowTabs" :key="t" @click="workflowTab = t"
                                class="px-3 py-1.5 rounded-lg text-sm"
                                :class="workflowTab === t ? 'bg-neu text-gray-800 font-medium' : 'text-gray-400 hover:text-gray-800 hover:bg-neu'">
                                {{ t === 'loops' ? 'Loops' : 'Workflow' }}
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="loopsSaved" class="text-xs text-green-600">✓ Gespeichert</span>
                            <button @click="saveLoops" :disabled="loopsSaving"
                                class="px-3 py-1.5 bg-neu text-white rounded-lg text-xs hover:bg-neu disabled:opacity-50">
                                {{ loopsSaving ? 'Wird gespeichert…' : 'Speichern' }}
                            </button>
                        </div>
                    </div>

                    <!-- Phase-Übersicht (kompakt) -->
                    <div class="flex items-center gap-2 mb-4 flex-wrap">
                        <template v-for="(p, i) in workflowPhases" :key="p.agent">
                            <span class="text-xs px-2.5 py-1 rounded-full bg-neu text-gray-800">{{ i + 1 }} · {{ p.label }}</span>
                            <span v-if="i < workflowPhases.length - 1" class="text-gray-300 text-xs">→</span>
                        </template>
                        <span v-if="loops.length" class="text-xs text-gray-400 pl-1">+ {{ loops.length }} Loop{{ loops.length > 1 ? 's' : '' }}</span>
                    </div>

                    <!-- Loops verwalten -->
                    <div v-if="workflowTab === 'loops'">
                        <p class="text-xs text-gray-400 mb-3">
                            Ein Loop schickt den Workflow <strong>zurück</strong>, wenn eine Bedingung erfüllt ist
                            — z. B. prüft Review den Content und bei "fail" produziert Production erneut.
                        </p>
                        <div class="space-y-3">
                            <div v-for="(l, idx) in loops" :key="l.id" class="neu-card-sm p-4">
                                <div class="flex items-center gap-3">
                                    <span class="text-lg">↻</span>
                                    <input v-model="l.name" class="flex-1 bg-neu text-sm border-0 px-3 py-1.5 rounded-lg text-gray-800 focus:outline-none focus:border-gray-400"
                                        placeholder="Name (z. B. Qualitäts-Loop)" />
                                    <button @click="moveLoop(l.id, -1)" :disabled="idx === 0"
                                        class="text-gray-300 hover:text-gray-800 disabled:opacity-30 text-sm">↑</button>
                                    <button @click="moveLoop(l.id, 1)" :disabled="idx === loops.length - 1"
                                        class="text-gray-300 hover:text-gray-800 disabled:opacity-30 text-sm">↓</button>
                                    <button @click="removeLoop(l.id)" class="text-red-300 hover:text-red-600 text-sm px-1">✕</button>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Von (prüfender Agent)</label>
                                        <select v-model="l.from_agent" class="w-full bg-neu text-sm border-0 px-2 py-1.5 rounded-lg text-gray-800 focus:outline-none focus:border-gray-400">
                                            <option v-for="(info, key) in agents" :key="key" :value="key">{{ info.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Zurück zu</label>
                                        <select v-model="l.to_agent" class="w-full bg-neu text-sm border-0 px-2 py-1.5 rounded-lg text-gray-800 focus:outline-none focus:border-gray-400">
                                            <option v-for="(info, key) in agents" :key="key" :value="key" :disabled="key === l.from_agent">{{ info.name }}</option>
                                        </select>
                                    </div>
                                    <div class="col-span-2">
                                        <label class="block text-xs text-gray-400 mb-1">Bedingung (Trigger)</label>
                                        <input v-model="l.condition" class="w-full bg-neu text-sm border-0 px-3 py-1.5 rounded-lg text-gray-800 font-mono focus:outline-none focus:border-gray-400"
                                            placeholder='z. B. verdict = "fail"' />
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Max. Runden</label>
                                        <div class="flex items-center gap-2">
                                            <button @click="setLoopRounds(l, l.max_rounds - 1)" class="w-8 h-8 rounded-lg bg-neu text-gray-600 hover:bg-neu">−</button>
                                            <span class="w-8 text-center text-sm font-semibold text-gray-800">{{ l.max_rounds }}</span>
                                            <button @click="setLoopRounds(l, l.max_rounds + 1)" class="w-8 h-8 rounded-lg bg-neu text-gray-600 hover:bg-neu">+</button>
                                        </div>
                                    </div>
                                    <div class="col-span-3 sm:col-span-3">
                                        <p class="text-xs text-gray-400">
                                            {{ agentLabel(l.from_agent) }} prüft → wenn <code class="text-gray-600">{{ l.condition || '…' }}</code> → {{ agentLabel(l.to_agent) }} erneut. Max. <strong class="text-gray-800">{{ l.max_rounds }}</strong> Runde{{ l.max_rounds > 1 ? 'n' : '' }}, danach Abbruch.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <button @click="addLoop" class="w-full py-3 rounded-lg border-2 border-dashed border-gray-200 text-sm text-gray-400 hover:text-gray-800 hover:border-gray-300 transition-colors">
                                + Loop hinzufügen
                            </button>
                        </div>
                    </div>

                    <!-- Workflow-Vorschau -->
                    <div v-if="workflowTab === 'workflow'">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs text-gray-400">Generierte Workflow-Definition (lässt sich in den Coordinator-Prompt kopieren):</p>
                            <button @click="copyWorkflow" class="px-3 py-1.5 bg-neu text-white rounded-lg text-xs hover:bg-neu">
                                {{ workflowCopied ? '✓ Kopiert' : 'Kopieren' }}
                            </button>
                        </div>
                        <pre class="bg-neu text-gray-800 text-xs rounded-lg p-4 whitespace-pre-wrap leading-relaxed">{{ workflowText }}</pre>
                    </div>
                </div>
            </div>

            <!-- DETAIL VIEW -->
            <div v-else>
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <button @click="closeAgent" class="text-gray-400 hover:text-gray-800 text-lg leading-none">←</button>
                        <span class="text-3xl">{{ agents[activeAgent].icon }}</span>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800">{{ agents[activeAgent].name }} Agent</h2>
                            <p class="text-xs text-gray-400">{{ agents[activeAgent].desc }}</p>
                        </div>
                    </div>
                    <span class="text-xs text-gray-400  px-2 py-1 rounded-full">{{ modelLabel(models[activeAgent].provider, models[activeAgent].model) }}</span>
                </div>

                <div class="flex gap-1 mb-6">
                    <button v-for="tab in ['prompt', 'model', 'test', 'logs']" :key="tab" @click="detailTab = tab"
                        class="px-3 py-1.5 rounded-lg text-sm"
                        :class="detailTab === tab ? 'bg-neu text-gray-800 font-medium' : 'text-gray-400 hover:text-gray-800 hover:bg-neu'">
                        {{ tab === 'prompt' ? 'Prompt' : tab === 'model' ? 'Modell' : tab === 'test' ? 'Test' : 'Logs' }}
                    </button>
                </div>

                <!-- Prompt -->
                <div v-if="detailTab === 'prompt'" class="neu-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-medium text-gray-800">System Prompt</h4>
                        <button @click="savePrompt(activeAgent)" :disabled="saving" class="px-3 py-1.5 bg-neu text-white rounded-lg text-xs hover:bg-neu">Speichern</button>
                    </div>
                    <textarea v-model="prompts[activeAgent]" rows="18" class="w-full bg-neu  rounded-lg p-3 text-sm text-gray-800 font-mono focus:outline-none focus:border-gray-400" placeholder="System-Prompt..."></textarea>
                    <p class="text-xs text-gray-400 mt-2">Wird bei jedem Aufruf als System-Message verwendet.</p>
                    <span v-if="saved" class="text-xs text-green-600 ml-3">✓ Gespeichert</span>
                </div>

                <!-- Model -->
                <div v-if="detailTab === 'model'" class="neu-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-medium text-gray-800">Modell-Konfiguration</h4>
                        <button @click="saveModels(activeAgent)" :disabled="modelsSaving" class="px-3 py-1.5 bg-neu text-white rounded-lg text-xs hover:bg-neu">Speichern</button>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Provider</label>
                            <select v-model="models[activeAgent].provider" class="w-full bg-neu  rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400">
                                <option v-for="p in providers" :key="p" :value="p">{{ p }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Modell</label>
                            <select v-model="models[activeAgent].model" class="w-full bg-neu  rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400">
                                <option v-for="m in (modelOptions[models[activeAgent].provider] || modelOptions.value?.[models[activeAgent].provider] || [])" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Temperature (0–2)</label>
                            <input type="number" step="0.1" min="0" max="2" v-model.number="models[activeAgent].temperature"
                                class="w-full bg-neu rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400" placeholder="0.7">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Max Tokens</label>
                            <input type="number" step="100" min="100" max="64000" v-model.number="models[activeAgent].max_tokens"
                                class="w-full bg-neu rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400" placeholder="2000">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Reasoning Aufwand</label>
                            <select v-model="models[activeAgent].reasoning_effort" class="w-full bg-neu rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400">
                                <option value="none">Aus (Default des Modells)</option>
                                <option value="low">Niedrig — schnell & günstig</option>
                                <option value="medium">Mittel — ausgewogen</option>
                                <option value="high">Hoch — tiefgründig & teuer</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <p class="text-[11px] text-gray-400 leading-snug pb-1">
                                Steuert wie viel das Modell intern nachdenkt (Claude: <code>output_config.effort</code>, OpenAI o-Serie/gpt-5: <code>reasoning_effort</code>).
                                Niedrig = schneller &amp; günstiger; bei einfachen Schreib-Formaten reicht meist <em>mittel</em>.
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 p-3 neu-card-sm">
                        <p class="text-xs text-gray-400">Aktuell: <span class="text-gray-800 font-medium">{{ modelLabel(models[activeAgent].provider, models[activeAgent].model) }}</span></p>
                    </div>
                    <span v-if="modelsSaved" class="text-xs text-green-600 ml-3">✓ Gespeichert</span>
                </div>

                <!-- Test -->
                <div v-if="detailTab === 'test'" class="space-y-4">
                    <div class="neu-card p-5">
                        <h4 class="text-sm font-medium text-gray-800 mb-3">Agent testen</h4>
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Strategie</label>
                                <select v-model="testForm.strategy" @change="testForm.persona_id = ''" class="w-full bg-neu  rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400">
                                    <option v-for="s in (strategies || [])" :key="s.key" :value="s.key">{{ s.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Ziel-Persona</label>
                                <select v-model="testForm.persona_id" class="w-full bg-neu  rounded-lg p-2 text-sm text-gray-800 focus:outline-none focus:border-gray-400">
                                    <option value="">Alle Personas (Agent wählt)</option>
                                    <option v-for="p in personasForStrategy" :key="p.id" :value="p.id">{{ p.name }}{{ p.role ? ' · ' + p.role : '' }}</option>
                                </select>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mb-3">Der Agent erhält ICPs, Pain-Cluster, Tonalität und {{ testForm.persona_id ? 'nur die gewählte Persona' : 'alle aktiven Personas' }} als Kontext.</p>
                        <textarea v-model="testForm.message" rows="4" class="w-full bg-neu  rounded-lg p-3 text-sm text-gray-800 focus:outline-none focus:border-gray-400 mb-3" :placeholder="`Teste den ${agents[activeAgent].name} Agent...`"></textarea>
                        <button @click="testAgent" :disabled="testLoading || !testForm.message" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            {{ testLoading ? 'Test läuft...' : '🧪 Testen' }}
                        </button>
                    </div>
                    <div v-if="testResult" class="neu-card p-5">
                        <h4 class="text-sm font-medium text-gray-800 mb-3">Ergebnis</h4>
                        <div class="space-y-3 text-sm">
                            <div class="grid grid-cols-2 gap-4 text-xs">
                                <div><span class="text-gray-400">Modell:</span> <span class="text-gray-800">{{ modelLabel(testResult.provider, testResult.model) }}</span></div>
                                <div><span class="text-gray-400">Agent:</span> <span class="text-gray-800">{{ agents[testResult.agent]?.name }}</span></div>
                            </div>
                            <div><p class="text-xs text-gray-400 mb-1">Eingabe:</p><div class="neu-card-sm p-3 text-gray-400">{{ testResult.input }}</div></div>
                            <div><p class="text-xs text-gray-400 mb-1">Ausgabe:</p><div class="neu-card-sm p-3 text-gray-800 whitespace-pre-wrap max-h-64 overflow-y-auto">{{ testResult.output }}</div></div>
                        </div>
                    </div>
                </div>

                <!-- Logs -->
                <div v-if="detailTab === 'logs'" class="neu-card p-5">
                    <h4 class="text-sm font-medium text-gray-800 mb-4">Agent-Logs ({{ filteredLogs(activeAgent).length }})</h4>
                    <div class="divide-y divide-gray-100">
                        <div v-for="log in filteredLogs(activeAgent)" :key="log.id">
                            <!-- Kompakte Zeile -->
                            <button @click="toggleLog(log.id)" class="w-full flex items-center gap-3 py-2 text-left hover:bg-gray-50 rounded px-2 -mx-2 transition-colors">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="log.status === 'success' ? 'bg-green-500' : 'bg-red-500'"></span>
                                <span class="text-xs text-gray-400 shrink-0 w-28">{{ new Date(log.created_at).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) }}</span>
                                <span class="text-xs text-gray-800 truncate flex-1">{{ log.input }}</span>
                                <span class="text-xs text-gray-400 shrink-0 hidden sm:inline">{{ log.model }}</span>
                                <span class="text-gray-300 text-xs shrink-0 transition-transform" :class="expandedLogs.has(log.id) ? 'rotate-90' : ''">▸</span>
                            </button>
                            <!-- Details (nur per Klick) -->
                            <div v-if="expandedLogs.has(log.id)" class="ml-4 mr-2 mb-3 mt-1 space-y-2 border-l-2 border-gray-100 pl-3">
                                <div>
                                    <p class="text-xs text-gray-400 mb-0.5">Input</p>
                                    <p class="text-xs text-gray-800 whitespace-pre-wrap">{{ log.input }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-400 mb-0.5">Output</p>
                                    <p class="text-xs text-gray-800 whitespace-pre-wrap max-h-64 overflow-y-auto">{{ log.output }}</p>
                                </div>
                                <p class="text-xs text-gray-400">{{ modelLabel(log.provider, log.model) }} · {{ log.status }}</p>
                            </div>
                        </div>
                        <p v-if="filteredLogs(activeAgent).length === 0" class="text-xs text-gray-400 italic py-2">Noch keine Logs.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>