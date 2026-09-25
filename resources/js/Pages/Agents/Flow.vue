<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    strategies: Array,
    personas: Array,
    recentRuns: Array,
    agentModels: Object,
    agentPrompts: Object,
    defaultPrompts: Object,
    workflowLoops: Array,
});

const page = usePage();

// ─── Top-Level View Tab: 'pipeline' | 'inspector' ──────────────────────────
const pageTab = ref('pipeline');

// ─── Pipeline phases definition ────────────────────────────────────────────
const phases = [
    {
        key: 'research',
        icon: '🔍',
        label: 'Research',
        desc: 'Web-Recherche, Quellen analysieren, erste Angles ableiten.',
        tools: ['web_search', 'scrape_page', 'create_source', 'create_angle'],
    },
    {
        key: 'angle',
        icon: '🎯',
        label: 'Angle Ranking',
        desc: 'Thesen bewerten (ICP-Fit, Schärfe, Timing) und ranken.',
        tools: ['list_angles', 'update_angle', 'get_batch_ranking'],
    },
    {
        key: 'production',
        icon: '✍️',
        label: 'Production',
        desc: 'Content aus Top-Angles mit Persona + Brand Voice verfassen.',
        tools: ['produce_content', 'get_strategy_context'],
    },
    {
        key: 'review',
        icon: '✅',
        label: 'Review',
        desc: 'Brand Voice & Qualitätsregeln prüfen → Verdict: pass / fail.',
        tools: ['list_content', 'update_content', 'revise_content'],
    },
];

// ─── System Prompts State (Editable directly in Flow) ──────────────────────
const prompts = reactive({
    research: props.agentPrompts?.research || props.defaultPrompts?.research || '',
    angle: props.agentPrompts?.angle || props.defaultPrompts?.angle || '',
    production: props.agentPrompts?.production || props.defaultPrompts?.production || '',
    review: props.agentPrompts?.review || props.defaultPrompts?.review || '',
});
const savingPrompt = reactive({});
const savedPrompt = reactive({});

async function savePrompt(phaseKey) {
    savingPrompt[phaseKey] = true;
    savedPrompt[phaseKey] = false;
    try {
        const res = await fetch('/api/agents/config', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                agent: phaseKey,
                system_prompt: prompts[phaseKey],
            }),
        });
        if (res.ok) {
            savedPrompt[phaseKey] = true;
            setTimeout(() => { savedPrompt[phaseKey] = false; }, 2000);
        }
    } catch (e) {
        alert('Fehler beim Speichern des Prompts: ' + e.message);
    } finally {
        savingPrompt[phaseKey] = false;
    }
}

function resetPromptToDefault(phaseKey) {
    prompts[phaseKey] = props.defaultPrompts?.[phaseKey] || '';
    savePrompt(phaseKey);
}

// ─── Workflow test state ────────────────────────────────────────────────────
const selectedStrategy = ref(page.props.activeCampaign || props.strategies?.[0]?.key || 'viscale');
const selectedPersona = ref(props.personas?.[0]?.id || null);
const selectedPhases = ref(['research', 'angle']);
const testPrompt = ref('');
const isRunning = ref(false);
const runs = ref([...(props.recentRuns || [])]);
const activeRun = ref(null);
const pollTimer = ref(null);

function toggleSelectedPhase(key) {
    if (selectedPhases.value.includes(key)) {
        if (selectedPhases.value.length > 1) {
            selectedPhases.value = selectedPhases.value.filter(k => k !== key);
        }
    } else {
        selectedPhases.value.push(key);
    }
}

function setAngleOnlyMode() {
    selectedPhases.value = ['research', 'angle'];
    openPhases.research = true;
    openPhases.angle = true;
    openPhases.production = false;
    openPhases.review = false;
}

function setAllPhasesMode() {
    selectedPhases.value = ['research', 'angle', 'production', 'review'];
    openPhases.research = true;
    openPhases.angle = true;
    openPhases.production = true;
    openPhases.review = true;
}

// Collapsible states
const openPhases = reactive({
    research: true,
    angle: true,
    production: false,
    review: false,
});

function togglePhase(key) {
    openPhases[key] = !openPhases[key];
}

const anglesCollapsed = ref(false);
const activePhaseSubTab = reactive({
    research: 'output',
    angle: 'output',
    production: 'output',
    review: 'output',
});

const selectedToolFilter = reactive({
    research: 'all',
    angle: 'all',
    production: 'all',
    review: 'all',
});

function toolSummary(tools) {
    const counts = {};
    for (const t of tools) {
        const name = t.details?.tool || 'tool';
        counts[name] = (counts[name] || 0) + 1;
    }
    return counts;
}

function toolParamSummary(call) {
    const input = call.details?.input || {};
    if (input.query) return `query: "${input.query}"`;
    if (input.url) return input.url;
    if (input.title) return `"${input.title}"`;
    if (input.angle) return `"${input.angle}"`;
    if (input.angle_id) return `${input.angle_id}${input.ranking_score ? ' (Score: ' + input.ranking_score + '/12)' : ''}`;
    if (input.format) return `${input.format} (${input.angle_id || ''})`;
    if (input.persona) return `persona: ${input.persona}`;
    const keys = Object.keys(input);
    if (!keys.length) return '';
    return keys.slice(0, 2).map(k => `${k}: ${typeof input[k] === 'string' ? input[k] : JSON.stringify(input[k])}`).join(' · ');
}

// Inspector states
const inspectorFilter = ref('all');
const expandedDetails = reactive({});
const approvingId = ref(null);
const isApprovingAll = ref(false);

function toggleDetail(id) {
    expandedDetails[id] = !expandedDetails[id];
}

// ─── Trace parsing ──────────────────────────────────────────────────────────
function parseTrace(raw) {
    if (!raw) return null;
    try { return typeof raw === 'string' ? JSON.parse(raw) : raw; } catch { return null; }
}

const activeTrace = computed(() => parseTrace(activeRun.value?.trace));
const proposedAngles = computed(() => activeTrace.value?.proposed_angles || []);
const loopsList = computed(() => activeTrace.value?.loops || []);
const timelineEvents = computed(() => activeTrace.value?.timeline || []);
const toolCalls = computed(() => (timelineEvents.value || []).filter(e => e.type === 'tool_call'));

const filteredInspectorEvents = computed(() => {
    const list = timelineEvents.value || [];
    if (inspectorFilter.value === 'all') return list;
    if (inspectorFilter.value === 'communication') return list.filter(e => e.type === 'communication');
    if (inspectorFilter.value === 'tool_call') return list.filter(e => e.type === 'tool_call');
    if (inspectorFilter.value === 'loop') return list.filter(e => e.type === 'loop');
    if (inspectorFilter.value === 'llm') return list.filter(e => e.type === 'llm_input' || e.type === 'llm_output');
    return list;
});

// Laufende Phase ermitteln
const runningPhase = computed(() => {
    if (!activeRun.value || activeRun.value.status !== 'running') return null;
    const trace = activeTrace.value;
    if (!trace?.steps?.length) return 'research';
    const agents = trace.steps.map(s => s.agent).filter(Boolean);
    return agents[agents.length - 1] || 'research';
});

// ─── Extract Phase-Specific Data ───────────────────────────────────────────
function getPhaseData(phaseKey) {
    if (!activeRun.value) return { status: 'idle', tools: [], input: null, output: null, verdict: null };
    const trace = activeTrace.value;
    const timeline = trace?.timeline || [];
    const steps = trace?.steps || [];

    const tools = timeline.filter(e => e.type === 'tool_call' && (e.agent === phaseKey || e.details?.agent === phaseKey));
    const inputEvt = timeline.find(e => e.type === 'llm_input' && e.agent === phaseKey);
    const outputEvt = timeline.filter(e => e.type === 'llm_output' && e.agent === phaseKey).pop();

    let status = 'idle';
    if (activeRun.value.status === 'running' && runningPhase.value === phaseKey) {
        status = 'running';
    } else if (outputEvt || steps.some(s => s.agent === phaseKey && s.status === 'done')) {
        status = 'done';
    } else if (activeRun.value.status === 'error' && runningPhase.value === phaseKey) {
        status = 'error';
    }

    return {
        status,
        tools,
        input: inputEvt?.details?.user_prompt || inputEvt?.details?.prompt || null,
        systemPrompt: inputEvt?.details?.system_prompt || null,
        modelInfo: inputEvt?.details?.model || null,
        inputDetails: inputEvt?.details || null,
        output: outputEvt?.details?.output || null,
        verdict: outputEvt?.details?.verdict || null,
        round: outputEvt?.details?.round || null,
    };
}

function hasExtraInputData(details) {
    if (!details) return false;
    const { model, user_prompt, system_prompt, prompt, ...rest } = details;
    return Object.keys(rest).length > 0;
}

function formatExtraInputData(details) {
    if (!details) return '';
    const { model, user_prompt, system_prompt, prompt, ...rest } = details;
    return JSON.stringify(rest, null, 2);
}

const copiedNotice = ref('');
function copyText(text, label = 'Kopiert!') {
    if (!text) return;
    navigator.clipboard?.writeText(text);
    copiedNotice.value = label;
    setTimeout(() => { copiedNotice.value = ''; }, 2000);
}

// ─── Start & Poll Workflow ─────────────────────────────────────────────────
async function startRun() {
    if (!testPrompt.value.trim() || isRunning.value) return;
    isRunning.value = true;

    // Sofortige UI-Reaktion (Optimistisch)
    activeRun.value = {
        id: 'neu…',
        input: testPrompt.value.trim(),
        status: 'running',
        created_at: new Date().toISOString(),
        trace: JSON.stringify({
            timeline: [
                {
                    id: 'init',
                    type: 'llm_input',
                    agent: 'research',
                    title: 'Starte Pipeline: Initialisiere Neuron AI Agents…',
                    details: { prompt: testPrompt.value.trim() },
                    timestamp: new Date().toISOString(),
                }
            ],
            steps: [{ agent: 'research', status: 'running', message: 'Initialisiere…' }]
        }),
    };

    // Automatisch ausgewählte Phasen öffnen
    openPhases.research = selectedPhases.value.includes('research');
    openPhases.angle = selectedPhases.value.includes('angle');
    openPhases.production = selectedPhases.value.includes('production');
    openPhases.review = selectedPhases.value.includes('review');

    try {
        const res = await fetch('/api/workflow/run', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                input: testPrompt.value.trim(),
                strategy: selectedStrategy.value,
                phases: selectedPhases.value,
                persona_id: selectedPersona.value,
                test_mode: true,
            }),
        });

        if (!res.ok) {
            const errData = await res.json().catch(() => ({}));
            throw new Error(errData.message || `Server-Fehler (${res.status})`);
        }

        const data = await res.json();
        if (data.run) {
            activeRun.value = data.run;
            const existingIdx = runs.value.findIndex(r => r.id === data.run.id);
            if (existingIdx >= 0) {
                runs.value[existingIdx] = data.run;
            } else {
                runs.value.unshift(data.run);
            }
            startPolling(data.run.id);
        } else {
            throw new Error(data.message || 'Workflow konnte nicht gestartet werden.');
        }
    } catch (e) {
        alert('Fehler beim Starten: ' + e.message);
        isRunning.value = false;
        if (activeRun.value?.id === 'neu…') {
            activeRun.value = null;
        }
    }
}

async function cancelCurrentRun() {
    if (!activeRun.value?.id || activeRun.value.id === 'neu…') {
        stopPolling();
        isRunning.value = false;
        activeRun.value = null;
        return;
    }

    try {
        const res = await fetch(`/api/workflow/runs/${activeRun.value.id}/cancel`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });
        const data = await res.json();
        if (data.run) {
            activeRun.value = data.run;
            const idx = runs.value.findIndex(r => r.id === data.run.id);
            if (idx >= 0) runs.value[idx] = data.run;
        }
    } catch (e) {
        console.error('Fehler beim Abbrechen:', e);
    } finally {
        stopPolling();
        isRunning.value = false;
    }
}

function stopPolling() {
    if (pollTimer.value) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

function startPolling(id) {
    stopPolling();
    let attempts = 0;
    pollTimer.value = setInterval(async () => {
        attempts++;
        try {
            const res = await fetch(`/api/workflow/runs/${id}`);
            if (!res.ok) {
                if (attempts > 5) {
                    stopPolling();
                    isRunning.value = false;
                }
                return;
            }
            const run = await res.json();
            activeRun.value = run;
            const idx = runs.value.findIndex(r => r.id === run.id);
            if (idx >= 0) runs.value[idx] = run;

            // Stop condition: Run ist fertig, abgebrochen, fehlerhaft oder Timeout (90s = 60 attempts)
            if (run.status === 'success' || run.status === 'error' || run.status === 'cancelled' || attempts > 60) {
                stopPolling();
                isRunning.value = false;
            }
        } catch {
            if (attempts > 10) {
                stopPolling();
                isRunning.value = false;
            }
        }
    }, 1500);
}

async function selectRun(run) {
    const res = await fetch(`/api/workflow/runs/${run.id}`);
    const data = await res.json();
    activeRun.value = data;
    if (data.status === 'running') {
        isRunning.value = true;
        startPolling(data.id);
    }
}

// ─── Flow Reset ────────────────────────────────────────────────────────────
function resetFlow() {
    if (pollTimer.value) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
    activeRun.value = null;
    isRunning.value = false;
    testPrompt.value = '';
    openPhases.research = true;
    openPhases.angle = true;
    openPhases.production = true;
    openPhases.review = true;
}

// ─── Angle Approval ────────────────────────────────────────────────────────
async function approveProposedAngle(proposedId) {
    if (!activeRun.value || approvingId.value) return;
    approvingId.value = proposedId;

    try {
        const res = await fetch('/api/workflow/approve-angle', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                run_id: activeRun.value.id,
                proposed_id: proposedId,
            }),
        });
        const data = await res.json();
        if (data.run) {
            activeRun.value = data.run;
            const idx = runs.value.findIndex(r => r.id === data.run.id);
            if (idx >= 0) runs.value[idx] = data.run;
        }
    } catch (e) {
        alert('Fehler beim Freigeben: ' + e.message);
    } finally {
        approvingId.value = null;
    }
}

async function approveAllProposedAngles() {
    if (!activeRun.value || isApprovingAll.value) return;
    isApprovingAll.value = true;

    try {
        const res = await fetch('/api/workflow/approve-all-angles', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                run_id: activeRun.value.id,
            }),
        });
        const data = await res.json();
        if (data.run) {
            activeRun.value = data.run;
            const idx = runs.value.findIndex(r => r.id === data.run.id);
            if (idx >= 0) runs.value[idx] = data.run;
        }
    } catch (e) {
        alert('Fehler beim Freigeben aller Angles: ' + e.message);
    } finally {
        isApprovingAll.value = false;
    }
}

onMounted(async () => {
    if (props.recentRuns?.length) {
        runs.value = [...props.recentRuns];
        if (runs.value[0]) {
            selectRun(runs.value[0]);
        }
    }
});
onUnmounted(() => { if (pollTimer.value) clearInterval(pollTimer.value); });

function modelLabel(key) {
    const m = props.agentModels?.[key];
    if (!m) return '—';
    const model = (m.model || '').replace(new RegExp('^' + (m.provider || '') + '/'), '');
    return model || m.provider || '—';
}

function runStatusBadge(run) {
    if (!run) return '';
    return {
        running: 'bg-gray-100 text-gray-900 border border-gray-300 font-mono',
        success: 'bg-gray-50 text-emerald-800 border border-emerald-300 font-mono',
        error: 'bg-gray-50 text-red-700 border border-red-300 font-mono',
    }[run.status] || 'bg-gray-100 text-gray-500 font-mono';
}

function formatTime(ts) {
    if (!ts) return '';
    try { return new Date(ts).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }); } catch { return ts; }
}
</script>

<template>
    <AppLayout>
        <!-- Minimal Top Header -->
        <div class="mb-5 flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-gray-200 pb-4">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-lg font-bold text-gray-900">Agent Flow</h1>
                    <span class="text-[11px] font-mono text-gray-500 bg-gray-100 border border-gray-200 px-2 py-0.5 rounded">
                        Neuron AI
                    </span>
                    <span v-if="activeRun" class="text-xs px-2 py-0.5 rounded" :class="runStatusBadge(activeRun)">
                        #{{ activeRun.id }} · {{ activeRun.status }}
                    </span>
                    <button
                        v-if="activeRun"
                        @click="resetFlow"
                        class="text-[11px] text-gray-500 hover:text-gray-900 bg-white border border-gray-200 hover:border-gray-300 px-2 py-0.5 rounded transition-colors cursor-pointer"
                        title="Deselektieren und Flow zurücksetzen"
                    >
                        ↺ Reset
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-1">Multi-Agenten Pipeline steuern, Prompts konfigurieren und Angles prüfen</p>
            </div>

            <!-- View Tab Switcher -->
            <div class="flex items-center gap-2">
                <div class="flex items-center bg-gray-100 p-0.5 rounded-md border border-gray-200 text-xs">
                    <button
                        @click="pageTab = 'pipeline'"
                        class="px-3 py-1 rounded transition-all cursor-pointer font-medium"
                        :class="pageTab === 'pipeline' ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-500 hover:text-gray-900'"
                    >
                        Pipeline & Phasen
                    </button>
                    <button
                        @click="pageTab = 'inspector'"
                        class="px-3 py-1 rounded transition-all cursor-pointer font-medium flex items-center gap-1.5"
                        :class="pageTab === 'inspector' ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-500 hover:text-gray-900'"
                    >
                        <span>Deep Inspector</span>
                        <span v-if="timelineEvents.length" class="text-[10px] font-mono bg-gray-200 text-gray-700 px-1.5 rounded">
                            {{ timelineEvents.length }}
                        </span>
                    </button>
                </div>
                <a href="/agents" class="text-xs text-gray-600 hover:text-gray-900 border border-gray-200 bg-white px-2.5 py-1 rounded transition-colors">
                    ← Agents
                </a>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════════ -->
        <!-- TAB 1: PIPELINE & PHASEN                                            -->
        <!-- ════════════════════════════════════════════════════════════════════ -->
        <div v-show="pageTab === 'pipeline'" class="grid grid-cols-1 xl:grid-cols-3 gap-5">

            <!-- Left: Test Bar + 4 Phase Cards + Staged Angles below Review -->
            <div class="xl:col-span-2 space-y-3.5">

                <!-- Minimal Test Bar -->
                <div class="bg-white border border-gray-200 rounded-lg p-3.5">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2.5">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-gray-900">Workflow ausführen</span>
                            <span class="text-[10px] font-mono text-gray-500 bg-gray-50 border border-gray-200 px-1.5 py-0.5 rounded">
                                Test-Modus: Kein DB-Auto-Save
                            </span>
                        </div>
                        <div class="flex items-center gap-3 text-xs">
                            <div class="flex items-center gap-1.5">
                                <span class="text-gray-400">Strategie:</span>
                                <select v-model="selectedStrategy" class="bg-white border border-gray-200 rounded px-2 py-0.5 text-xs text-gray-800 focus:outline-none focus:border-gray-400">
                                    <option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option>
                                </select>
                            </div>
                            <div v-if="personas && personas.length" class="flex items-center gap-1.5">
                                <span class="text-gray-400">Persona:</span>
                                <select v-model="selectedPersona" class="bg-white border border-gray-200 rounded px-2 py-0.5 text-xs text-gray-800 focus:outline-none focus:border-gray-400">
                                    <option :value="null">Alle / Auto</option>
                                    <option v-for="p in personas" :key="p.id" :value="p.id">{{ p.name }} ({{ p.role }})</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <input
                            v-model="testPrompt"
                            type="text"
                            class="flex-1 bg-gray-50 border border-gray-200 rounded px-3 py-1.5 text-xs text-gray-900 focus:outline-none focus:border-gray-400 placeholder-gray-400"
                            placeholder="Thema oder Leitfrage eingeben, z.B. 'Was kostet ein KI-Arbeitsplatz in KMUs wirklich?'"
                            @keyup.enter="startRun"
                        />
                        <button
                            @click="startRun"
                            :disabled="!testPrompt.trim() || isRunning"
                            class="px-3.5 py-1.5 bg-gray-900 hover:bg-black disabled:bg-gray-200 disabled:text-gray-400 text-white font-medium text-xs rounded transition-colors cursor-pointer disabled:cursor-not-allowed flex items-center gap-1.5 shrink-0"
                        >
                            <span v-if="isRunning" class="animate-spin text-xs">⟳</span>
                            <span>{{ isRunning ? 'Läuft…' : 'Starten' }}</span>
                        </button>
                        <button
                            v-if="isRunning"
                            @click="cancelCurrentRun"
                            type="button"
                            class="px-2.5 py-1.5 bg-red-600 hover:bg-red-700 text-white font-medium text-xs rounded transition-colors cursor-pointer flex items-center gap-1 shrink-0"
                            title="Laufenden Workflow sofort stoppen"
                        >
                            <span>🛑</span>
                            <span>Stoppen</span>
                        </button>
                    </div>

                    <!-- Phase Selection Bar -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mt-2.5 pt-2.5 border-t border-gray-100 text-xs">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="text-gray-400 text-[11px] font-medium">Aktive Phasen:</span>
                            <button
                                v-for="p in phases"
                                :key="p.key"
                                type="button"
                                @click="toggleSelectedPhase(p.key)"
                                class="px-2 py-0.5 rounded text-[11px] font-medium border transition-colors cursor-pointer flex items-center gap-1"
                                :class="selectedPhases.includes(p.key)
                                    ? 'bg-gray-900 text-white border-gray-900'
                                    : 'bg-white text-gray-400 border-gray-200 hover:border-gray-300 hover:text-gray-600'"
                            >
                                <span>{{ p.icon }}</span>
                                <span>{{ p.label }}</span>
                                <span v-if="selectedPhases.includes(p.key)" class="text-[10px] text-gray-300">✓</span>
                            </button>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0 text-[11px]">
                            <button
                                type="button"
                                @click="setAngleOnlyMode"
                                class="text-gray-600 hover:text-gray-900 font-medium px-2 py-0.5 rounded border transition-colors cursor-pointer"
                                :class="selectedPhases.length === 2 && selectedPhases.includes('research') && selectedPhases.includes('angle') ? 'bg-gray-100 border-gray-300 text-gray-900 font-semibold' : 'border-transparent hover:bg-gray-50'"
                            >
                                🎯 Nur Angles (ohne Production)
                            </button>
                            <span class="text-gray-200">|</span>
                            <button
                                type="button"
                                @click="setAllPhasesMode"
                                class="text-gray-600 hover:text-gray-900 font-medium px-2 py-0.5 rounded border transition-colors cursor-pointer"
                                :class="selectedPhases.length === 4 ? 'bg-gray-100 border-gray-300 text-gray-900 font-semibold' : 'border-transparent hover:bg-gray-50'"
                            >
                                🚀 Alle 4
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ════ THE 4 PHASE CARDS (MINIMAL, CLEAN BORDERS) ═══════════ -->
                <div class="space-y-2.5">
                    <div
                        v-for="phase in phases"
                        :key="phase.key"
                        class="border rounded-lg transition-all"
                        :class="[
                            !selectedPhases.includes(phase.key) ? 'bg-gray-50/60 opacity-60 border-dashed border-gray-200' : 'bg-white border-gray-200',
                            getPhaseData(phase.key).status === 'running' ? '!border-gray-400 !opacity-100' : ''
                        ]"
                    >
                        <!-- Phase Header -->
                        <div
                            class="px-4 py-2.5 flex items-center justify-between cursor-pointer select-none hover:bg-gray-50 transition-colors"
                            @click="togglePhase(phase.key)"
                        >
                            <div class="flex items-center gap-2.5">
                                <span class="text-sm">{{ phase.icon }}</span>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold" :class="selectedPhases.includes(phase.key) ? 'text-gray-900' : 'text-gray-500'">{{ phase.label }}</span>
                                    <span class="text-[10px] font-mono text-gray-500 bg-gray-100 border border-gray-200 px-1.5 py-0.5 rounded">
                                        {{ modelLabel(phase.key) }}
                                    </span>
                                    <span v-if="!selectedPhases.includes(phase.key)" class="text-[10px] font-mono text-gray-400 bg-gray-100 px-1.5 py-0.2 rounded">
                                        übersprungen
                                    </span>
                                    <span v-else-if="getPhaseData(phase.key).status === 'running'" class="text-[10px] font-medium text-blue-600 bg-blue-50 border border-blue-200 px-1.5 py-0.2 rounded animate-pulse">
                                        ⟳ läuft
                                    </span>
                                    <span v-else-if="getPhaseData(phase.key).status === 'done'" class="text-[10px] font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.2 rounded">
                                        ✓ fertig
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2.5">
                                <span v-if="getPhaseData(phase.key).tools.length" class="text-[10px] font-mono text-gray-500 bg-gray-50 border border-gray-200 px-1.5 py-0.2 rounded">
                                    {{ getPhaseData(phase.key).tools.length }} Tools
                                </span>
                                <span class="text-[11px] text-gray-400 font-mono">{{ openPhases[phase.key] ? '▲' : '▼' }}</span>
                            </div>
                        </div>

                        <!-- Phase Body (Expanded) -->
                        <div v-if="openPhases[phase.key]" class="px-4 py-3 border-t border-gray-100 bg-gray-50/40 space-y-2.5">

                            <!-- Sub-tabs within Phase -->
                            <div class="flex items-center justify-between border-b border-gray-200/80 pb-1.5 text-xs">
                                <div class="flex items-center gap-1">
                                    <button
                                        @click="activePhaseSubTab[phase.key] = 'output'"
                                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer"
                                        :class="activePhaseSubTab[phase.key] === 'output' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900 bg-white border border-gray-200'"
                                    >
                                        Output
                                    </button>
                                    <button
                                        @click="activePhaseSubTab[phase.key] = 'prompt_config'"
                                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer"
                                        :class="activePhaseSubTab[phase.key] === 'prompt_config' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900 bg-white border border-gray-200'"
                                    >
                                        ⚙️ System-Prompt
                                    </button>
                                    <button
                                        @click="activePhaseSubTab[phase.key] = 'tools'"
                                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer flex items-center gap-1"
                                        :class="activePhaseSubTab[phase.key] === 'tools' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900 bg-white border border-gray-200'"
                                    >
                                        <span>Tools</span>
                                        <span v-if="getPhaseData(phase.key).tools.length" class="text-[9px] font-mono px-1 rounded"
                                            :class="activePhaseSubTab[phase.key] === 'tools' ? 'bg-gray-700 text-white' : 'bg-gray-100 text-gray-700'">
                                            {{ getPhaseData(phase.key).tools.length }}
                                        </span>
                                    </button>
                                    <button
                                        @click="activePhaseSubTab[phase.key] = 'run_input'"
                                        class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer flex items-center gap-1"
                                        :class="activePhaseSubTab[phase.key] === 'run_input' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900 bg-white border border-gray-200'"
                                    >
                                        <span>Full Prompt & Data</span>
                                        <span v-if="getPhaseData(phase.key).systemPrompt" class="text-[9px] font-mono opacity-80">
                                            ({{ Math.round(getPhaseData(phase.key).systemPrompt.length / 1000) }}k)
                                        </span>
                                    </button>
                                </div>

                                <div v-if="phase.key === 'review' && getPhaseData('review').verdict">
                                    <span class="text-[10px] uppercase font-mono font-bold px-1.5 py-0.5 rounded border"
                                        :class="getPhaseData('review').verdict === 'pass' ? 'bg-emerald-50 text-emerald-800 border-emerald-300' : 'bg-red-50 text-red-800 border-red-300'">
                                        Verdict: {{ getPhaseData('review').verdict }}
                                    </span>
                                </div>
                            </div>

                            <!-- SUB-TAB 1: Concrete Output -->
                            <div v-if="activePhaseSubTab[phase.key] === 'output'">
                                <div v-if="getPhaseData(phase.key).output" class="bg-white border border-gray-200 rounded p-3 text-xs text-gray-800 max-h-64 overflow-y-auto whitespace-pre-wrap leading-relaxed font-sans">
                                    {{ getPhaseData(phase.key).output }}
                                </div>
                                <div v-else-if="getPhaseData(phase.key).status === 'running'" class="py-5 text-center text-xs text-gray-500 animate-pulse font-mono">
                                    ⟳ Agent führt Phase aus…
                                </div>
                                <div v-else class="py-3 text-center text-xs text-gray-400">
                                    Noch keine Ausgabe für diesen Run.
                                </div>
                            </div>

                            <!-- SUB-TAB 2: System-Prompt (View & Edit directly in Flow) -->
                            <div v-if="activePhaseSubTab[phase.key] === 'prompt_config'" class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-500 text-[11px]">System-Prompt / Anweisungen für den {{ phase.label }}-Agent:</span>
                                    <button
                                        @click="resetPromptToDefault(phase.key)"
                                        class="text-[10px] text-gray-400 hover:text-gray-700 underline cursor-pointer"
                                    >
                                        Auf Standard zurücksetzen
                                    </button>
                                </div>
                                <textarea
                                    v-model="prompts[phase.key]"
                                    rows="5"
                                    class="w-full bg-white border border-gray-200 rounded p-2 text-[11px] font-mono text-gray-800 focus:outline-none focus:border-gray-400 leading-normal"
                                ></textarea>
                                <div class="flex items-center justify-between">
                                    <span v-if="savedPrompt[phase.key]" class="text-[11px] text-emerald-600 font-medium">✓ Prompt gespeichert</span>
                                    <span v-else></span>
                                    <button
                                        @click="savePrompt(phase.key)"
                                        :disabled="savingPrompt[phase.key]"
                                        class="px-2.5 py-1 bg-gray-900 hover:bg-black text-white text-[11px] font-medium rounded transition-colors cursor-pointer disabled:opacity-50"
                                    >
                                        {{ savingPrompt[phase.key] ? 'Speichert…' : 'Prompt speichern' }}
                                    </button>
                                </div>
                            </div>

                            <!-- SUB-TAB 3: Tools executed (Compact, Filtered, Meaningful) -->
                            <div v-if="activePhaseSubTab[phase.key] === 'tools'" class="space-y-2">
                                <div v-if="!getPhaseData(phase.key).tools.length" class="py-3 text-center text-xs text-gray-400">
                                    Keine Tools aufgerufen.
                                </div>
                                <div v-else class="space-y-2">
                                    <!-- Tool Filter Pills -->
                                    <div class="flex items-center gap-1.5 flex-wrap pb-1.5 border-b border-gray-200/60">
                                        <button
                                            @click="selectedToolFilter[phase.key] = 'all'"
                                            class="text-[10px] font-mono px-2 py-0.5 rounded transition-colors cursor-pointer"
                                            :class="selectedToolFilter[phase.key] === 'all' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:text-gray-900'"
                                        >
                                            Alle ({{ getPhaseData(phase.key).tools.length }})
                                        </button>
                                        <button
                                            v-for="(count, tName) in toolSummary(getPhaseData(phase.key).tools)"
                                            :key="tName"
                                            @click="selectedToolFilter[phase.key] = selectedToolFilter[phase.key] === tName ? 'all' : tName"
                                            class="text-[10px] font-mono px-2 py-0.5 rounded transition-colors cursor-pointer"
                                            :class="selectedToolFilter[phase.key] === tName ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:text-gray-900'"
                                        >
                                            {{ tName }} ({{ count }})
                                        </button>
                                    </div>

                                    <!-- Compact Unified Log List -->
                                    <div class="divide-y divide-gray-100 border border-gray-200 rounded bg-white overflow-hidden max-h-72 overflow-y-auto">
                                        <div
                                            v-for="call in getPhaseData(phase.key).tools.filter(c => selectedToolFilter[phase.key] === 'all' || c.details?.tool === selectedToolFilter[phase.key])"
                                            :key="call.id"
                                            class="px-3 py-2 text-xs font-mono hover:bg-gray-50/70 transition-colors"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="flex items-center gap-2 min-w-0 flex-1">
                                                    <span class="font-bold text-gray-900 shrink-0">{{ call.details?.tool }}</span>
                                                    <span class="text-gray-500 truncate text-[11px] font-sans" :title="toolParamSummary(call)">
                                                        {{ toolParamSummary(call) }}
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-2 shrink-0">
                                                    <span v-if="call.details?.duration_ms" class="text-[10px] text-gray-400">
                                                        {{ (call.details.duration_ms / 1000).toFixed(1) }}s
                                                    </span>
                                                    <button
                                                        @click="toggleDetail(call.id)"
                                                        class="text-[10px] text-gray-400 hover:text-gray-700 underline cursor-pointer"
                                                    >
                                                        {{ expandedDetails[call.id] ? '▲' : 'JSON' }}
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Expanded JSON details -->
                                            <div v-if="expandedDetails[call.id]" class="mt-2 pt-2 border-t border-gray-100 space-y-1.5 text-[10px]">
                                                <div v-if="call.details?.input && Object.keys(call.details.input).length">
                                                    <span class="text-gray-400 uppercase font-sans">Input:</span>
                                                    <pre class="bg-gray-50 p-1.5 rounded text-gray-800 overflow-x-auto mt-0.5">{{ JSON.stringify(call.details?.input, null, 2) }}</pre>
                                                </div>
                                                <div v-if="call.details?.output">
                                                    <span class="text-gray-400 uppercase font-sans">Output:</span>
                                                    <pre class="bg-gray-900 text-gray-200 p-1.5 rounded max-h-32 overflow-y-auto mt-0.5">{{ typeof call.details?.output === 'object' ? JSON.stringify(call.details?.output, null, 2) : call.details?.output }}</pre>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SUB-TAB 4: Full Prompt & Data given to Model -->
                            <div v-if="activePhaseSubTab[phase.key] === 'run_input'" class="space-y-2.5">
                                <!-- Model & Meta Badge -->
                                <div class="flex items-center justify-between flex-wrap gap-2 p-2 bg-white border border-gray-200 rounded text-[11px] font-mono">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-gray-400">Modell:</span>
                                        <span class="font-bold text-gray-900">
                                            {{ getPhaseData(phase.key).modelInfo ? `${getPhaseData(phase.key).modelInfo.provider}/${getPhaseData(phase.key).modelInfo.model}` : modelLabel(phase.key) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2.5 text-gray-500 text-[10px]">
                                        <span v-if="getPhaseData(phase.key).modelInfo?.temperature !== undefined">
                                            Temp: {{ getPhaseData(phase.key).modelInfo.temperature }}
                                        </span>
                                        <span v-if="getPhaseData(phase.key).inputDetails?.persona_id">
                                            Persona: ID {{ getPhaseData(phase.key).inputDetails.persona_id }}
                                        </span>
                                        <span v-if="getPhaseData(phase.key).inputDetails?.strategy">
                                            Strategie: {{ getPhaseData(phase.key).inputDetails.strategy }}
                                        </span>
                                    </div>
                                </div>

                                <!-- 1. User Prompt (Task given to Model) -->
                                <div class="bg-white border border-gray-200 rounded p-2.5 space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-bold text-gray-700 uppercase tracking-wide">1. User Task / Message an Modell:</span>
                                        <button
                                            v-if="getPhaseData(phase.key).input"
                                            @click="copyText(getPhaseData(phase.key).input, 'User-Prompt kopiert!')"
                                            class="text-[10px] text-gray-400 hover:text-gray-700 underline cursor-pointer"
                                        >
                                            Kopieren
                                        </button>
                                    </div>
                                    <div v-if="getPhaseData(phase.key).input" class="bg-gray-50 border border-gray-100 rounded p-2 text-[11px] font-mono text-gray-900 whitespace-pre-wrap leading-relaxed max-h-36 overflow-y-auto">
                                        {{ getPhaseData(phase.key).input }}
                                    </div>
                                    <div v-else class="text-gray-400 text-[11px] italic p-1">
                                        Noch keine Task übergeben.
                                    </div>
                                </div>

                                <!-- 2. Full System Prompt & Injected Strategy Context -->
                                <div class="bg-white border border-gray-200 rounded p-2.5 space-y-1">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-gray-700 uppercase tracking-wide">2. Vollständiger System-Prompt & Strategie-Kontext:</span>
                                            <span v-if="getPhaseData(phase.key).systemPrompt" class="text-[9px] font-mono bg-gray-100 text-gray-600 px-1 rounded">
                                                {{ getPhaseData(phase.key).systemPrompt.length.toLocaleString() }} Zeichen
                                            </span>
                                        </div>
                                        <button
                                            v-if="getPhaseData(phase.key).systemPrompt"
                                            @click="copyText(getPhaseData(phase.key).systemPrompt, 'System-Prompt kopiert!')"
                                            class="text-[10px] text-gray-400 hover:text-gray-700 underline cursor-pointer"
                                        >
                                            Kopieren
                                        </button>
                                    </div>
                                    <div v-if="getPhaseData(phase.key).systemPrompt" class="bg-gray-50 border border-gray-100 rounded p-2 text-[11px] font-mono text-gray-800 whitespace-pre-wrap leading-relaxed max-h-64 overflow-y-auto">
                                        {{ getPhaseData(phase.key).systemPrompt }}
                                    </div>
                                    <div v-else class="text-gray-400 text-[11px] italic p-1">
                                        Wird beim Starten der Phase aufgezeichnet.
                                    </div>
                                </div>

                                <!-- 3. Extra Payload / Context Data -->
                                <div v-if="hasExtraInputData(getPhaseData(phase.key).inputDetails)" class="bg-white border border-gray-200 rounded p-2.5 space-y-1">
                                    <span class="text-[10px] font-bold text-gray-600 uppercase tracking-wide">3. Übergebene Kontext-Daten (Payload):</span>
                                    <pre class="bg-gray-50 border border-gray-100 rounded p-2 text-[10px] font-mono text-gray-800 overflow-x-auto max-h-36 leading-relaxed">{{ formatExtraInputData(getPhaseData(phase.key).inputDetails) }}</pre>
                                </div>

                                <div v-if="copiedNotice" class="text-center text-[10px] text-emerald-600 font-medium">
                                    ✓ {{ copiedNotice }}
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- 💡 Compact Angles Collapse Element (PLACED BELOW REVIEW PANEL) -->
                <div v-if="proposedAngles.length" class="border border-gray-200 bg-white rounded-lg overflow-hidden">
                    <div
                        class="px-3.5 py-2.5 flex items-center justify-between cursor-pointer hover:bg-gray-50 transition-colors select-none"
                        @click="anglesCollapsed = !anglesCollapsed"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-gray-900">Vorgeschlagene Angles</span>
                            <span class="text-[10px] font-mono bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded border border-gray-200">
                                {{ proposedAngles.length }} im Entwurf
                            </span>
                            <span class="text-[11px] text-gray-400 hidden sm:inline">· Freigabe erforderlich</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="proposedAngles.some(a => !a.approved)"
                                @click.stop="approveAllProposedAngles"
                                :disabled="isApprovingAll"
                                class="text-[11px] bg-gray-900 hover:bg-black text-white font-medium px-2 py-0.5 rounded transition-colors cursor-pointer disabled:opacity-50"
                            >
                                {{ isApprovingAll ? 'Freigeben…' : '✓ Alle freigeben' }}
                            </button>
                            <span class="text-[11px] text-gray-400 font-mono">{{ anglesCollapsed ? '▲' : '▼' }}</span>
                        </div>
                    </div>

                    <!-- Collapsed content -->
                    <div v-if="!anglesCollapsed" class="p-3 border-t border-gray-200 bg-gray-50/30 space-y-2.5">
                        <div
                            v-for="prop in proposedAngles"
                            :key="prop.id"
                            class="border rounded-lg p-3 space-y-2 text-xs bg-white"
                            :class="prop.approved ? 'border-emerald-300 bg-emerald-50/20' : 'border-gray-200'"
                        >
                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="font-mono text-[10px] text-gray-500 bg-gray-100 px-1 py-0.2 rounded">{{ prop.id }}</span>
                                    <span v-if="prop.icp" class="font-mono text-[10px] text-gray-800 bg-gray-100 font-semibold px-1.5 py-0.2 rounded">{{ prop.icp }}</span>
                                    <span v-if="prop.funnel" class="font-mono text-[10px] text-gray-700 bg-gray-100 px-1.5 py-0.2 rounded">{{ prop.funnel }}</span>
                                    <span v-if="prop.pain_cluster" class="font-mono text-[10px] text-gray-700 bg-gray-100 px-1.5 py-0.2 rounded">{{ prop.pain_cluster }}</span>
                                    <span v-if="prop.ranking_score !== null" class="font-mono text-[10px] text-gray-900 font-semibold bg-gray-100 px-1.5 py-0.2 rounded">
                                        Score: {{ prop.ranking_score }}/12
                                    </span>
                                </div>
                                <div class="shrink-0">
                                    <span v-if="prop.approved" class="text-[11px] font-mono text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded font-medium">
                                        ✓ In Pipeline ({{ prop.saved_angle_id }})
                                    </span>
                                    <button
                                        v-else
                                        @click="approveProposedAngle(prop.id)"
                                        :disabled="approvingId === prop.id"
                                        class="text-[11px] bg-gray-900 hover:bg-black text-white px-2.5 py-1 rounded transition-colors cursor-pointer disabled:opacity-50"
                                    >
                                        {{ approvingId === prop.id ? '…' : '✓ Freigeben' }}
                                    </button>
                                </div>
                            </div>

                            <!-- These (Hauptbehauptung) -->
                            <div class="text-xs font-semibold text-gray-900 leading-snug">
                                {{ prop.these || prop.angle }}
                            </div>

                            <!-- Mechanismus + Implikation + Beleg (wenn vorhanden) -->
                            <div v-if="prop.mechanismus || prop.implikation || prop.beleg" class="grid grid-cols-1 md:grid-cols-3 gap-2 pt-1.5 border-t border-gray-100 text-[11px] text-gray-600">
                                <div v-if="prop.mechanismus" class="bg-gray-50 p-2 rounded border border-gray-100">
                                    <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wide block mb-0.5">Ursache → Wirkung (Mechanismus):</span>
                                    <span>{{ prop.mechanismus }}</span>
                                </div>
                                <div v-if="prop.implikation" class="bg-gray-50 p-2 rounded border border-gray-100">
                                    <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wide block mb-0.5">Handlung für ICP (Implikation):</span>
                                    <span>{{ prop.implikation }}</span>
                                </div>
                                <div v-if="prop.beleg" class="bg-gray-50 p-2 rounded border border-gray-100">
                                    <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wide block mb-0.5">Kunden-Beleg / Trigger:</span>
                                    <span class="italic text-gray-700">„{{ prop.beleg.replace(/^[„"']|[“"']$/g, '') }}“</span>
                                </div>
                            </div>

                            <p v-if="prop.score_reasoning" class="text-[11px] text-gray-500 pt-1 border-t border-gray-100">
                                <span class="font-medium text-gray-700">Ranking-Begründung:</span> {{ prop.score_reasoning }}
                            </p>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right: Minimal History & Loops -->
            <div class="space-y-3.5">

                <!-- Run History -->
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="px-3.5 py-2.5 border-b border-gray-100 flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-800">Vergangene Runs</span>
                        <span class="text-[10px] font-mono text-gray-400">{{ runs.length }}</span>
                    </div>
                    <div class="divide-y divide-gray-100 max-h-[440px] overflow-y-auto">
                        <div v-if="!runs.length" class="px-3 py-6 text-xs text-gray-400 text-center">
                            Keine Runs vorhanden.
                        </div>
                        <div
                            v-for="run in runs"
                            :key="run.id"
                            @click="selectRun(run)"
                            class="px-3.5 py-2.5 hover:bg-gray-50 cursor-pointer transition-colors text-xs"
                            :class="activeRun?.id === run.id ? 'bg-gray-50 border-l-2 border-gray-900' : ''"
                        >
                            <div class="flex items-center justify-between mb-0.5">
                                <span class="font-bold text-gray-800">Run #{{ run.id }}</span>
                                <span class="text-[10px] px-1.5 py-0.2 rounded" :class="runStatusBadge(run)">
                                    {{ run.status }}
                                </span>
                            </div>
                            <p class="text-[11px] text-gray-500 truncate">{{ run.input }}</p>
                            <div class="text-[10px] text-gray-400 mt-0.5 font-mono">
                                {{ formatTime(run.created_at) }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Feedback Loops Status -->
                <div v-if="workflowLoops?.length" class="bg-white border border-gray-200 rounded-lg p-3 text-xs">
                    <div class="font-semibold text-gray-900 mb-1.5">Feedback-Loops</div>
                    <div v-for="loop in workflowLoops" :key="loop.id" class="text-gray-600 space-y-0.5">
                        <div class="font-medium text-gray-800">{{ loop.name }}</div>
                        <div class="text-[11px] font-mono text-gray-500">{{ loop.from_agent }} → {{ loop.to_agent }} (max. {{ loop.max_rounds }}×)</div>
                    </div>
                </div>

            </div>

        </div>

        <!-- ════════════════════════════════════════════════════════════════════ -->
        <!-- TAB 2: DEEP INSPECTOR & DEBUGGER                                    -->
        <!-- ════════════════════════════════════════════════════════════════════ -->
        <div v-show="pageTab === 'inspector'" class="space-y-4">
            <div v-if="!activeRun" class="bg-white border border-gray-200 rounded-lg p-8 text-center text-xs text-gray-400">
                Wähle zuerst einen Run aus der Historie oder starte einen Workflow.
            </div>

            <div v-else class="bg-white border border-gray-200 rounded-lg p-4 space-y-3.5">
                <!-- Filter bar -->
                <div class="flex items-center justify-between flex-wrap gap-2 pb-2.5 border-b border-gray-100 text-xs">
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-gray-400 mr-1">Filter:</span>
                        <button
                            v-for="flt in [
                                { id: 'all', label: 'Alle (' + timelineEvents.length + ')' },
                                { id: 'communication', label: 'Übergaben' },
                                { id: 'tool_call', label: 'Tools (' + toolCalls.length + ')' },
                                { id: 'loop', label: 'Loops (' + loopsList.length + ')' },
                                { id: 'llm', label: 'Prompts & Antworten' }
                            ]"
                            :key="flt.id"
                            @click="inspectorFilter = flt.id"
                            class="px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer"
                            :class="inspectorFilter === flt.id ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:text-gray-900'"
                        >
                            {{ flt.label }}
                        </button>
                    </div>
                    <span class="text-[10px] font-mono text-gray-400">Run #{{ activeRun.id }} Trace</span>
                </div>

                <!-- Timeline list -->
                <div class="space-y-2">
                    <div v-if="!filteredInspectorEvents.length" class="py-6 text-center text-xs text-gray-400">
                        Keine Events für diesen Filter vorhanden.
                    </div>

                    <div
                        v-for="evt in filteredInspectorEvents"
                        :key="evt.id"
                        class="border border-gray-200 rounded p-3 text-xs bg-white"
                    >
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <div class="flex items-center gap-1.5">
                                <span class="font-bold text-gray-900">{{ evt.title }}</span>
                                <span class="text-[10px] font-mono bg-gray-100 border border-gray-200 px-1 py-0.2 rounded text-gray-600">
                                    {{ evt.agent }}
                                </span>
                                <span v-if="evt.details?.duration_ms" class="text-[10px] font-mono text-gray-400">
                                    {{ evt.details.duration_ms }}ms
                                </span>
                            </div>
                            <span class="text-[10px] font-mono text-gray-400">{{ formatTime(evt.timestamp) }}</span>
                        </div>

                        <!-- Tool details -->
                        <div v-if="evt.type === 'tool_call'" class="mt-1.5 font-mono text-[11px]">
                            <button @click="toggleDetail(evt.id)" class="text-blue-600 hover:underline cursor-pointer">
                                {{ expandedDetails[evt.id] ? 'Details einklappen ▲' : 'Details anzeigen ▼' }}
                            </button>
                            <div v-if="expandedDetails[evt.id]" class="mt-1.5 space-y-1.5">
                                <div>
                                    <span class="text-gray-400 text-[10px] uppercase font-sans">Input:</span>
                                    <pre class="bg-gray-50 border p-1.5 rounded text-[10px] overflow-x-auto mt-0.5">{{ JSON.stringify(evt.details?.input, null, 2) }}</pre>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-[10px] uppercase font-sans">Output:</span>
                                    <pre class="bg-gray-900 text-gray-200 p-1.5 rounded text-[10px] overflow-x-auto max-h-36 mt-0.5">{{ typeof call.details?.output === 'object' ? JSON.stringify(call.details?.output, null, 2) : call.details?.output }}</pre>
                                </div>
                            </div>
                        </div>

                        <!-- Loop feedback -->
                        <div v-else-if="evt.type === 'loop'" class="mt-1.5">
                            <span class="font-mono text-[10px] px-1.5 py-0.2 rounded font-bold uppercase"
                                :class="evt.details?.verdict === 'pass' ? 'bg-emerald-50 text-emerald-800 border border-emerald-300' : 'bg-red-50 text-red-800 border border-red-300'">
                                Verdict: {{ evt.details?.verdict }}
                            </span>
                            <div v-if="evt.details?.feedback" class="mt-1 bg-gray-50 p-2 rounded text-xs text-gray-800 whitespace-pre-wrap">
                                {{ evt.details.feedback }}
                            </div>
                        </div>

                        <!-- Generic payload -->
                        <div v-else class="mt-1">
                            <button @click="toggleDetail(evt.id)" class="text-[10px] text-blue-600 hover:underline mb-0.5 cursor-pointer">
                                {{ expandedDetails[evt.id] ? 'Einklappen ▲' : 'Vorschau aufklappen ▼' }}
                            </button>
                            <pre v-if="expandedDetails[evt.id]" class="bg-gray-50 border p-2 rounded text-[11px] font-mono text-gray-800 whitespace-pre-wrap max-h-48 overflow-y-auto">
                                {{ evt.details?.prompt || evt.details?.output || evt.details?.payload }}
                            </pre>
                            <p v-else class="text-xs text-gray-600 truncate">
                                {{ evt.details?.prompt || evt.details?.output || evt.details?.payload }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Raw output -->
                <div v-if="activeRun?.output" class="pt-3 border-t border-gray-100">
                    <span class="text-xs font-semibold text-gray-700 block mb-1">Konsolen-Output</span>
                    <pre class="bg-gray-900 text-gray-200 rounded p-3 text-xs font-mono whitespace-pre-wrap max-h-60 overflow-y-auto leading-relaxed">{{ activeRun.output }}</pre>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
