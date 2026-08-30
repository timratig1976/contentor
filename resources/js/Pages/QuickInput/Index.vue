<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array });

const mode = ref('text');
const loading = ref(false);
const result = ref(null);

const form = reactive({
    content: '', title: '',
    strategy: props.strategies?.[0]?.key || 'viscale',
    batch_key: '', create_angles: true, num_angles: 5,
});

const pdfForm = reactive({ file: null, title: '', strategy: props.strategies?.[0]?.key || 'viscale', batch_key: '' });

async function submitText() {
    loading.value = true; result.value = null;
    try {
        const res = await fetch('/api/quick-input', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ ...form }),
        });
        result.value = await res.json();
    } catch (e) { alert('Fehler: ' + e.message); }
    finally { loading.value = false; }
}

async function submitPdf() {
    if (!pdfForm.file) { alert('Bitte PDF-Datei auswählen'); return; }
    loading.value = true; result.value = null;
    try {
        const fd = new FormData();
        fd.append('file', pdfForm.file); fd.append('title', pdfForm.title);
        fd.append('strategy', pdfForm.strategy); fd.append('batch_key', pdfForm.batch_key);
        fd.append('create_angles', '1');
        const res = await fetch('/api/quick-input', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: fd });
        result.value = await res.json();
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
        <p class="text-sm text-gray-600 mt-1">Text, PDF oder URL einfügen → automatisch Quellen & Angles erstellen</p>
      </div>

      <div class="flex gap-1 mb-6">
        <button @click="mode='text'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='text'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">Text / Artikel</button>
        <button @click="mode='pdf'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='pdf'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">PDF / Datei</button>
        <button @click="mode='url'" class="px-4 py-2 rounded-lg text-sm font-medium" :class="mode==='url'?'bg-white border border-gray-200 text-gray-900 shadow-sm':'text-gray-500 hover:text-gray-900'">URL</button>
      </div>

      <!-- TEXT -->
      <div v-if="mode==='text'" class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Titel (optional)</label><input v-model="form.title" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="z.B. HubSpot CRM Trends 2026" /></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label><select v-model="form.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select></div>
        </div>
        <div>
          <label class="block text-sm text-gray-900 mb-1 font-medium">Text einfügen</label>
          <textarea v-model="form.content" rows="12" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-mono focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="Blog-Artikel, LinkedIn-Post, Notizen oder Kundenzitat hier einfügen..."></textarea>
          <p class="text-xs text-gray-500 mt-1">{{ form.content.length }} Zeichen · Auto-Erkennung: Blog / LinkedIn / Zitat / Notiz</p>
        </div>
        <div class="flex flex-col md:flex-row md:items-center gap-4">
          <div class="flex-1"><label class="block text-sm text-gray-900 mb-1 font-medium">Batch-Key (optional)</label><input v-model="form.batch_key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="crm-trends-2026" /></div>
          <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" v-model="form.create_angles" class="rounded border-gray-300 text-green-600 focus:ring-2 focus:ring-green-500/20" /> Angles extrahieren</label>
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">Max</label><input v-model.number="form.num_angles" type="number" min="1" max="10" class="w-16 bg-white border border-gray-300 rounded-lg px-2 py-2 text-sm text-gray-900" /></div>
          </div>
        </div>
        <button @click="submitText" :disabled="loading || form.content.length < 10" class="neu-btn-primary px-4 py-2 text-sm">{{ loading ? 'Verarbeite...' : 'Verarbeiten' }}</button>
      </div>

      <!-- PDF -->
      <div v-if="mode==='pdf'" class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Titel (optional)</label><input v-model="pdfForm.title" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="Dateiname wenn leer" /></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label><select v-model="pdfForm.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select></div>
        </div>
        <div>
          <label class="block text-sm text-gray-700 mb-2">Datei auswählen</label>
          <label class="flex items-center justify-center w-full h-40 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-green-500 transition-colors bg-gray-50"
            :class="pdfForm.file?'border-green-500 bg-green-50':''">
            <input type="file" @change="onFileChange" accept=".pdf,.txt,.md,.doc,.docx" class="hidden" />
            <div class="text-center">
              <span class="text-4xl mb-2 block">📄</span>
              <p class="text-sm text-gray-600">{{ pdfForm.file ? pdfForm.file.name : 'PDF, TXT, MD, DOC auswählen' }}</p>
              <p v-if="pdfForm.file" class="text-xs text-gray-500 mt-1">{{ (pdfForm.file.size / 1024).toFixed(1) }} KB</p>
            </div>
          </label>
        </div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Batch-Key (optional)</label><input v-model="pdfForm.batch_key" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="whitepaper-2026" /></div>
        <button @click="submitPdf" :disabled="loading || !pdfForm.file" class="neu-btn-primary px-4 py-2 text-sm">{{ loading ? 'Verarbeite...' : 'PDF verarbeiten' }}</button>
      </div>

      <!-- URL -->
      <div v-if="mode==='url'" class="bg-white border border-gray-200 rounded-xl p-6 space-y-5">
        <p class="text-sm text-gray-600">Gib eine URL ein — der Agent recherchiert den Inhalt automatisch.</p>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">URL</label><input v-model="form.content" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="https://..." /></div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Titel</label><input v-model="form.title" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /></div>
          <div><label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label><select v-model="form.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select></div>
        </div>
        <button @click="submitText" :disabled="loading || form.content.length < 5" class="neu-btn-primary px-4 py-2 text-sm">{{ loading ? 'Verarbeite...' : 'Verarbeiten' }}</button>
      </div>

      <!-- Result -->
      <div v-if="result" class="bg-white border border-gray-200 rounded-xl p-6 mt-6">
        <h3 class="text-gray-900 font-semibold mb-4">Ergebnis</h3>
        <div v-if="result.source" class="mb-4">
          <p class="text-sm text-gray-600 mb-2">Quelle erstellt:</p>
          <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
            <div class="flex items-center gap-2 mb-1"><span class="text-lg">{{ typeIcons[result.source.type] || '📄' }}</span><span class="text-gray-900 font-medium">{{ result.source.title }}</span></div>
            <p class="text-xs text-gray-500">ID: {{ result.source.id }} · Batch: {{ result.source.batch_key }}</p>
          </div>
        </div>
        <div v-if="result.angles?.length">
          <p class="text-sm text-gray-600 mb-2">Extrahierte Angles:</p>
          <div class="space-y-2">
            <div v-for="angle in result.angles" :key="angle.id" class="bg-gray-50 border border-gray-200 rounded-lg p-3">
              <p class="text-sm text-gray-900">{{ angle.angle }}</p>
              <div class="flex gap-2 mt-1"><span v-if="angle.icp" class="text-xs px-1.5 py-0.5 rounded bg-green-50 text-green-700 border border-green-200">{{ angle.icp }}</span><span v-if="angle.statement_type" class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-700">{{ angle.statement_type }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>