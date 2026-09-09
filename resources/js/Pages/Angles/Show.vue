<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ angle: Object, templates: Array });

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

// ─── Status ───
const statusLabels = { neu: 'Neu', bewertet: 'Bewertet', approved: 'approved', verworfen: 'Verworfen' };
const statusClasses = { neu: 'bg-gray-100 text-gray-800', bewertet: 'bg-blue-50 text-blue-700', approved: 'bg-green-50 text-green-700', verworfen: 'bg-red-50 text-red-700' };
const status = ref(props.angle.status);
const statusOptions = ['neu', 'bewertet', 'approved', 'verworfen'];
async function setStatus() {
    if (status.value === props.angle.status) return;
    await router.patch(`/api/angles/${props.angle.id}`, { status: status.value }, { preserveState: true });
    props.angle.status = status.value;
}

// ─── Scoring (kompakt, Hover-Detail) ───
const rankings = ref({
    r_zielgruppe: props.angle.r_zielgruppe || 0,
    r_viscale_fit: props.angle.r_viscale_fit || 0,
    r_schaerfe: props.angle.r_schaerfe || 0,
    r_timing: props.angle.r_timing || 0,
});
const saving = ref(false);
const criteriaLabels = { r_zielgruppe: 'Zielgruppe', r_viscale_fit: 'Fit', r_schaerfe: 'Schärfe', r_timing: 'Timing' };
const criteriaHint = {
    r_zielgruppe: 'Wie präzise trifft der Angle den ICP? (1=generisch, 2=relevant, 3=punktgenau)',
    r_viscale_fit: 'Wie gut passt der Angle zur Positionierung? (1=schwach, 2=passend, 3=perfekt)',
    r_schaerfe: 'Wie provokativ/meinungsstark? (1=neutral, 2=pointiert, 3=scharf)',
    r_timing: 'Wie aktuell/relevant? (1=evergreen, 2=aktuell, 3=trend)',
};

// Score-Ring (SVG): Umfang = 2πr ≈ 97.4
const CIRC = 2 * Math.PI * 15.5;
const ringColor = computed(() => {
    const s = props.angle.ranking_score ?? 0;
    return s >= 10 ? '#10b981' : s >= 7 ? '#f59e0b' : '#ef4444';
});
const ringTextClass = computed(() => {
    const s = props.angle.ranking_score ?? 0;
    return s >= 10 ? 'text-green-600' : s >= 7 ? 'text-amber-600' : 'text-red-600';
});
const ringDash = computed(() => {
    const frac = Math.min(Math.max((props.angle.ranking_score ?? 0) / 12, 0), 1);
    return `${(frac * CIRC).toFixed(1)} ${CIRC.toFixed(1)}`;
});
function barColor(key) {
    const v = rankings.value[key] || 0;
    return v === 3 ? 'bg-indigo-500' : v === 2 ? 'bg-indigo-300' : 'bg-indigo-200';
}
function updateRanking() {
    saving.value = true;
    router.patch(`/api/angles/${props.angle.id}`, rankings.value, { preserveState: true, onFinish: () => saving.value = false });
}

// ─── Produzieren-Controls (kompakt) ───
const generating = ref(false);
const selectedTemplates = ref([]);
const activeFormat = ref('linkedin_post');
const statementType = ref(props.angle.statement_type || 'Direkt');
const statementReason = ref('');
const statementHint = ref('');
const statementOptions = ['Direkt', 'Drastisch', 'Bedrohlich', 'Gain', 'Mechanismus', 'Vision', 'Sarkastisch'];

const formatLabels = {
    linkedin_post: 'LinkedIn Post', ad_copy: 'Ad Copy',
    newsletter_acquisition: 'Newsletter', newsletter_bk: 'Newsletter BK',
    landing_page_headlines: 'Landing Page', blog_post: 'Blog Post',
};
const formatDesc = {
    linkedin_post: 'Hook → Mechanismus → Beweis → Soft-CTA.',
    ad_copy: 'Primary Text (max 125 Z.) → Headline (max 40 Z.) → CTA.',
    newsletter_acquisition: 'Betreff → Preview → Body → CTA.',
    newsletter_bk: 'Betreff → Einleitung → Hauptteil → Next Step.',
    landing_page_headlines: 'Hero Headline → Sub → 3 Bullets → CTA.',
    blog_post: 'Einleitung, Absätze, Fazit.',
};

// Fallback-Templates für Formate, die keine eigenen Template-Definitionen haben
const defaultTemplatesForFormat = {
    blog_post: [
        { name: 'Classic Blog Post', format: 'blog_post', description: 'Einleitung → 3 Absätze → Fazit mit CTA', pattern: 'framework' },
        { name: 'Problem-Lösung Blog', format: 'blog_post', description: 'Problem aufzeigen → Lösung erklären → CTA', pattern: 'question' },
        { name: 'Storytelling Blog', format: 'blog_post', description: 'Anekdote als Aufhänger → Learnings → Fazit', pattern: 'story' },
    ],
    ad_copy: [
        { name: 'Direct Response Ad', format: 'ad_copy', description: 'Pain → Versprechen → CTA', pattern: 'contrarian' },
        { name: 'Data-Driven Ad', format: 'ad_copy', description: 'Zahl als Hook → Nutzen → CTA', pattern: 'data_drop' },
    ],
    newsletter_acquisition: [
        { name: 'Acquisition Newsletter', format: 'newsletter_acquisition', description: 'Betreff → Problem → Lösung → CTA', pattern: 'question' },
    ],
    newsletter_bk: [
        { name: 'Bestandskunden Newsletter', format: 'newsletter_bk', description: 'Persönlich → 1-2 Punkte → Next Step', pattern: 'story' },
    ],
    landing_page_headlines: [
        { name: 'Hero Headline Set', format: 'landing_page_headlines', description: 'Hero → Sub → Bullets → CTA', pattern: 'framework' },
    ],
};

const availableFormats = computed(() => {
    const fmts = new Set((props.templates || []).map(t => t.format));
    Object.keys(defaultTemplatesForFormat).forEach(f => fmts.add(f));
    if (!fmts.has('linkedin_post')) fmts.add('linkedin_post');
    return [...fmts];
});

const filteredTemplates = computed(() => {
    const stored = (props.templates || []).filter(t => t.format === activeFormat.value);
    return stored.length ? stored : (defaultTemplatesForFormat[activeFormat.value] || []);
});

function selectFormat(fmt) { activeFormat.value = fmt; selectedTemplates.value = []; recommendStatement(); }
function toggleTemplate(tpl) {
    const idx = selectedTemplates.value.findIndex(t => t.name === tpl.name);
    if (idx >= 0) selectedTemplates.value.splice(idx, 1);
    else selectedTemplates.value.push(tpl);
}
function isSelected(name) { return selectedTemplates.value.some(t => t.name === name); }
function patternFor(tpl) {
    if (tpl.pattern) return tpl.pattern;
    const n = (tpl.name || '').toLowerCase();
    return n.includes('contrarian') ? 'contrarian' : n.includes('data') ? 'data_drop' : n.includes('mistake') ? 'mistake_post'
        : n.includes('story') ? 'story' : n.includes('list') ? 'listicle' : n.includes('question') ? 'question'
        : n.includes('framework') ? 'framework' : 'contrarian';
}

async function recommendStatement() {
    try {
        const res = await fetch('/api/content/recommend-statement', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ format: activeFormat.value, funnel: props.angle.funnel || null, icp: props.angle.icp || null, angle: props.angle.angle || '' }),
        });
        const data = await res.json();
        if (data.statement_type) { statementType.value = data.statement_type; statementReason.value = data.reason || ''; statementHint.value = data.hint || ''; }
    } catch {}
}

// ─── Editor-State: die erzeugten Posts ───
const posts = ref([]);              // aktive (nicht verworfene) Posts im Editor
const activePostId = ref(null);

const activePost = computed(() => posts.value.find(p => p.id === activePostId.value));

function selectPost(p) { activePostId.value = p.id; }

async function generateVariants() {
    if (!selectedTemplates.value.length) return;
    generating.value = true;
    generateError.value = '';
    startTimer();
    const patterns = selectedTemplates.value.map(patternFor);
    try {
        const res = await fetch('/api/content/produzieren', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                angle_id: props.angle.id, strategy: props.angle.strategy?.key, format: activeFormat.value,
                statement_type: statementType.value,
                variants_count: selectedTemplates.value.length, variant_patterns: patterns,
            }),
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data?.items) {
            // Fehler explizit anzeigen statt still zu scheitern
            const msg = data?.message || data?.error || `HTTP ${res.status}`;
            generateError.value = typeof msg === 'string' ? msg : JSON.stringify(msg);
        } else {
            posts.value = data.items;
            activePostId.value = data.items[0]?.id || null;
        }
    } catch (e) {
        generateError.value = 'Netzwerkfehler: ' + e.message;
    }
    stopTimer();
    generating.value = false;
}

// ─── Echtzeit-Status während der Generierung ───
const elapsed = ref(0);
const generateError = ref('');
let timerInterval = null;
function startTimer() {
    elapsed.value = 0;
    timerInterval = setInterval(() => elapsed.value++, 1000);
}
function stopTimer() { if (timerInterval) { clearInterval(timerInterval); timerInterval = null; } }
onUnmounted(stopTimer);

// Erwartete Dauer: ~30–90s pro Variante je nach Reasoning-Effort
const progressWidth = computed(() => Math.min(97, (elapsed.value / 90) * 100).toFixed(0) + '%');
const generatingHint = computed(() => {
    if (elapsed.value < 15) return 'KI schreibt…';
    if (elapsed.value < 40) return 'KI schreibt noch (Reasoning-Modelle brauchen Zeit)…';
    if (elapsed.value < 90) return 'Gleich fertig — längerer Text oder hohes Reasoning…';
    return 'Dauert ungewöhnlich lang — bitte warten oder Reasoning-Effort senken.';
});

// ─── Editor-Aktionen ───
const editInstruction = ref('');
const editLoading = ref(false);

async function submitEdit() {
    if (!editInstruction.value.trim() || !activePost.value) return;
    editLoading.value = true;
    try {
        const res = await fetch(`/api/content/${activePost.value.id}/assistant-edit`, {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ instruction: editInstruction.value.trim(), apply: true }),
        });
        const data = await res.json();
        if (data.content_item) {
            activePost.value.content = data.content_item.content;
            activePost.value.status = data.content_item.status;
        } else if (data.error) { alert('⚠️ ' + data.error); }
    } catch (e) { alert('Fehler: ' + e.message); }
    editLoading.value = false;
    editInstruction.value = '';
}

// Manuelles Editieren (direkt im Textarea) → speichern
async function saveManualEdit() {
    if (!activePost.value) return;
    await router.patch(`/api/content/${activePost.value.id}`, { content: activePost.value.content }, { preserveState: true });
}

async function approveToOutput(p) {
    if (p.variant_group_id) {
        const res = await fetch(`/api/content/${p.id}/select-variant`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() } });
        const data = await res.json();
        if (data.selected) {
            for (const g of posts.value) {
                if (g.id === data.selected.id) g.status = 'geplant';
                else if (g.variant_group_id === data.selected.variant_group_id) g.status = 'verworfen';
            }
        }
    } else {
        await router.patch(`/api/content/${p.id}`, { status: 'geplant' }, { preserveState: true });
        p.status = 'geplant';
    }
    // aktive (nicht verworfene) bleiben im Editor sichtbar
    posts.value = posts.value.filter(x => x.status !== 'verworfen');
}

function dismiss(p) {
    p.status = 'verworfen';
    posts.value = posts.value.filter(x => x.status !== 'verworfen');
    if (activePostId.value === p.id) activePostId.value = posts.value[0]?.id || null;
}

onMounted(recommendStatement);
</script>

<template>
    <AppLayout>
        <div class="mb-4">
            <a href="/angles" class="text-sm text-gray-400 hover:text-gray-700">← Zurück zu Angles</a>
        </div>

        <!-- ===================== TOP: Header + Score ===================== -->
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5 mb-5">
            <!-- gleiche rechte Spaltenbreite wie unten (360px) -->
            <!-- Header -->
            <div class="neu-card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                            <h2 class="text-lg font-bold text-gray-900 font-mono">{{ angle.id }}</h2>
                            <span class="text-xs text-gray-400">{{ angle.strategy?.name || '—' }} · {{ angle.batch_key || 'Kein Batch' }}</span>
                        </div>
                        <p class="text-base text-gray-800 leading-relaxed">{{ angle.angle }}</p>
                    </div>
                    <select v-model="status" @change="setStatus" class="text-xs px-2 py-1 rounded-full border-0 cursor-pointer shrink-0" :class="statusClasses[status]">
                        <option v-for="o in statusOptions" :key="o" :value="o">{{ statusLabels[o] }}</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-x-5 gap-y-1 mt-3 text-xs">
                    <span><span class="text-gray-400">ICP</span> <span class="text-gray-800 font-medium">{{ angle.icp || '—' }}</span></span>
                    <span><span class="text-gray-400">Cluster</span> <span class="text-gray-800">{{ angle.pain_cluster || '—' }}</span></span>
                    <span><span class="text-gray-400">Statement</span> <span class="text-gray-800">{{ angle.statement_type || '—' }}</span></span>
                    <span><span class="text-gray-400">Funnel</span> <span class="text-gray-800">{{ angle.funnel || '—' }}</span></span>
                </div>
            </div>

            <!-- Score (Ring + klickbare Kriterien) -->
            <div class="neu-card p-5">
                <div class="flex items-center gap-4 mb-4">
                    <div class="relative w-16 h-16 shrink-0">
                        <svg viewBox="0 0 36 36" class="w-16 h-16 -rotate-90">
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke="#e5e7eb" stroke-width="3.5"></circle>
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke-linecap="round" stroke-width="3.5"
                                :stroke="ringColor" :stroke-dasharray="ringDash"></circle>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-xl font-bold tabular-nums" :class="ringTextClass">{{ angle.ranking_score ?? '—' }}</span>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Score</p>
                        <p class="text-xs text-gray-400 mt-0.5">von 12 · Rang {{ angle.ranking_rang ?? '—' }}</p>
                    </div>
                </div>
                <div class="space-y-2.5">
                    <div v-for="(label, key) in criteriaLabels" :key="key">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[10px] text-gray-400 uppercase tracking-wide" :title="criteriaHint[key]">{{ label }}</span>
                            <div class="flex items-center gap-1">
                                <button v-for="v in [1, 2, 3]" :key="v" @click="rankings[key] = v; updateRanking()"
                                    class="w-5 h-5 rounded-md text-[10px] font-semibold transition-colors"
                                    :class="rankings[key] === v ? 'bg-indigo-500 text-white' : 'bg-gray-100 text-gray-400 hover:bg-gray-200'"
                                    :title="criteriaHint[key]">{{ v }}</button>
                            </div>
                        </div>
                        <div class="h-1 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all" :class="barColor(key)" :style="{ width: ((rankings[key] || 0) / 3) * 100 + '%' }"></div>
                        </div>
                    </div>
                </div>
                <p v-if="saving" class="text-[10px] text-gray-400 mt-2">Speichere…</p>
                <p v-if="angle.score_reasoning" class="text-[11px] text-gray-600 mt-3 bg-blue-50 border border-blue-100 rounded-lg p-2.5 leading-relaxed">{{ angle.score_reasoning }}</p>
            </div>
        </div>

        <!-- ===================== UNTEN: Editor (75%) + Produzieren (25%) ===================== -->
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5">
            <!-- Editor -->
            <div class="space-y-4">
                <div v-if="!posts.length" class="neu-card p-10 text-center text-gray-400" style="min-height: 400px;">
                    <p class="text-4xl mb-2">📝</p>
                    <p class="text-sm">Wähle rechts einen Post-Typ und ein Template → „Post generieren".</p>
                    <p class="text-xs mt-1">Der generierte Post erscheint hier als Editor.</p>
                </div>

                <template v-else>
                    <!-- Varianten-Tabs -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <button v-for="p in posts" :key="p.id" @click="selectPost(p)"
                            class="px-3 py-1.5 rounded-lg text-xs border"
                            :class="activePostId === p.id ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'">
                            {{ p.variant_pattern || 'Variante' }}
                            <span v-if="p.status === 'geplant'" class="ml-1 text-green-400">✓</span>
                        </button>
                    </div>

                    <!-- Editor -->
                    <div v-if="activePost" class="neu-card p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-gray-600">{{ formatLabels[activePost.format] }}</span>
                                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">{{ activePost.variant_pattern }}</span>
                                <span class="text-xs font-mono text-gray-300">{{ activePost.id }}</span>
                            </div>
                            <div class="flex gap-2">
                                <button @click="dismiss(activePost)" class="text-xs px-2 py-1 rounded-lg border border-gray-200 text-gray-500 hover:text-red-600">Verwerfen</button>
                                <button @click="approveToOutput(activePost)" :disabled="activePost.status === 'geplant'"
                                    class="text-xs px-3 py-1 rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">
                                    {{ activePost.status === 'geplant' ? '✓ In Output' : '→ Zum Output' }}
                                </button>
                            </div>
                        </div>

                        <!-- Editor-ähnliche Textarea (automatisch hohe Fläche) -->
                        <textarea v-model="activePost.content" rows="22"
                            class="w-full bg-white border border-gray-200 rounded-lg p-5 text-[15px] text-gray-900 leading-relaxed font-serif resize-y focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-200"
                            placeholder="Post-Text…"></textarea>
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-xs text-gray-400">{{ (activePost.content || '').length }} Zeichen</span>
                            <button @click="saveManualEdit" class="text-xs px-3 py-1 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200">💾 Manuelle Änderung speichern</button>
                        </div>

                        <!-- Assistant-Edit -->
                        <div class="mt-4 border-t border-gray-100 pt-3">
                            <p class="text-xs text-gray-500 font-medium mb-2">🤖 Assistant-Anweisung:</p>
                            <div class="flex gap-2">
                                <input v-model="editInstruction" @keydown.enter="submitEdit"
                                    class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-800 focus:outline-none focus:border-green-500"
                                    placeholder="z. B. Mach den Hook schärfer, kürze auf 800 Zeichen…" />
                                <button @click="submitEdit" :disabled="editLoading || !editInstruction.trim()"
                                    class="px-3 py-2 text-xs bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
                                    {{ editLoading ? '…' : 'Überarbeiten' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Bestehende Posts -->
                <div v-if="angle.content_items?.length" class="neu-card">
                    <div class="p-4 border-b border-gray-100"><h3 class="text-sm font-semibold text-gray-800">Bestehende Posts</h3></div>
                    <div class="divide-y divide-gray-100">
                        <div v-for="item in angle.content_items" :key="item.id" class="px-4 py-2.5 flex items-center justify-between text-sm">
                            <div>
                                <p class="text-gray-800">{{ item.title || item.content?.substring(0, 70) }}</p>
                                <p class="text-xs text-gray-400">{{ item.format }} · {{ item.status }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Produzieren Sidebar (25%) -->
            <div class="neu-card p-4 h-fit">
                <h3 class="text-sm font-semibold text-gray-800 mb-3">🚀 Produzieren</h3>

                <!-- Post-Typ -->
                <label class="text-[10px] text-gray-400 uppercase font-medium">Post-Typ</label>
                <select v-model="activeFormat" @change="selectFormat(activeFormat)" class="w-full bg-white border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-800 mb-1 focus:outline-none focus:border-green-500">
                    <option v-for="fmt in availableFormats" :key="fmt" :value="fmt">{{ formatLabels[fmt] || fmt }}</option>
                </select>
                <p class="text-[11px] text-gray-400 mb-3">{{ formatDesc[activeFormat] }}</p>

                <!-- Statement-Typ -->
                <label class="text-[10px] text-gray-400 uppercase font-medium">Statement-Typ <span class="text-indigo-500">(auto)</span></label>
                <select v-model="statementType" class="w-full bg-white border border-gray-300 rounded-lg px-2 py-1.5 text-xs text-gray-800 mb-1 focus:outline-none focus:border-green-500">
                    <option v-for="o in statementOptions" :key="o" :value="o">{{ o }}</option>
                </select>
                <p v-if="statementReason" class="text-[11px] text-gray-500 mb-3">💡 {{ statementReason }}</p>

                <!-- Templates -->
                <label class="text-[10px] text-gray-400 uppercase font-medium mb-1">Template(s)</label>
                <div class="space-y-1 max-h-40 overflow-y-auto mb-3">
                    <div v-for="tpl in filteredTemplates" :key="tpl.name" @click="toggleTemplate(tpl)"
                        class="flex items-center gap-2 px-2 py-1.5 rounded-md border cursor-pointer text-xs"
                        :class="isSelected(tpl.name) ? 'bg-green-50 border-green-300' : 'bg-white border-gray-200 hover:border-gray-300'">
                        <span :class="isSelected(tpl.name) ? 'text-green-600' : 'text-gray-300'">{{ isSelected(tpl.name) ? '✓' : '○' }}</span>
                        <span class="text-gray-800 truncate" :title="tpl.description">{{ tpl.name }}</span>
                    </div>
                    <p v-if="!filteredTemplates.length" class="text-[11px] text-gray-400 italic py-1">Keine Templates.</p>
                </div>

                <p v-if="generateError" class="text-[11px] text-red-600 bg-red-50 border border-red-200 rounded-md px-2 py-1.5 mb-2">⚠️ {{ generateError }}</p>
                <div v-if="generating" class="mb-2">
                    <div class="flex items-center justify-between text-[11px] text-gray-500 mb-1">
                        <span>{{ generatingHint }}</span>
                        <span class="font-mono tabular-nums">{{ elapsed }}s</span>
                    </div>
                    <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full bg-green-500 rounded-full transition-all duration-1000 ease-linear" :style="{ width: progressWidth }"></div>
                    </div>
                </div>
                <button @click="generateVariants" :disabled="generating || !selectedTemplates.length"
                    class="w-full px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-medium disabled:opacity-50">
                    {{ generating ? 'Generiere… ' + elapsed + 's' : (selectedTemplates.length > 1 ? '🚀 ' + selectedTemplates.length + ' Varianten generieren' : '🚀 Post generieren') }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>