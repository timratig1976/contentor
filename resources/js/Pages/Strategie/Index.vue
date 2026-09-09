<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array, currentStrategy: Object, contentKeys: Array });

const activeTab = ref('brand_voice');
const saving = ref(false);
const saved = ref(false);
const showNewStrategy = ref(false);
const newStrategyForm = reactive({ key: '', name: '' });

// Strategie wechseln (Dropdown im Header)
function switchStrategy(key) {
    if (!key || key === props.currentStrategy?.key) return;
    router.get('/strategie', { strategy: key }, { preserveScroll: true, onSuccess: () => router.reload() });
}

// ---- Persona-Mapping (globale Personas → Strategie) ----
const mappedPersonas = ref([]);
const allPersonas = ref([]);
const mapLoading = ref(false);
const showMapModal = ref(false);
const mapForm = reactive({ persona_id: '', angles: [], topic_clusters: [] });

async function loadPersonaMapping() {
    if (!props.currentStrategy) return;
    mapLoading.value = true;
    try {
        const [mapped, all] = await Promise.all([
            fetch(`/api/strategies/${props.currentStrategy.id}/personas`).then(r => r.json()),
            fetch('/api/personas').then(r => r.json()),
        ]);
        mappedPersonas.value = mapped;
        allPersonas.value = all;
    } finally {
        mapLoading.value = false;
    }
}

function openMapModal() {
    mapForm.persona_id = '';
    mapForm.angles = [];
    mapForm.topic_clusters = [];
    showMapModal.value = true;
}

async function attachPersona() {
    if (!mapForm.persona_id) return;
    await fetch(`/api/strategies/${props.currentStrategy.id}/personas`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({
            persona_id: Number(mapForm.persona_id),
            angles: mapForm.angles.filter(Boolean),
            topic_clusters: mapForm.topic_clusters.filter(Boolean),
        }),
    });
    showMapModal.value = false;
    loadPersonaMapping();
}

async function detachPersona(personaId) {
    if (!confirm('Diese Persona von der Strategie trennen? (Die Persona bleibt global erhalten.)')) return;
    await fetch(`/api/strategies/${props.currentStrategy.id}/personas/${personaId}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    });
    loadPersonaMapping();
}

// Beim Öffnen der Personas-Seite laden
watch(activeTab, (tab) => { if (tab === 'content_personas') loadPersonaMapping(); }, { immediate: true });

// ---- Strategie löschen (fail-safe) ----
const showDeleteStrategy = ref(false);
const deleting = ref(false);
const deleteError = ref('');

// Zählt alles, was zusammen mit der Strategie gelöscht wird.
const deleteTotals = computed(() => {
    const s = props.currentStrategy;
    if (!s) return null;
    return {
        sources: s.sources_count || 0,
        angles: s.angles_count || 0,
        content_items: s.content_items_count || 0,
        content_media: s.media_count || 0,
        personas: s.personas_count || 0,
        redaktionsplan: s.redaktionsplan_entries_count || 0,
        content_strategies: s.content_strategies_count || 0,
    };
});

async function confirmDeleteStrategy() {
    const strategy = props.currentStrategy;
    if (!strategy || deleting.value) return;

    deleting.value = true;
    deleteError.value = '';
    try {
        const res = await fetch(`/api/strategies/${strategy.id}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            deleteError.value = data.message || 'Strategie konnte nicht gelöscht werden. Bitte versuche es erneut.';
            return;
        }

        showDeleteStrategy.value = false;

        // Nach dem Löschen zur nächsten verbleibenden Strategie wechseln
        // (oder auf die Strategie-Seite, falls keine mehr existiert).
        router.get('/strategie', data.next_strategy_key ? { strategy: data.next_strategy_key } : {}, {
            preserveScroll: true,
            onSuccess: () => router.reload(),
        });
    } catch {
        deleteError.value = 'Verbindungsfehler beim Löschen.';
    } finally {
        deleting.value = false;
    }
}

const forms = reactive({
    brand_voice: { personality: '', tone: 'direkt', never: [], must: [] },
    channel_rules: { channels: [] },
    icp_definitions: { icps: [], default_icp: '' },
    media_logic: { rules: [] },
    editorial_rhythm: { cadence: 'weekly', slots: [] },
    content_strategy: { pillars: [], goals: '' },
    post_templates: { templates: [] },
});

props.contentKeys.forEach(s => {
    if (s.content) forms[s.key] = { ...forms[s.key], ...s.content };
});

const tabLabels = {
    brand_voice: 'Brand Voice',
    channel_rules: 'Kanal-Regeln',
    icp_definitions: '🎯 ICP-Definitionen',
    media_logic: 'Medien-Logik',
    editorial_rhythm: 'Redaktions-Rhythmus',
    content_strategy: 'Content-Strategie',
    post_templates: 'Post-Templates',
    content_personas: 'Personas',
    ki_settings: '🤖 KI-Einstellungen',
};

async function save() {
    saving.value = true; saved.value = false;
    try {
        const strategy = props.currentStrategy;
        if (!strategy) return;

        // ICP-Definitionen: speichern in ContentStrategy + Strategy.config.rules
        if (activeTab.value === 'icp_definitions') {
            await fetch('/api/strategy', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ strategy: strategy.key, key: 'icp_definitions', content: forms.icp_definitions }),
            });

            const config = { ...(strategy.config || {}) };
            config.rules = { ...(config.rules || {}) };
            config.rules.icpGuesser = forms.icp_definitions.icps.map(icp => ({
                icp: icp.key,
                match: icp.match_keywords,
            }));
            config.rules.defaultIcp = forms.icp_definitions.default_icp || 'B2B-1';

            await fetch(`/api/strategies/${strategy.id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ config }),
            });
        } else {
            await fetch('/api/strategy', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ strategy: strategy.key, key: activeTab.value, content: forms[activeTab.value] }),
            });
        }

        saved.value = true; setTimeout(() => saved.value = false, 2000);
    } finally { saving.value = false; }
}

async function createStrategy() {
    await fetch('/api/strategies', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({ key: newStrategyForm.key, name: newStrategyForm.name }),
    });
    showNewStrategy.value = false;
    router.reload();
}

function addItem(key, field) { if (!forms[key][field]) forms[key][field] = []; forms[key][field].push(''); }

// ---- Auto-Approve-Threshold (in strategy.config.rules) ----
const autoApproveScore = ref(props.currentStrategy?.config?.rules?.autoApproveScore ?? null);
const autoApproveSaving = ref(false);
const autoApproveSaved = ref(false);

async function saveAutoApprove() {
    const strategy = props.currentStrategy;
    if (!strategy || autoApproveSaving.value) return;

    autoApproveSaving.value = true;
    autoApproveSaved.value = false;

    const config = { ...(strategy.config || {}) };
    config.rules = { ...(config.rules || {}) };
    if (autoApproveScore.value === null || autoApproveScore.value === '' || autoApproveScore.value === undefined) {
        delete config.rules.autoApproveScore;
    } else {
        config.rules.autoApproveScore = Number(autoApproveScore.value);
    }

    try {
        const res = await fetch(`/api/strategies/${strategy.id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ config }),
        });
        if (res.ok) {
            autoApproveSaved.value = true;
            setTimeout(() => autoApproveSaved.value = false, 2000);
        }
    } finally {
        autoApproveSaving.value = false;
    }
}
function removeItem(key, field, index) { forms[key][field].splice(index, 1); }
function addChannel() { forms.channel_rules.channels.push({ channel: 'linkedin', frequency: 'weekly', best_times: [], rules: [] }); }
function addIcp() { forms.icp_definitions.icps.push({ key: '', name: '', description: '', role: '', pain_points: [], gains: [], match_keywords: '', default_funnel: 'ToFu', priority: 'medium' }); }
function removeIcp(index) {
    const icp = forms.icp_definitions.icps[index];
    if (!icp) return;
    if (icp.key && !confirm(`ICP „${icp.key}${icp.name ? ' — ' + icp.name : ''}“ wirklich entfernen?`)) return;
    forms.icp_definitions.icps.splice(index, 1);
}
function addTemplate() { forms.post_templates.templates.push({ format: 'linkedin_post', name: '', structure: [], example: '' }); }

// ---- Template-Katalog (inline in Strategie) ----
const templateFilter = ref('');
const availableTemplates = [
    { name: 'Contrarian Take', format: 'linkedin_post', description: 'Gegen den Mainstream — alle sagen X, Realität ist Y', best_for: ['B2B-2', 'B2B-3'], structure: "These gegen den Mainstream (max 8 Wörter)\nWarum der Mainstream irrt\nBeleg / Mechanismus\nWas das für den Leser heißt\nSoft CTA", example: 'CRM-Automatisierung macht euren Vertrieb schlechter.' },
    { name: 'Data Drop', format: 'linkedin_post', description: 'Überraschende Zahl + Einordnung', best_for: ['B2B-1', 'B2B-2'], structure: "Überraschende Zahl / Fakt\nWarum das überrascht\nMechanismus dahinter\nKonsequenz\nSoft CTA", example: '73% der Forecasts liegen daneben.' },
    { name: 'Mistake Post', format: 'linkedin_post', description: 'Die N häufigsten Fehler bei X', best_for: ['B2B-3', 'B2B-1'], structure: "Fehler 1 + Folge\nFehler 2 + Folge\nFehler 3 + Folge\nWie man es richtig macht\nSoft CTA", example: '3 Fehler bei der HubSpot-Property-Hygiene.' },
    { name: 'Framework / Modell', format: 'linkedin_post', description: 'Eigenes Denkmodell teilen', best_for: ['B2B-1', 'B2B-2'], structure: "Problem\nModell-Übersicht (3 Stufen)\nStufe 1-3\nErgebnis\nSoft CTA", example: 'Unser 3-Stufen-Modell für Datenhygiene.' },
    { name: 'Storytelling Post', format: 'linkedin_post', description: 'Persönliche Geschichte mit Lerneffekt', best_for: ['B2B-1', 'B2B-3'], structure: "Hook (emotional)\nAusgangssituation\nWendepunkt\nLösung / Learnings\nCTA", example: 'Letzte Woche saß ich mit einem CEO zusammen.' },
    { name: 'Listicle', format: 'linkedin_post', description: 'Aufzählung mit Mehrwert', best_for: ['B2B-1', 'B2B-2', 'B2B-3'], structure: "Hook (Zahl + Thema)\nPunkt 1\nPunkt 2\nPunkt 3\nFazit\nCTA", example: '5 Dinge, die wir von unseren besten Kunden gelernt haben.' },
    { name: 'Question Post', format: 'linkedin_post', description: 'Frage ans Netzwerk, hohe Engagement-Rate', best_for: ['B2B-1', 'B2B-2', 'B2B-3'], structure: "Frage (provokant)\nKontext\nEigene Einschätzung\nDiskussion", example: 'Warum haben die meisten kein Pipeline-Review?' },
    { name: 'Ad Copy — Schmerz', format: 'ad_copy', description: 'Schmerz-getriebene Anzeige', best_for: ['B2B-2', 'B2B-3'], structure: "Primary Text (max 125)\nHeadline (max 40)\nDescription\nCTA", example: 'Primary: Forecast daneben?' },
    { name: 'Ad Copy — Gain', format: 'ad_copy', description: 'Gain-getriebene Anzeige', best_for: ['B2B-1', 'B2B-2'], structure: "Primary Text (max 125)\nHeadline (max 40)\nDescription\nCTA", example: 'Primary: Planbares Wachstum.' },
    { name: 'Newsletter BK', format: 'newsletter_bk', description: 'Bestandskunden-Mail (BK = Lifecycle, Zielgruppe = B2B-Segment)', best_for: ['B2B-1', 'B2B-2', 'B2B-3'], structure: "Betreff (max 50)\nPreview-Text\nEinleitung\nHauptteil\nNext Step\nSign-off", example: 'Betreff: Datenhygiene in 15 Minuten' },
    { name: 'Landing Page', format: 'landing_page_headlines', description: 'Konversions-starke LP', best_for: ['B2B-1', 'B2B-2'], structure: "Hero Headline\nSub-Headline\n3 Bullet Points\nCTA-Button", example: 'Headline: Schluss mit Blindflug' },
];
const filteredTemplates = computed(() => {
    if (!templateFilter.value) return availableTemplates;
    return availableTemplates.filter(t => t.format === templateFilter.value);
});
function isTemplateSelected(name) {
    return (forms.post_templates.templates || []).some(t => t.name === name);
}
function toggleTemplate(tpl) {
    if (!forms.post_templates.templates) forms.post_templates.templates = [];
    const idx = forms.post_templates.templates.findIndex(t => t.name === tpl.name);
    if (idx >= 0) {
        forms.post_templates.templates.splice(idx, 1);
    } else {
        forms.post_templates.templates.push({ format: tpl.format, name: tpl.name, structure: tpl.structure, example: tpl.example });
    }
}

// ---- Template-Detail-Modal ----
const detailModal = ref(null); // aktuell geöffnetes Template
const modalGeneratedExample = ref('');
const modalGenerating = ref(false);

function openDetail(tpl) { detailModal.value = tpl; modalGeneratedExample.value = ''; }
function closeDetail() { detailModal.value = null; }

async function generateExampleForModal(tpl) {
    if (!props.currentStrategy || modalGenerating.value) return;
    modalGenerating.value = true;
    try {
        const res = await fetch('/api/assistant/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({
                message: `Generiere ein konkretes Beispiel für das Template "${tpl.name}" (Format: ${tpl.format}) für die Strategie "${props.currentStrategy.name}". Template-Struktur: ${tpl.structure}. Gib NUR den fertigen Content-Text aus, keine Erklärungen.`,
                history: [],
            }),
        });
        const data = await res.json();
        modalGeneratedExample.value = data.reply || 'Keine Antwort.';
    } catch { modalGeneratedExample.value = 'Fehler bei der Generierung.'; }
    modalGenerating.value = false;
}

function addMediaRule() { forms.media_logic.rules.push({ format: 'image', style: '', aspect_ratio: '1:1', notes: '' }); }
function addEditorialSlot() { forms.editorial_rhythm.slots.push({ day: 'Monday', channel: 'linkedin', format: 'post', persona: '' }); }
function addPillar() { forms.content_strategy.pillars.push({ name: '', description: '', icp_focus: [] }); }

// ---- ICP-Definitionen aus DB initialisieren ----
function loadIcpFromConfig() {
    const strategy = props.currentStrategy;
    if (!strategy) return;

    // 1. Aus ContentStrategy (gespeicherte UI-Daten)
    const contentKey = props.contentKeys?.find(k => k.key === 'icp_definitions');
    if (contentKey?.content?.icps?.length) {
        forms.icp_definitions.icps = JSON.parse(JSON.stringify(contentKey.content.icps));
        forms.icp_definitions.default_icp = contentKey.content.default_icp || '';
        return;
    }

    // 2. Fallback: aus Strategy.config.rules (icpGuesser + clusters)
    const rules = strategy.config?.rules || {};
    const guesser = rules.icpGuesser || [];
    if (guesser.length) {
        forms.icp_definitions.icps = guesser.map(g => ({
            key: g.icp,
            name: '',
            description: '',
            role: '',
            pain_points: [],
            gains: [],
            match_keywords: g.match || '',
            default_funnel: 'ToFu',
            priority: 'medium',
        }));
        forms.icp_definitions.default_icp = rules.defaultIcp || '';
    }
}
onMounted(loadIcpFromConfig);

</script>

<template>
  <AppLayout>
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-4">
        <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Content-Strategie</h1>
        <!-- Aktive Strategie (Dropdown zum Wechseln) -->
        <select
          v-if="strategies.length"
          :value="props.currentStrategy?.key"
          @change="switchStrategy($event.target.value)"
          class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-500/20">
          <option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option>
        </select>
        <p v-if="props.currentStrategy" class="text-sm text-gray-400">· {{ props.currentStrategy.key }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button @click="showDeleteStrategy = true" :disabled="!props.currentStrategy"
          class="px-4 py-2 text-sm rounded-lg bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
          🗑 Strategie löschen
        </button>
        <button @click="showNewStrategy = true" class="neu-btn-primary px-4 py-2 text-sm">+ Neue Strategie</button>
      </div>
    </div>

    <!-- Lösch-Dialog (fail-safe) -->
    <div v-if="showDeleteStrategy && props.currentStrategy" class="fixed inset-0 z-50 flex items-center justify-center">
      <div class="fixed inset-0 bg-black/40" @click="!deleting && (showDeleteStrategy = false)"></div>
      <div class="relative bg-white rounded-2xl shadow-xl p-6 w-full max-w-md mx-4 z-10">
        <h3 class="text-lg font-semibold text-red-600 mb-1">Strategie endgültig löschen?</h3>
        <p class="text-sm text-gray-600 mb-4">
          „{{ props.currentStrategy.name }}“ wird <strong>endgültig</strong> zusammen mit allen zugehörigen Daten gelöscht. Dieser Vorgang kann nicht rückgängig gemacht werden.
        </p>
        <div v-if="deleteTotals" class="bg-red-50 border border-red-100 rounded-lg p-3 mb-4 text-sm text-gray-700">
          <p class="font-medium mb-2">Das wird mitgelöscht:</p>
          <ul class="space-y-1">
            <li v-if="deleteTotals.content_strategies" class="flex justify-between"><span>Content-Strategie-Blöcke</span><span class="font-medium">{{ deleteTotals.content_strategies }}</span></li>
            <li v-if="deleteTotals.personas" class="flex justify-between"><span>Personas</span><span class="font-medium">{{ deleteTotals.personas }}</span></li>
            <li v-if="deleteTotals.redaktionsplan" class="flex justify-between"><span>Redaktionsplan-Einträge</span><span class="font-medium">{{ deleteTotals.redaktionsplan }}</span></li>
            <li v-if="deleteTotals.content_items" class="flex justify-between"><span>Content-Items</span><span class="font-medium">{{ deleteTotals.content_items }}</span></li>
            <li v-if="deleteTotals.content_media" class="flex justify-between"><span>Medien</span><span class="font-medium">{{ deleteTotals.content_media }}</span></li>
            <li v-if="deleteTotals.angles" class="flex justify-between"><span>Angles</span><span class="font-medium">{{ deleteTotals.angles }}</span></li>
            <li v-if="deleteTotals.sources" class="flex justify-between"><span>Quellen</span><span class="font-medium">{{ deleteTotals.sources }}</span></li>
            <li v-if="!Object.values(deleteTotals).some(v => v > 0)" class="text-gray-500 italic">Keine verknüpften Daten vorhanden.</li>
          </ul>
        </div>
        <p v-if="deleteError" class="text-sm text-red-600 mb-3">{{ deleteError }}</p>
        <div class="flex gap-3">
          <button @click="confirmDeleteStrategy" :disabled="deleting"
            class="px-4 py-2 text-sm rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 transition-colors">
            {{ deleting ? 'Wird gelöscht…' : 'Endgültig löschen' }}
          </button>
          <button @click="showDeleteStrategy = false" :disabled="deleting"
            class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 disabled:opacity-50">
            Abbrechen
          </button>
        </div>
      </div>
    </div>

    <template v-if="props.currentStrategy">
    <div class="flex gap-1 mb-6 overflow-x-auto">
      <button v-for="(label, key) in tabLabels" :key="key" @click="activeTab = key"
        class="px-3 py-1.5 rounded-lg text-sm whitespace-nowrap font-medium"
        :class="activeTab === key ? 'bg-white border border-gray-200 text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'">
        {{ label }}
      </button>
    </div>

    <div v-if="showNewStrategy" class="fixed inset-0 z-50 flex items-center justify-center">
      <div class="fixed inset-0 bg-black/40" @click="showNewStrategy = false"></div>
      <div class="relative bg-white rounded-2xl shadow-xl p-6 w-full max-w-md mx-4 z-10">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Neue Strategie</h3>
        <div class="space-y-3">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Key</label><input v-model="newStrategyForm.key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Name</label><input v-model="newStrategyForm.name" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /></div>
        </div>
        <div class="flex gap-3 mt-4"><button @click="createStrategy" class="neu-btn-primary px-4 py-2 text-sm">Erstellen</button><button @click="showNewStrategy = false" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">Abbrechen</button></div>
      </div>
    </div>

    <div v-if="activeTab === 'brand_voice'" class="space-y-5">
      <div class="bg-white border border-gray-200 rounded-xl p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Brand Voice</h3>
        <div class="space-y-4">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Persönlichkeit</label><textarea v-model="forms.brand_voice.personality" rows="3" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="z.B. Direkt, analytisch, kein Bullshit, auf Augenhöhe..."></textarea></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Tonalität</label><select v-model="forms.brand_voice.tone" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"><option value="direkt">Direkt & klar</option><option value="beratend">Beratend</option><option value="provokativ">Provokativ</option><option value="inspirierend">Inspirierend</option><option value="analytisch">Analytisch</option></select></div>
          <div><label class="block text-sm text-gray-700 mb-2">🚫 Niemals verwenden</label><div class="space-y-2 mb-2"><div v-for="(item, i) in (forms.brand_voice.never || [])" :key="i" class="flex gap-2"><input v-model="forms.brand_voice.never[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="z.B. revolutionär" /><button @click="removeItem('brand_voice', 'never', i)" class="text-red-500 hover:text-red-700">✕</button></div></div><button @click="addItem('brand_voice', 'never')" class="text-sm text-green-600 hover:text-green-700 font-medium">+ Verbotenes Wort</button></div>
          <div><label class="block text-sm text-gray-700 mb-2">✅ Immer verwenden</label><div class="space-y-2 mb-2"><div v-for="(item, i) in (forms.brand_voice.must || [])" :key="i" class="flex gap-2"><input v-model="forms.brand_voice.must[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="z.B. Mechanismus" /><button @click="removeItem('brand_voice', 'must', i)" class="text-red-500 hover:text-red-700">✕</button></div></div><button @click="addItem('brand_voice', 'must')" class="text-sm text-green-600 hover:text-green-700 font-medium">+ Pflicht-Wort</button></div>
        </div>
      </div>

      <!-- Scoring & Auto-Approve -->
      <div class="bg-white border border-gray-200 rounded-xl p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Scoring & Auto-Approve</h3>
        <div class="grid grid-cols-2 gap-4 items-end">
          <div>
            <label class="block text-sm text-gray-900 mb-1 font-medium">Auto-Approve ab Score</label>
            <input type="number" min="1" max="12" v-model.number="autoApproveScore"
              class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
              placeholder="z.B. 10 (max 12)" />
            <p class="text-xs text-gray-500 mt-1">
              Angles ab diesem Score werden automatisch auf „approved" gesetzt.
              Leer lassen = immer manuelle Review.
            </p>
          </div>
          <div class="flex items-center gap-3">
            <button @click="saveAutoApprove" :disabled="autoApproveSaving"
              class="px-4 py-2 bg-neu hover:bg-gray-300 text-gray-800 rounded-lg text-sm transition-colors disabled:opacity-50">
              {{ autoApproveSaving ? 'Speichern…' : 'Speichern' }}
            </button>
            <span v-if="autoApproveSaved" class="text-xs text-green-600">✓ Gespeichert</span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="activeTab === 'ki_settings'" class="space-y-4">

      <!-- Auto-Approve -->
      <div class="bg-white border border-gray-200 rounded-xl p-5">
        <div class="flex items-start justify-between gap-6">
          <div class="flex-1">
            <h3 class="text-sm font-semibold text-gray-900 mb-0.5">Auto-Approve Schwellenwert</h3>
            <p class="text-xs text-gray-500 mb-3">Angles ab diesem Score werden automatisch auf <span class="text-green-600 font-medium">approved</span> gesetzt. Leer lassen für immer manuelle Review.</p>
            <div class="flex items-center gap-3">
              <div class="relative w-32">
                <input type="number" min="1" max="12" v-model.number="autoApproveScore"
                  class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 text-center font-semibold focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                  placeholder="—" />
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">/ 12</span>
              </div>
              <button @click="saveAutoApprove" :disabled="autoApproveSaving"
                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50">
                {{ autoApproveSaving ? 'Speichern…' : 'Speichern' }}
              </button>
              <span v-if="autoApproveSaved" class="text-xs text-green-600 font-medium">✓ Gespeichert</span>
            </div>
          </div>

          <!-- Score-Legende kompakt -->
          <div class="shrink-0 bg-gray-50 border border-gray-100 rounded-lg p-3 text-xs space-y-1.5 min-w-[220px]">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Score-Legende</p>
            <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span>
              <span class="font-medium text-gray-700 w-16">≥ 10</span>
              <span class="text-gray-500">Top — auto-approved</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-yellow-400 shrink-0"></span>
              <span class="font-medium text-gray-700 w-16">7 – 9</span>
              <span class="text-gray-500">Solide — Review</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-red-400 shrink-0"></span>
              <span class="font-medium text-gray-700 w-16">&lt; 7</span>
              <span class="text-gray-500">Schwach — überarbeiten</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-gray-300 shrink-0"></span>
              <span class="font-medium text-gray-700 w-16">—</span>
              <span class="text-gray-500">Nicht bewertet</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Kriterien-Übersicht -->
      <div class="bg-white border border-gray-200 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-gray-900 mb-3">Scoring-Kriterien</h3>
        <div class="grid grid-cols-2 gap-3">
          <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs font-semibold text-gray-700 mb-0.5">Zielgruppe</p>
            <p class="text-xs text-gray-500">Wie präzise trifft der Angle den ICP?</p>
            <div class="flex gap-1 mt-2">
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">1 generisch</span>
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">2 relevant</span>
              <span class="text-xs bg-green-50 border border-green-200 rounded px-1.5 py-0.5 text-green-700">3 punktgenau</span>
            </div>
          </div>
          <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs font-semibold text-gray-700 mb-0.5">Strategie-Fit</p>
            <p class="text-xs text-gray-500">Passt der Angle zur Positionierung?</p>
            <div class="flex gap-1 mt-2">
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">1 schwach</span>
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">2 passend</span>
              <span class="text-xs bg-green-50 border border-green-200 rounded px-1.5 py-0.5 text-green-700">3 perfekt</span>
            </div>
          </div>
          <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs font-semibold text-gray-700 mb-0.5">Schärfe</p>
            <p class="text-xs text-gray-500">Wie provokativ / meinungsstark?</p>
            <div class="flex gap-1 mt-2">
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">1 neutral</span>
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">2 pointiert</span>
              <span class="text-xs bg-green-50 border border-green-200 rounded px-1.5 py-0.5 text-green-700">3 scharf</span>
            </div>
          </div>
          <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-xs font-semibold text-gray-700 mb-0.5">Timing</p>
            <p class="text-xs text-gray-500">Wie aktuell / relevant ist das Thema?</p>
            <div class="flex gap-1 mt-2">
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">1 evergreen</span>
              <span class="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-500">2 aktuell</span>
              <span class="text-xs bg-green-50 border border-green-200 rounded px-1.5 py-0.5 text-green-700">3 trend</span>
            </div>
          </div>
        </div>
      </div>

    </div>

    <div v-if="activeTab === 'channel_rules'" class="space-y-5">
      <div class="flex justify-between items-center">
        <h3 class="text-sm font-semibold text-gray-900">Kanal-Regelwerk</h3>
        <button @click="addChannel" class="neu-btn-primary px-4 py-2 text-sm">+ Kanal</button>
      </div>
      <div v-for="(ch, i) in forms.channel_rules.channels" :key="i" class="bg-white border border-gray-200 rounded-xl p-5 relative">
        <button @click="forms.channel_rules.channels.splice(i, 1)" class="absolute top-3 right-3 text-red-500 hover:text-red-700">✕</button>
        <div class="grid grid-cols-2 gap-4 mb-4">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Kanal</label><select v-model="ch.channel" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option value="linkedin">LinkedIn</option><option value="newsletter">Newsletter</option><option value="blog">Blog</option><option value="instagram">Instagram</option><option value="meta_ads">Meta Ads</option><option value="linkedin_ads">LinkedIn Ads</option></select></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Frequenz</label><select v-model="ch.frequency" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option value="daily">Täglich</option><option value="weekly">1× Woche</option><option value="biweekly">2× Woche</option><option value="triweekly">3× Woche</option><option value="monthly">1× Monat</option></select></div>
        </div>
        <div class="mb-4"><label class="block text-sm text-gray-900 mb-1 font-medium">Beste Posting-Zeiten</label><div class="flex flex-wrap gap-2"><span v-for="t in (ch.best_times || [])" :key="t" class="text-xs bg-white border border-gray-200 text-gray-700 px-2 py-1 rounded flex items-center gap-1">{{ t }}<button @click="ch.best_times = ch.best_times.filter(x => x !== t)" class="text-red-500">✕</button></span><button @click="ch.best_times.push('Mo 09:00')" class="text-sm text-green-600 font-medium">+ Zeit</button></div></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Format-Regeln</label><div class="space-y-1.5 mb-2"><div v-for="(rule, ri) in (ch.rules || [])" :key="ri" class="flex gap-2"><input v-model="ch.rules[ri]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="Max 3 Hashtags" /><button @click="ch.rules.splice(ri, 1)" class="text-red-500">✕</button></div></div><button @click="ch.rules = [...(ch.rules || []), '']" class="text-sm text-green-600 font-medium">+ Regel</button></div>
      </div>
      <p v-if="!forms.channel_rules.channels.length" class="text-sm text-gray-500 italic">Noch keine Kanäle definiert.</p>
    </div>

    <div v-if="activeTab === 'icp_definitions'" class="space-y-5">
      <div class="flex items-center justify-between mb-2">
        <div>
          <h3 class="text-sm font-semibold text-gray-900">ICP-Definitionen</h3>
          <p class="text-xs text-gray-500 mt-0.5">Definiere deine Zielgruppen-Segmente mit Pain Points, Gains und Match-Keywords für die automatische Erkennung.</p>
        </div>
      </div>

      <!-- Default ICP -->
      <div class="bg-white border border-gray-200 rounded-xl p-4">
        <div class="flex items-center gap-4">
          <label class="text-sm font-medium text-gray-900 shrink-0">Standard-ICP</label>
          <select v-model="forms.icp_definitions.default_icp" class="bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900">
            <option value="">—</option>
            <option v-for="icp in forms.icp_definitions.icps" :key="icp.key" :value="icp.key">{{ icp.key }} — {{ icp.name || icp.key }}</option>
          </select>
          <p class="text-xs text-gray-500">Fallback-ICP, wenn kein Regex-Match auf den Angle-Text.</p>
        </div>
      </div>

      <!-- ICP Cards -->
      <div v-for="(icp, i) in forms.icp_definitions.icps" :key="i" class="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
        <div class="flex items-center justify-between">
          <h4 class="text-sm font-semibold text-gray-900">{{ icp.key || 'Neuer ICP' }}{{ icp.name ? ' — ' + icp.name : '' }}</h4>
          <button @click="removeIcp(i)" class="text-red-500 hover:text-red-700 text-sm">✕ Entfernen</button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label class="block text-xs text-gray-900 mb-1 font-medium">Key *</label>
            <input v-model="icp.key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-mono" placeholder="B2B-1" />
          </div>
          <div>
            <label class="block text-xs text-gray-900 mb-1 font-medium">Name</label>
            <input v-model="icp.name" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="CRM-Entscheider Mittelstand" />
          </div>
          <div>
            <label class="block text-xs text-gray-900 mb-1 font-medium">Rolle</label>
            <input v-model="icp.role" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="CEO / Head of Sales" />
          </div>
          <div>
            <label class="block text-xs text-gray-900 mb-1 font-medium">Default Funnel</label>
            <select v-model="icp.default_funnel" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900">
              <option value="ToFu">ToFu (Bewusstsein)</option>
              <option value="MoFu">MoFu (Überlegung)</option>
              <option value="BoFu">BoFu (Entscheidung)</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-xs text-gray-900 mb-1 font-medium">Beschreibung</label>
          <textarea v-model="icp.description" rows="2" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Wer ist das? 2-3 Sätze."></textarea>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-gray-700 mb-1 font-medium">🔥 Pain Points</label>
            <div class="space-y-1.5 mb-1.5">
              <div v-for="(p, pi) in (icp.pain_points || [])" :key="pi" class="flex gap-2">
                <input v-model="icp.pain_points[pi]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="z.B. Blindflug im Forecast" />
                <button @click="icp.pain_points.splice(pi, 1)" class="text-red-500">✕</button>
              </div>
            </div>
            <button @click="icp.pain_points = [...(icp.pain_points || []), '']" class="text-xs text-green-600 font-medium">+ Pain Point</button>
          </div>
          <div>
            <label class="block text-xs text-gray-700 mb-1 font-medium">✅ Gains (was will der erreichen?)</label>
            <div class="space-y-1.5 mb-1.5">
              <div v-for="(g, gi) in (icp.gains || [])" :key="gi" class="flex gap-2">
                <input v-model="icp.gains[gi]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="z.B. Planbares Wachstum" />
                <button @click="icp.gains.splice(gi, 1)" class="text-red-500">✕</button>
              </div>
            </div>
            <button @click="icp.gains = [...(icp.gains || []), '']" class="text-xs text-green-600 font-medium">+ Gain</button>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-gray-900 mb-1 font-medium">Match-Keywords (Regex, Auto-Erkennung)</label>
            <input v-model="icp.match_keywords" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-mono" placeholder="forecast|pipeline|sales|head of sales" />
          </div>
          <div class="flex items-end gap-3 pb-1">
            <label class="text-xs text-gray-700 font-medium shrink-0">Priorität</label>
            <select v-model="icp.priority" class="bg-white border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-gray-900">
              <option value="high">🔴 Hoch</option>
              <option value="medium">🟡 Mittel</option>
              <option value="low">🟢 Niedrig</option>
            </select>
          </div>
        </div>
      </div>

      <button @click="addIcp" class="neu-btn-primary px-4 py-2 text-sm">+ ICP hinzufügen</button>
    </div>

    <div v-if="activeTab === 'media_logic'" class="space-y-5">
      <div class="flex justify-between items-center"><h3 class="text-sm font-semibold text-gray-900">Medien-Logik</h3><button @click="addMediaRule" class="neu-btn-primary px-4 py-2 text-sm">+ Regel</button></div>
      <div v-for="(rule, i) in (forms.media_logic.rules || [])" :key="i" class="bg-white border border-gray-200 rounded-xl p-5 grid grid-cols-2 gap-4">
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Format</label><select v-model="rule.format" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option value="image">Bild</option><option value="carousel">Karussell</option><option value="video">Video</option><option value="graphic">Grafik</option></select></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Seitenverhältnis</label><select v-model="rule.aspect_ratio" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option value="1:1">1:1</option><option value="1.91:1">1.91:1</option><option value="4:5">4:5</option><option value="9:16">9:16</option><option value="16:9">16:9</option></select></div>
        <div class="col-span-2"><label class="block text-sm text-gray-900 mb-1 font-medium">Stil</label><input v-model="rule.style" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Dunkel, reduziert, Brand Colors" /></div>
        <div class="col-span-2"><label class="block text-sm text-gray-900 mb-1 font-medium">Notizen</label><input v-model="rule.notes" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Kein Text-Overlay" /></div>
        <button @click="forms.media_logic.rules.splice(i, 1)" class="col-span-2 text-red-500 text-sm hover:text-red-700">Regel entfernen</button>
      </div>
    </div>

    <div v-if="activeTab === 'editorial_rhythm'" class="space-y-5">
      <div class="flex justify-between items-center"><h3 class="text-sm font-semibold text-gray-900">Redaktions-Rhythmus</h3><button @click="addEditorialSlot" class="neu-btn-primary px-4 py-2 text-sm">+ Slot</button></div>
      <div class="mb-4"><label class="block text-sm text-gray-900 mb-1 font-medium">Grund-Kadenz</label><select v-model="forms.editorial_rhythm.cadence" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option value="daily">Täglich</option><option value="weekly">Wöchentlich</option><option value="biweekly">2× Woche</option></select></div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-for="(slot, i) in (forms.editorial_rhythm.slots || [])" :key="i" class="bg-white border border-gray-200 rounded-lg p-3">
          <div class="grid grid-cols-2 gap-2 mb-2"><select v-model="slot.day" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900"><option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option><option>Friday</option></select><select v-model="slot.channel" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900"><option>linkedin</option><option>newsletter</option><option>blog</option></select></div>
          <div class="grid grid-cols-2 gap-2"><input v-model="slot.format" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900" placeholder="Format" /><input v-model="slot.persona" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900" placeholder="Persona" /></div>
          <button @click="forms.editorial_rhythm.slots.splice(i, 1)" class="text-xs text-red-500 mt-2">Entfernen</button>
        </div>
      </div>
    </div>

    <div v-if="activeTab === 'content_strategy'" class="space-y-5">
      <div class="bg-white border border-gray-200 rounded-xl p-6">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Content-Strategie</h3>
        <div class="mb-4"><label class="block text-sm text-gray-900 mb-1 font-medium">Strategische Ziele</label><textarea v-model="forms.content_strategy.goals" rows="3" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="Was wollen wir mit Content erreichen?"></textarea></div>
        <div>
          <div class="flex justify-between mb-2"><label class="text-sm text-gray-700">Content-Pillars</label><button @click="addPillar" class="text-sm text-green-600 font-medium">+ Pillar</button></div>
          <div class="space-y-3">
            <div v-for="(pillar, i) in (forms.content_strategy.pillars || [])" :key="i" class="bg-white border border-gray-200 rounded-xl p-5">
              <div class="flex gap-2 mb-2"><input v-model="pillar.name" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Pillar-Name" /><button @click="forms.content_strategy.pillars.splice(i, 1)" class="text-red-500">✕</button></div>
              <textarea v-model="pillar.description" rows="2" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 mb-2" placeholder="Beschreibung"></textarea>
              <div class="flex flex-wrap gap-2"><label v-for="icp in ['B2B-1', 'B2B-2', 'B2B-3', 'B2B-4']" :key="icp" class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" :value="icp" v-model="pillar.icp_focus" class="rounded border-gray-300 text-green-600 focus:ring-2 focus:ring-green-500/20" /> {{ icp }}</label></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="activeTab === 'post_templates'" class="space-y-5">

      <!-- Katalog zum Auswählen -->
      <div class="bg-white border border-gray-200 rounded-xl p-5">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="text-sm font-semibold text-gray-900">Template-Katalog</h3>
            <p class="text-xs text-gray-500 mt-0.5">Klick = Auswählen/Abwählen. Rechtsklick auf Name = Details + KI-Beispiel.</p>
          </div>
          <select v-model="templateFilter" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-gray-900">
            <option value="">Alle Formate</option>
            <option value="linkedin_post">LinkedIn Post</option>
            <option value="ad_copy">Ad Copy</option>
            <option value="newsletter_bk">Newsletter BK</option>
            <option value="newsletter_acquisition">Newsletter Acquisition</option>
            <option value="landing_page_headlines">Landing Page</option>
          </select>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div v-for="tpl in filteredTemplates" :key="tpl.name"
            class="border border-gray-200 rounded-lg p-3 hover:border-green-300 hover:shadow-sm transition-all cursor-pointer"
            :class="isTemplateSelected(tpl.name) ? 'ring-2 ring-green-400 border-green-400 bg-green-50/30' : ''">
            <div class="flex items-center justify-between mb-1">
              <span class="text-sm font-medium text-gray-900 hover:text-green-700" @click.stop="openDetail(tpl)">{{ tpl.name }}</span>
              <span class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">{{ tpl.format }}</span>
            </div>
            <p class="text-xs text-gray-500 mb-2" @click="toggleTemplate(tpl)">{{ tpl.description }}</p>
            <div class="flex flex-wrap gap-1 items-center" @click="toggleTemplate(tpl)">
              <span v-for="icp in (tpl.best_for || [])" :key="icp" class="text-xs bg-green-50 text-green-700 px-1.5 py-0.5 rounded">{{ icp }}</span>
              <span v-if="isTemplateSelected(tpl.name)" class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full ml-auto font-medium">✓ Ausgewählt</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Template-Detail-Modal -->
    <div v-if="detailModal" class="fixed inset-0 z-50 flex items-center justify-center" @click.self="closeDetail">
      <div class="fixed inset-0 bg-black/40"></div>
      <div class="relative bg-white rounded-2xl shadow-xl p-6 w-full max-w-2xl mx-4 z-10 max-h-[80vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
          <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ detailModal.name }}</h3>
            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{{ detailModal.format }}</span>
          </div>
          <button @click="closeDetail" class="text-gray-400 hover:text-gray-900 text-xl">✕</button>
        </div>

        <div class="grid grid-cols-2 gap-6 mb-5">
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Struktur</label>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700 whitespace-pre-line font-mono">{{ detailModal.structure }}</div>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Beispiel</label>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700 whitespace-pre-line mb-3 min-h-[100px]">
              <template v-if="modalGeneratedExample">{{ modalGeneratedExample }}</template>
              <template v-else>{{ detailModal.example }}</template>
            </div>
            <div class="flex items-center gap-3">
              <button @click="generateExampleForModal(detailModal)" :disabled="modalGenerating"
                class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50">
                {{ modalGenerating ? 'Generiert…' : '🤖 Beispiel generieren' }}
              </button>
              <button v-if="modalGeneratedExample" @click="modalGeneratedExample = ''"
                class="text-xs text-gray-500 underline">Zurücksetzen</button>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3 pt-3 border-t border-gray-200">
          <button @click="toggleTemplate(detailModal); closeDetail()"
            class="px-4 py-2 text-sm rounded-lg font-medium"
            :class="isTemplateSelected(detailModal.name) ? 'bg-red-50 text-red-600 border border-red-200 hover:bg-red-100' : 'bg-green-600 text-white hover:bg-green-700'">
            {{ isTemplateSelected(detailModal.name) ? '✕ Aus Strategie entfernen' : '+ In Strategie übernehmen' }}
          </button>
          <button @click="closeDetail" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">Schließen</button>
        </div>
      </div>
    </div>
    <!-- Personas: globale Personas dieser Strategie zuordnen (nicht anlegen) -->
    <div v-if="activeTab === 'content_personas'" class="space-y-5">
      <div class="flex justify-between items-center">
        <div>
          <h3 class="text-sm font-semibold text-gray-900">Personas für diese Strategie</h3>
          <p class="text-xs text-gray-500 mt-1">Personas sind global. Hier ordnest du sie der Strategie zu und definierst die relevanten Angles & Themen-Cluster.</p>
        </div>
        <button @click="openMapModal" class="neu-btn-primary px-4 py-2 text-sm" :disabled="!allPersonas.length">+ Persona zuordnen</button>
      </div>

      <div v-if="mapLoading" class="text-sm text-gray-400">Lade Persona-Mapping…</div>

      <div v-else-if="!mappedPersonas.length" class="bg-white border border-gray-200 rounded-xl p-8 text-center text-sm text-gray-500">
        Keine Personas dieser Strategie zugeordnet.
        <button @click="openMapModal" class="text-green-600 underline ml-1" :disabled="!allPersonas.length">Erste zuordnen</button>
      </div>

      <div v-else class="space-y-3">
        <div v-for="p in mappedPersonas" :key="p.id" class="bg-white border border-gray-200 rounded-xl p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <span class="text-gray-900 font-medium">{{ p.name }}</span>
              <span v-if="p.role" class="text-xs text-gray-500">{{ p.role }}</span>
              <span v-if="p.pivot?.is_default" class="text-xs bg-green-50 text-green-700 border border-green-200 px-2 py-0.5 rounded-full">Default</span>
            </div>
            <button @click="detachPersona(p.id)" class="text-xs text-red-500 hover:text-red-700">Trennen</button>
          </div>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Relevante Angles (diese Strategie)</label>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="(a, i) in (p.pivot?.mapped_angles || [])" :key="i" class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">{{ a.text || a }}</span>
                <span v-if="!(p.pivot?.mapped_angles || []).length" class="text-xs text-gray-400 italic">Keine spezifischen Angles</span>
              </div>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Themen-Cluster (diese Strategie)</label>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="(t, i) in (p.pivot?.mapped_topics || [])" :key="i" class="text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded">{{ t }}</span>
                <span v-if="!(p.pivot?.mapped_topics || []).length" class="text-xs text-gray-400 italic">Keine spezifischen Themen</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Mapping-Modal -->
      <div v-if="showMapModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="fixed inset-0 bg-black/40" @click="showMapModal = false"></div>
        <div class="relative bg-white rounded-2xl shadow-xl p-6 w-full max-w-lg mx-4 z-10">
          <h3 class="text-lg font-semibold text-gray-900 mb-4">Persona dieser Strategie zuordnen</h3>
          <div class="space-y-4">
            <div>
              <label class="block text-sm text-gray-900 mb-1 font-medium">Persona</label>
              <select v-model="mapForm.persona_id" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900">
                <option value="" disabled>Persona wählen…</option>
                <option v-for="p in allPersonas" :key="p.id" :value="p.id" :disabled="mappedPersonas.some(m => m.id === p.id)">
                  {{ p.name }}{{ mappedPersonas.some(m => m.id === p.id) ? ' (bereits zugeordnet)' : '' }}
                </option>
              </select>
            </div>
            <div>
              <label class="block text-sm text-gray-900 mb-1 font-medium">Relevante Angles (optional)</label>
              <div class="space-y-1.5 mb-1">
                <div v-for="(a, i) in mapForm.angles" :key="i" class="flex gap-2">
                  <input v-model="mapForm.angles[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Angle-Text" />
                  <button @click="mapForm.angles.splice(i, 1)" class="text-red-500">✕</button>
                </div>
              </div>
              <button @click="mapForm.angles.push('')" class="text-sm text-green-600 font-medium">+ Angle</button>
            </div>
            <div>
              <label class="block text-sm text-gray-900 mb-1 font-medium">Themen-Cluster (optional)</label>
              <div class="space-y-1.5 mb-1">
                <div v-for="(t, i) in mapForm.topic_clusters" :key="i" class="flex gap-2">
                  <input v-model="mapForm.topic_clusters[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Thema" />
                  <button @click="mapForm.topic_clusters.splice(i, 1)" class="text-red-500">✕</button>
                </div>
              </div>
              <button @click="mapForm.topic_clusters.push('')" class="text-sm text-green-600 font-medium">+ Thema</button>
            </div>
          </div>
          <div class="flex gap-3 mt-5">
            <button @click="attachPersona" :disabled="!mapForm.persona_id" class="neu-btn-primary px-4 py-2 text-sm disabled:opacity-40">Zuordnen</button>
            <button @click="showMapModal = false" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">Abbrechen</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="activeTab !== 'content_personas' && activeTab !== 'ki_settings' && activeTab !== 'icp_definitions'" class="mt-6 flex items-center gap-3 pt-4 border-t border-gray-200">
      <button @click="save" :disabled="saving" class="neu-btn-primary px-4 py-2 text-sm">{{ saving ? 'Speichert...' : '💾 Strategie speichern' }}</button>
      <span v-if="saved" class="text-sm text-green-600">✓ Gespeichert!</span>
    </div>
    </template>

    <div v-else class="bg-white border border-gray-200 rounded-xl p-10 text-center">
      <p class="text-4xl mb-3">🧠</p>
      <h2 class="text-lg font-medium text-gray-900 mb-1">Noch keine Strategie vorhanden</h2>
      <p class="text-sm text-gray-600 mb-4">Lege oben mit „+ Neue Strategie“ deine erste Content-Strategie an.</p>
      <button @click="showNewStrategy = true" class="neu-btn-primary px-4 py-2 text-sm">+ Neue Strategie</button>
    </div>
  </AppLayout>
</template>