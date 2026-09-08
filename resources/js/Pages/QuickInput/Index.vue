<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array });

const mode = ref('text');
const loading = ref(false);
const result = ref(null);
const approving = ref(false);
const approveMsg = ref('');

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const form = reactive({
    content: '', title: '',
    strategy: props.strategies?.[0]?.key || 'viscale',
    batch_key: '', num_angles: 5,
});

const urlForm = reactive({
    url: '', title: '',
    strategy: props.strategies?.[0]?.key || 'viscale',
    batch_key: '',
    monitor: false, frequency: 'weekly',
});

const pdfForm = reactive({
    file: null, title: '',
    strategy: props.strategies?.[0]?.key || 'viscale',
    batch_key: '',
});

// ─── Draft-Management ───
const drafts = ref([]);            // [{ angle, icp, pain_cluster, statement_type, selected }]

function setDrafts(list) {
    drafts.value = (list || []).map(d => ({
        angle: d.angle || '',
        icp: d.icp || '',
        pain_cluster: d.pain_cluster || '',
        statement_type: d.statement_type || '',
        selected: true,
    }));
}

function selectAll(v) { drafts.value.forEach(d => d.selected = v); }
const selectedCount = () => drafts.value.filter(d => d.selected).length;

// ─── Enhance via Assistant ───
async function enhanceWithAssistant(idx) {
    const draft = drafts.value[idx];
    const prompt = `Verbessere diesen Content-Angle: "${draft.angle}".\nMache ihn schärfer und präziser (max 200 Zeichen), ICP: ${draft.icp || 'unbekannt'}.\nAntworte NUR mit dem verbesserten Angle-Text.`;
    try {
        await navigator.clipboard.writeText(prompt);
        draft.copied = true;
        setTimeout(() => draft.copied = false, 2500);
    } catch {}
    document.dispatchEvent(new CustomEvent('assistant:open', { detail: { prompt } }));
}

// ─── Approve: selektierte Drafts in die angles-Tabelle ───
async function approveDrafts() {
    if (!selectedCount() || approving.value) return;
    approving.value = true;
    approveMsg.value = '';
    const stratKey = result.value?.source?.strategy?.key
        || form.strategy || urlForm.strategy || pdfForm.strategy;
    try {
        const res = await fetch('/api/angles/batch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                strategy: stratKey,
                source_id: result.value?.source?.id || null,
                batch_key: result.value?.source?.batch_key || null,
                angles: drafts.value.filter(d => d.selected && d.angle.trim()).map(d => ({
                    angle: d.angle.trim(),
                    icp: d.icp || null,
                    pain_cluster: d.pain_cluster || null,
                    statement_type: d.statement_type || null,
                })),
            }),
        });
        const data = await res.json();
        if (res.ok && data.created) {
            approveMsg.value = `✅ ${data.created} Angles übernommen.`;
            result.value.approved = true;
        } else {
            approveMsg.value = '⚠️ ' + (data.message || 'Fehler beim Übernehmen.');
        }
    } catch (e) {
        approveMsg.value = '⚠️ ' + e.message;
    }
    approving.value = false;
}

// ─── Submit Handlers ───
async function submitText() {
    loading.value = true; result.value = null; drafts.value = [];
    try {
        const res = await fetch('/api/quick-input', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ ...form }),
        });
        const data = await res.json();
        result.value = data;
        setDrafts(data.drafts);
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { loading.value = false; }
}

async function submitUrl() {
    if (!urlForm.url.trim()) return;
    loading.value = true; result.value = null; drafts.value = [];
    try {
        const res = await fetch('/api/quick-input', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                content: urlForm.url,
                title: urlForm.title || urlForm.url,
                strategy: urlForm.strategy,
                batch_key: urlForm.batch_key,
                type: 'url',
                monitor: urlForm.monitor,
                frequency: urlForm.frequency,
                num_angles: 5,
            }),
        });
        const data = await res.json();
        result.value = data;
        setDrafts(data.drafts);
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { loading.value = false; }
}

async function submitPdf() {
    if (!pdfForm.file) { alert('Bitte Datei auswählen'); return; }
    loading.value = true; result.value = null; drafts.value = [];
    try {
        const fd = new FormData();
        fd.append('file', pdfForm.file); fd.append('title', pdfForm.title);
        fd.append('strategy', pdfForm.strategy); fd.append('batch_key', pdfForm.batch_key);
        fd.append('num_angles', '5');
        const res = await fetch('/api/quick-input', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() }, body: fd });
        const data = await res.json();
        result.value = data;
        setDrafts(data.drafts);
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { loading.value = false; }
}

function onFileChange(e) { pdfForm.file = e.target.files[0]; }
const typeIcons = { blog: '📝', linkedin: '💼', url: '🔗', pdf: '📄', note: '📌', quote: '💬', interview: '🎤' };
</script>

<template>
  <AppLayout>
    <div class="max-w-4xl">
      <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Quick Input</h1>
        <p class="text-sm text-gray-600 mt-1">Text, PDF oder URL einfügen → Angles finden, verbessern und erst nach Freigabe übernehmen</p>
      </div>

      <div class="flex gap-1 mb-6">
        <button @click="mode='text'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='text'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">Text / Artikel</button>
        <button @click="mode='pdf'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='pdf'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">PDF / Datei</button>
        <button @click="mode='url'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='url'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">URL</button>
      </div>

      <!-- TEXT -->
      <div v-if="mode==='text'" class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Titel (optional)</label><input v-model="form.title" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500" placeholder="z.B. HubSpot CRM Trends 2026" /></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label><select v-model="form.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select></div>
        </div>
        <div>
          <label class="block text-sm text-gray-900 mb-1 font-medium">Text einfügen</label>
          <textarea v-model="form.content" rows="12" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-mono focus:outline-none focus:border-green-500" placeholder="Blog-Artikel, LinkedIn-Post, Notizen oder Kundenzitat hier einfügen..."></textarea>
          <p class="text-xs text-gray-500 mt-1">{{ form.content.length }} Zeichen</p>
        </div>
        <div class="flex flex-col md:flex-row md:items-center gap-4">
          <div class="flex-1"><label class="block text-sm text-gray-900 mb-1 font-medium">Batch-Key (optional)</label><input v-model="form.batch_key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="crm-trends-2026" /></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Max Angles</label><input v-model.number="form.num_angles" type="number" min="1" max="10" class="w-16 bg-white border border-gray-300 rounded-lg px-2 py-2 text-sm text-gray-900" /></div>
        </div>
        <button @click="submitText" :disabled="loading || form.content.length < 10" class="neu-btn-primary px-4 py-2 text-sm">{{ loading ? 'Verarbeite...' : 'Angles finden' }}</button>
      </div>

      <!-- PDF -->
      <div v-if="mode==='pdf'" class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Titel (optional)</label><input v-model="pdfForm.title" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Dateiname wenn leer" /></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label><select v-model="pdfForm.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select></div>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-2">Datei auswählen</label>
          <label class="flex items-center justify-center w-full h-40 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-green-500 transition-colors bg-gray-50" :class="pdfForm.file?'border-green-500 bg-green-50':''">
            <input type="file" @change="onFileChange" accept=".pdf,.txt,.md,.doc,.docx" class="hidden" />
            <div class="text-center">
              <span class="text-4xl mb-2 block">📄</span>
              <p class="text-sm text-gray-600">{{ pdfForm.file ? pdfForm.file.name : 'PDF, TXT, MD, DOC auswählen' }}</p>
              <p v-if="pdfForm.file" class="text-xs text-gray-500 mt-1">{{ (pdfForm.file.size / 1024).toFixed(1) }} KB</p>
            </div>
          </label>
        </div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Batch-Key (optional)</label><input v-model="pdfForm.batch_key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="whitepaper-2026" /></div>
        <button @click="submitPdf" :disabled="loading || !pdfForm.file" class="neu-btn-primary px-4 py-2 text-sm">{{ loading ? 'Verarbeite...' : 'Datei analysieren' }}</button>
      </div>

      <!-- URL -->
      <div v-if="mode==='url'" class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
        <div class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-3">
          <span class="text-lg shrink-0">🌐</span>
          <div>
            <p class="text-sm font-medium text-blue-800">URL scrapen</p>
            <p class="text-xs text-blue-600 mt-0.5">Seiteninhalt wird geladen und analysiert. Mit „Auto-Monitoring" wird die Seite regelmäßig erneut geprüft.</p>
          </div>
        </div>
        <div>
          <label class="block text-sm text-gray-900 mb-1 font-medium">URL *</label>
          <input v-model="urlForm.url" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500" placeholder="https://example.com/artikel" @keydown.enter="submitUrl" />
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-900 mb-1 font-medium">Titel (optional)</label>
            <input v-model="urlForm.title" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Wird aus der Seite gelesen wenn leer" />
          </div>
          <div>
            <label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label>
            <select v-model="urlForm.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select>
          </div>
        </div>
        <div>
          <label class="block text-sm text-gray-900 mb-1 font-medium">Batch-Key (optional)</label>
          <input v-model="urlForm.batch_key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="z.B. wettbewerber-2026" />
        </div>

        <!-- Auto-Monitoring -->
        <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
          <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" v-model="urlForm.monitor" class="mt-1 rounded border-gray-300 text-green-600 focus:ring-green-500" />
            <span>
              <span class="block text-sm font-medium text-gray-900">📡 Auto-Monitoring aktivieren</span>
              <span class="block text-xs text-gray-500 mt-0.5">Diese Quelle regelmäßig crawlen — bei Änderungen entstehen automatisch neue Angles.</span>
            </span>
          </label>
          <div v-if="urlForm.monitor" class="mt-3 flex items-center gap-2">
            <label class="text-xs text-gray-500">Frequenz:</label>
            <select v-model="urlForm.frequency" class="bg-white border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-gray-900">
              <option value="daily">Täglich</option>
              <option value="weekly">Wöchentlich</option>
              <option value="biweekly">Alle 2 Wochen</option>
            </select>
          </div>
        </div>

        <button @click="submitUrl" :disabled="loading || urlForm.url.length < 4" class="neu-btn-primary px-4 py-2 text-sm disabled:opacity-50">{{ loading ? '🌐 Scraping läuft…' : '🌐 URL laden & analysieren' }}</button>
      </div>

      <!-- Result: Draft-Angles zur Freigabe -->
      <div v-if="result" class="bg-white border border-gray-200 rounded-xl p-6 mt-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-gray-900 font-semibold">{{ result.approved ? 'Übernommene Angles' : 'Gefundene Angles' }}</h3>
          <div class="flex items-center gap-2">
            <span v-if="result.scraped" class="text-xs bg-blue-50 text-blue-600 border border-blue-200 px-2 py-0.5 rounded-full">🌐 URL gescraped</span>
            <span v-if="result.content_length" class="text-xs text-gray-400">{{ result.content_length.toLocaleString() }} Zeichen</span>
          </div>
        </div>

        <div v-if="result.error" class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
          <p class="text-sm text-red-700">{{ result.error }}</p>
        </div>

        <div v-if="result.source" class="mb-4">
          <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-lg">{{ typeIcons[result.source.type] || '📄' }}</span>
              <span class="text-gray-900 font-medium">{{ result.source.title }}</span>
              <span v-if="result.source.monitor" class="text-xs bg-green-50 text-green-700 border border-green-200 px-2 py-0.5 rounded-full">📡 Auto-Monitoring</span>
            </div>
            <p class="text-xs text-gray-500">ID: {{ result.source.id }} · Batch: {{ result.source.batch_key }}</p>
          </div>
        </div>

        <!-- Drafts -->
        <div v-if="drafts.length" class="space-y-3">
          <div class="flex items-center justify-between">
            <p class="text-sm text-gray-600">{{ drafts.length }} Angle-Kandidaten — vor der Übernahme prüfen & verbessern</p>
            <div class="flex gap-2">
              <button @click="selectAll(true)" class="text-xs text-gray-500 hover:text-green-600">Alle wählen</button>
              <button @click="selectAll(false)" class="text-xs text-gray-500 hover:text-gray-600">Keine</button>
            </div>
          </div>

          <div v-for="(draft, idx) in drafts" :key="idx" class="border border-gray-200 rounded-lg p-3" :class="draft.selected ? 'border-green-300 bg-green-50/30' : 'opacity-60'">
            <div class="flex items-start gap-3">
              <input type="checkbox" v-model="draft.selected" class="mt-1 rounded border-gray-300 text-green-600" />
              <div class="flex-1">
                <textarea v-model="draft.angle" rows="2" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-900 focus:outline-none focus:border-green-500"></textarea>
                <div class="flex flex-wrap gap-2 mt-1.5">
                  <input v-model="draft.icp" class="w-20 text-xs px-1.5 py-0.5 rounded bg-green-50 text-green-700 border border-green-200" placeholder="ICP" />
                  <input v-model="draft.pain_cluster" class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-700 border border-gray-200 flex-1" placeholder="Pain-Cluster" />
                  <span v-if="draft.statement_type" class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-700">{{ draft.statement_type }}</span>
                </div>
              </div>
              <button @click="enhanceWithAssistant(idx)" class="text-xs px-2 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 whitespace-nowrap" :title="'Über Assistant verbessern'">
                {{ draft.copied ? '✓ Prompt kopiert' : '🤖 Verbessern' }}
              </button>
            </div>
          </div>

          <!-- Approve -->
          <div class="pt-3 border-t border-gray-100 flex items-center gap-3">
            <button @click="approveDrafts" :disabled="approving || !selectedCount() || result.approved"
              class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
              {{ result.approved ? '✓ Übernommen' : '✅ ' + selectedCount() + ' Angles übernehmen' }}
            </button>
            <span v-if="approveMsg" class="text-sm" :class="result.approved ? 'text-green-600' : 'text-gray-600'">{{ approveMsg }}</span>
          </div>
        </div>

        <div v-else-if="result.source && !result.error" class="text-sm text-gray-500 italic mt-2">
          Keine Angles extrahiert — zu wenig Textinhalt.
        </div>
      </div>
    </div>
  </AppLayout>
</template>