<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ settings: Object, strategies: Object, agentPrompts: Object, agentModels: Object, stats: Object, agentLogs: Array, personas: Array });

const activeAgent = ref(null);
const detailTab = ref('prompt');

const llmKeys = computed(() => props.settings?.llm_keys || {});
const hasEdenAI = computed(() => !!llmKeys.value?.edenai_key);
const hasSerperDev = computed(() => !!llmKeys.value?.serperdev_key);

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
    research: { provider: props.agentModels?.research?.provider || 'openai', model: props.agentModels?.research?.model || 'gpt-4o' },
    angle: { provider: props.agentModels?.angle?.provider || 'openai', model: props.agentModels?.angle?.model || 'gpt-4o' },
    production: { provider: props.agentModels?.production?.provider || 'anthropic', model: props.agentModels?.production?.model || 'claude-3-5-sonnet-20240620' },
    review: { provider: props.agentModels?.review?.provider || 'openai', model: props.agentModels?.review?.model || 'gpt-4o' },
    coordinator: { provider: props.agentModels?.coordinator?.provider || 'openai', model: props.agentModels?.coordinator?.model || 'gpt-4o' },
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
                    <span class="text-xs px-2 py-1 rounded-full" :class="hasSerperDev ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'">SerperDev {{ hasSerperDev ? '✓' : '✗' }}</span>
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
                            <span class="text-xs text-gray-400">{{ models[key].provider }}/{{ models[key].model }}</span>
                            <span class="text-xs text-gray-400">{{ logCount(key) }} logs</span>
                        </div>
                    </div>
                </div>

                <div class="neu-card p-5 mt-6">
                    <h3 class="text-sm font-medium text-gray-800 mb-2">Workflow starten</h3>
                    <code class="text-xs text-gray-400 bg-neu px-3 py-2 rounded-md block">cd content-agent && python3 main.py</code>
                    <p class="text-xs text-gray-400 mt-2">Ergebnisse: <a href="/quellen" class="text-gray-400 hover:text-gray-800">Quellen</a> · <a href="/angles" class="text-gray-400 hover:text-gray-800">Angles</a> · <a href="/redaktionsplan" class="text-gray-400 hover:text-gray-800">Redaktionsplan</a></p>
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
                    <span class="text-xs text-gray-400  px-2 py-1 rounded-full">{{ models[activeAgent].provider }}/{{ models[activeAgent].model }}</span>
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
                    <div class="mt-4 p-3 neu-card-sm">
                        <p class="text-xs text-gray-400">Aktuell: <span class="text-gray-800 font-medium">{{ models[activeAgent].provider }}/{{ models[activeAgent].model }}</span></p>
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
                                <div><span class="text-gray-400">Modell:</span> <span class="text-gray-800">{{ testResult.provider }}/{{ testResult.model }}</span></div>
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
                                <p class="text-xs text-gray-400">{{ log.provider }}/{{ log.model }} · {{ log.status }}</p>
                            </div>
                        </div>
                        <p v-if="filteredLogs(activeAgent).length === 0" class="text-xs text-gray-400 italic py-2">Noch keine Logs.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>