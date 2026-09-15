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

const rssForm = reactive({
    url: '', title: '',
    strategy: props.strategies?.[0]?.key || 'viscale',
    frequency: 'daily',
});
const rssResult = ref(null);

const pdfForm = reactive({
    files: [], title: '',
    strategy: props.strategies?.[0]?.key || 'viscale',
    batch_key: '',
});
const dragging = ref(false);
const fileInput = ref(null);

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

// ─── An Assistant senden: Angle-Verbesserung im Assistant-Chat ausführen ───
function enhanceWithAssistant(idx) {
    const draft = drafts.value[idx];
    const strategyKey = result.value?.source?.strategy?.key || pdfForm.strategy || form.strategy || urlForm.strategy;
    const prompt = `Verbessere diesen Content-Angle im Kontext der Strategie "${strategyKey}": "${draft.angle}".\nMache ihn schärfer und präziser (max 200 Zeichen), ICP: ${draft.icp || 'unbekannt'}${draft.pain_cluster ? ', Pain-Cluster: ' + draft.pain_cluster : ''}.\nAntworte NUR mit dem verbesserten Angle-Text.`;
    // Assistant öffnen, Strategie-Kontext setzen und Prompt direkt senden
    document.dispatchEvent(new CustomEvent('assistant:open', {
        detail: { prompt, strategy: strategyKey, autoSend: true },
    }));
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
    const files = pdfForm.files.filter(f => f instanceof File);
    if (!files.length) { alert('Bitte zuerst eine oder mehrere Dateien auswählen.'); return; }
    loading.value = true; result.value = null; drafts.value = [];
    const allDrafts = [];
    const sources = [];
    const errors = [];
    try {
        for (const file of files) {
            const fd = new FormData();
            fd.append('file', file, file.name);
            fd.append('title', files.length === 1 ? (pdfForm.title || '') : '');
            fd.append('strategy', pdfForm.strategy);
            fd.append('batch_key', pdfForm.batch_key || '');
            fd.append('num_angles', '5');
            try {
                const res = await fetch('/api/quick-input', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() }, body: fd });
                const data = await res.json();
                if (!res.ok) {
                    const msg = data.error
                        || (data.errors ? Object.values(data.errors).flat().join(' ') : null)
                        || data.message
                        || `Fehler ${res.status}`;
                    errors.push(`${file.name}: ${msg}`);
                    continue;
                }
                if (data.source) sources.push(data.source);
                if (Array.isArray(data.drafts)) allDrafts.push(...data.drafts);
            } catch (e) {
                errors.push(`${file.name}: ${e.message}`);
            }
        }
        if (!sources.length && errors.length) {
            result.value = { error: errors.join(' · ') };
            return;
        }
        result.value = {
            source: sources[0] || null,
            sources,
            multi: sources.length > 1,
            error: errors.length ? errors.join(' · ') : null,
        };
        setDrafts(allDrafts);
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { loading.value = false; }
}

// ─── RSS-Feed als Quelle anlegen (sofort erster Fetch) ───
async function submitRss() {
    if (!rssForm.url.trim()) return;
    loading.value = true; rssResult.value = null; result.value = null; drafts.value = [];
    try {
        const res = await fetch('/api/sources', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                title: rssForm.title || rssForm.url,
                type: 'rss',
                strategy: rssForm.strategy,
                url: rssForm.url,
                monitor: true,
                frequency: rssForm.frequency,
            }),
        });
        const data = await res.json();
        if (res.ok) {
            rssResult.value = data;
        } else {
            rssResult.value = { error: data.message?.url?.[0] || data.message || 'Fehler beim Anlegen des Feeds. Bitte URL prüfen.' };
        }
    } catch (e) { rssResult.value = { error: 'Netzwerkfehler: ' + e.message }; }
    loading.value = false;
}

function onFileChange(e) {
    addFiles(e.target.files);
    e.target.value = ''; // gleiche Datei erneut wählbar machen
}
function onDrop(e) {
    dragging.value = false;
    addFiles(e.dataTransfer?.files);
}
function addFiles(fileList) {
    const incoming = Array.from(fileList || []);
    for (const f of incoming) {
        if (!pdfForm.files.some(x => x.name === f.name && x.size === f.size)) {
            pdfForm.files.push(f);
        }
    }
}
function removeFile(idx) { pdfForm.files.splice(idx, 1); }
function fmtSize(bytes) { return (bytes / 1024).toFixed(1) + ' KB'; }
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
        <button @click="mode='pdf'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='pdf'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">PDF / Datei / Bild</button>
        <button @click="mode='url'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='url'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">URL</button>
        <button @click="mode='rss'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='rss'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">📶 RSS-Feed</button>
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
          <label class="block text-sm text-gray-700 mb-2">Dateien auswählen <span class="text-gray-400 font-normal">(mehrere möglich)</span></label>
          <label
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
            class="flex items-center justify-center w-full min-h-40 border-2 border-dashed rounded-xl cursor-pointer transition-colors py-6"
            :class="[dragging ? 'border-green-500 bg-green-50' : (pdfForm.files.length ? 'border-green-400 bg-green-50/40' : 'border-gray-300 bg-gray-50 hover:border-green-500')]">
            <input ref="fileInput" type="file" multiple @change="onFileChange" accept=".pdf,.txt,.md,.doc,.docx,.png,.jpg,.jpeg,.webp" class="hidden" />
            <div class="text-center pointer-events-none">
              <span class="text-4xl mb-2 block">📄</span>
              <p class="text-sm text-gray-600">{{ dragging ? 'Loslassen zum Hinzufügen…' : 'Dateien hierher ziehen oder klicken' }}</p>
              <p class="text-xs text-gray-400 mt-1">PDF, TXT, MD, DOC oder Screenshot (PNG/JPG) — auch mehrere auf einmal</p>
            </div>
          </label>

          <!-- Ausgewählte Dateien -->
          <ul v-if="pdfForm.files.length" class="mt-3 space-y-1.5">
            <li v-for="(f, idx) in pdfForm.files" :key="idx" class="flex items-center justify-between bg-white border border-gray-200 rounded-lg px-3 py-2">
              <div class="flex items-center gap-2 min-w-0">
                <span class="text-base">📎</span>
                <span class="text-sm text-gray-900 truncate">{{ f.name }}</span>
                <span class="text-xs text-gray-400 shrink-0">{{ fmtSize(f.size) }}</span>
              </div>
              <button @click.prevent="removeFile(idx)" class="text-gray-400 hover:text-red-500 text-sm shrink-0 ml-2" title="Entfernen">✕</button>
            </li>
          </ul>
        </div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Batch-Key (optional)</label><input v-model="pdfForm.batch_key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="whitepaper-2026" /></div>
        <button @click="submitPdf" :disabled="loading || !pdfForm.files.length" class="neu-btn-primary px-4 py-2 text-sm disabled:opacity-50">{{ loading ? 'Verarbeite…' : (pdfForm.files.length > 1 ? pdfForm.files.length + ' Dateien analysieren' : 'Datei analysieren') }}</button>
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

      <!-- RSS-FEED -->
      <div v-if="mode==='rss'" class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
        <div class="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-lg p-3">
          <span class="text-lg shrink-0">📶</span>
          <div>
            <p class="text-sm font-medium text-blue-800">RSS-Feed als dauerhafte Quelle</p>
            <p class="text-xs text-blue-600 mt-0.5">Neue Artikel im Feed landen automatisch im Eingang (Quellen-Seite → Tab 📥 Eingang) — mit KI-extrahierten Angle-Vorschlägen zur Freigabe.</p>
          </div>
        </div>
        <div>
          <label class="block text-sm text-gray-900 mb-1 font-medium">Feed-URL *</label>
          <input v-model="rssForm.url" type="url" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500" placeholder="https://blog.hubspot.com/marketing/rss.xml" @keydown.enter="submitRss" />
          <p class="text-xs text-gray-500 mt-1">Typische Endungen: <span class="font-mono">/rss.xml</span>, <span class="font-mono">/feed</span>, <span class="font-mono">/atom.xml</span></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm text-gray-900 mb-1 font-medium">Titel (optional)</label>
            <input v-model="rssForm.title" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Wird aus dem Feed gelesen" />
          </div>
          <div>
            <label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label>
            <select v-model="rssForm.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select>
          </div>
          <div>
            <label class="block text-sm text-gray-900 mb-1 font-medium">Prüf-Frequenz</label>
            <select v-model="rssForm.frequency" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900">
              <option value="daily">Täglich (empfohlen)</option>
              <option value="weekly">Wöchentlich</option>
              <option value="biweekly">Alle 2 Wochen</option>
            </select>
          </div>
        </div>
        <button @click="submitRss" :disabled="loading || rssForm.url.length < 8" class="neu-btn-primary px-4 py-2 text-sm disabled:opacity-50">{{ loading ? '📶 Feed wird gelesen…' : '📶 Feed anlegen & sofort erste Artikel ziehen' }}</button>

        <!-- Ergebnis -->
        <div v-if="rssResult?.error" class="bg-red-50 border border-red-200 rounded-lg p-3">
          <p class="text-sm text-red-700">⚠️ {{ rssResult.error }}</p>
        </div>
        <div v-if="rssResult?.source" class="bg-green-50 border border-green-200 rounded-lg p-4 space-y-2">
          <p class="text-sm font-medium text-green-800">✅ Feed „{{ rssResult.source.title }}" angelegt</p>
          <p v-if="rssResult.first_fetch" class="text-xs text-green-700">
            Erster Durchlauf: <strong>{{ rssResult.first_fetch.items_new ?? 0 }}</strong> neue Artikel in der Warteschlange
            <span v-if="rssResult.first_fetch.items_total"> (von {{ rssResult.first_fetch.items_total }} im Feed)</span>
            <span v-if="rssResult.first_fetch.error" class="text-red-600"> — ⚠️ {{ rssResult.first_fetch.error }}</span>
          </p>
          <p class="text-xs text-green-600">
            👉 Weiter zu <a href="/quellen" class="underline font-medium">Quellen → Eingang</a>,
            um Angle-Entwürfe zu extrahieren und freizugeben.
          </p>
        </div>
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
              <button @click="enhanceWithAssistant(idx)" class="text-xs px-2 py-1 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 whitespace-nowrap" title="Angle im Assistant-Chat im Kontext der Strategie verbessern lassen">
                🤖 An Assistant senden
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