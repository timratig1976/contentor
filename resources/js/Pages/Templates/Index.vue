<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    strategies: Array,
    currentStrategy: Object,
    templates: Array,
    selected: Array,  // IDs der für diese Strategie aktiven Templates
    formats: Object,  // { linkedin_post: 'LinkedIn Post', ... }
});

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

// ─── Strategie wechseln ───
function switchStrategy(key) {
    router.get('/templates', { strategy: key }, { preserveState: false });
}

// ─── Auswahl pro Strategie ───
const selectedIds = reactive(new Set(props.selected || []));
const selectionSaving = ref(false);
const selectionSaved = ref(false);

function toggleSelected(id) {
    if (selectedIds.has(id)) selectedIds.delete(id);
    else selectedIds.add(id);
}
function isSelected(id) { return selectedIds.has(id); }
async function saveSelection() {
    selectionSaving.value = true; selectionSaved.value = false;
    await fetch('/api/post-templates/selection', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ strategy: props.currentStrategy.key, selected: [...selectedIds] }),
    });
    selectionSaving.value = false; selectionSaved.value = true;
    setTimeout(() => selectionSaved.value = false, 2000);
}

// ─── Gruppenansicht nach Format ───
const activeFormat = ref('');
const expandedGroups = reactive({});
const formatOrder = Object.keys(props.formats || {});

const grouped = computed(() => {
    const groups = {};
    for (const tpl of (props.templates || [])) {
        if (activeFormat.value && tpl.format !== activeFormat.value) continue;
        if (!groups[tpl.format]) groups[tpl.format] = [];
        groups[tpl.format].push(tpl);
    }
    return groups;
});

function toggleGroup(format) {
    expandedGroups[format] = !expandedGroups[format];
}
function isGroupExpanded(format) {
    return expandedGroups[format] !== false; // default: expanded
}

// ─── Detail-Panel ───
const detailTemplate = ref(null);
function openDetail(tpl) { detailTemplate.value = tpl; }
function closeDetail() { detailTemplate.value = null; exampleResult.value = ''; }

// ─── KI: Beispiel generieren für ein bestehendes Template ───
const exampleResult = ref('');
const generatingExample = ref(false);
async function generateExample(tpl) {
    if (!props.currentStrategy || generatingExample.value) return;
    generatingExample.value = true;
    const res = await fetch('/api/assistant/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({
            message: `Generiere ein konkretes Beispiel für das Template "${tpl.name}" (Format: ${tpl.format}) für die Strategie "${props.currentStrategy.name}". Template-Struktur: ${tpl.structure}. Gib NUR den fertigen Content-Text aus, keine Erklärungen.`,
            history: [],
        }),
    });
    const data = await res.json();
    exampleResult.value = data.reply || 'Keine Antwort.';
    generatingExample.value = false;
}

// ─── Manuelles Template hinzufügen ───
const showAddForm = ref(false);
const addForm = reactive({ name: '', format: 'linkedin_post', description: '', structure: '', example: '', best_for: [] });
const addSaving = ref(false);
async function saveNewTemplate() {
    if (!addForm.name || !addForm.structure) return;
    addSaving.value = true;
    const res = await fetch('/api/post-templates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ ...addForm }),
    });
    if (res.ok) {
        addSaving.value = false;
        showAddForm.value = false;
        Object.assign(addForm, { name: '', format: 'linkedin_post', description: '', structure: '', example: '', best_for: [] });
        router.reload();
    } else { addSaving.value = false; }
}

// ─── KI: Template aus Beispieltext identifizieren ───
const showIdentify = ref(false);
const identifyText = ref('');
const identifyFormat = ref('linkedin_post');
const identifyResult = reactive({});
const identifying = ref(false);
const identifySaving = ref(false);
async function identifyTemplate() {
    if (!identifyText.value.trim() || identifying.value) return;
    identifying.value = true;
    const res = await fetch('/api/post-templates/identify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ example: identifyText.value, format: identifyFormat.value }),
    });
    const data = await res.json();
    if (data.name) {
        Object.assign(identifyResult, data);
    } else {
        Object.assign(identifyResult, { error: data.error || 'Fehler beim Identifizieren.' });
    }
    identifying.value = false;
}
async function saveIdentifiedTemplate() {
    if (!identifyResult.name) return;
    identifySaving.value = true;
    const res = await fetch('/api/post-templates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ ...identifyResult }),
    });
    if (res.ok) {
        identifySaving.value = false;
        showIdentify.value = false;
        Object.assign(identifyResult, {});
        identifyText.value = '';
        router.reload();
    } else { identifySaving.value = false; }
}

// ─── Template löschen ───
async function deleteTemplate(id) {
    if (!confirm('Template endgültig löschen?')) return;
    await fetch(`/api/post-templates/${id}`, {
        method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf() },
    });
    if (detailTemplate.value?.id === id) closeDetail();
    router.reload();
}

// Format-Label Hilfsfunktion
function formatLabel(f) { return props.formats?.[f] || f; }
</script>

<template>
  <AppLayout>
    <div class="max-w-5xl">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
          <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">📝 Template-Katalog</h1>
          <select v-if="strategies?.length" :value="currentStrategy?.key" @change="switchStrategy($event.target.value)"
            class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-gray-900">
            <option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <button @click="showIdentify = !showIdentify" class="px-4 py-2 text-sm bg-violet-50 border border-violet-200 text-violet-700 rounded-lg hover:bg-violet-100 transition-colors">
            🤖 Template per KI erkennen
          </button>
          <button @click="showAddForm = !showAddForm" class="px-4 py-2 text-sm neu-btn-primary">
            + Template hinzufügen
          </button>
        </div>
      </div>

      <!-- Aktuelle Auswahl (Strategie) -->
      <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-3 mb-5 flex items-center justify-between">
        <div>
          <p class="text-sm text-amber-800 font-medium">Auswahl für <strong>{{ currentStrategy?.name }}</strong></p>
          <p class="text-xs text-amber-600 mt-0.5">{{ selectedIds.size }} von {{ templates?.length }} Templates aktiviert — klicke auf ✓/○ um die Auswahl zu ändern, dann „Auswahl speichern".</p>
        </div>
        <div class="flex items-center gap-3">
          <button @click="saveSelection" :disabled="selectionSaving" class="px-4 py-2 text-sm bg-amber-600 text-white rounded-lg hover:bg-amber-700 disabled:opacity-50">
            {{ selectionSaving ? 'Speichere…' : '💾 Auswahl speichern' }}
          </button>
          <span v-if="selectionSaved" class="text-xs text-green-600 font-medium">✓ Gespeichert</span>
        </div>
      </div>

      <!-- KI: Template identifizieren -->
      <div v-if="showIdentify" class="bg-violet-50 border border-violet-200 rounded-xl p-5 mb-5 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold text-violet-900">🤖 Template aus Beispieltext erkennen</h3>
          <button @click="showIdentify = false; Object.assign(identifyResult, {}); identifyText = ''" class="text-violet-400 hover:text-violet-700">✕</button>
        </div>
        <div class="grid grid-cols-4 gap-3 items-end">
          <div class="col-span-1">
            <label class="block text-xs text-violet-700 mb-1 font-medium">Format</label>
            <select v-model="identifyFormat" class="w-full bg-white border border-violet-300 rounded-lg px-3 py-2 text-sm text-gray-900">
              <option v-for="(label, key) in formats" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div class="col-span-3">
            <label class="block text-xs text-violet-700 mb-1 font-medium">Beispiel-Post einfügen (min. 20 Zeichen)</label>
            <textarea v-model="identifyText" rows="3" class="w-full bg-white border border-violet-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-violet-500 resize-none" placeholder="Füge einen echten Post ein — die KI erkennt das Muster dahinter und schlägt Struktur + Name vor."></textarea>
          </div>
        </div>
        <button @click="identifyTemplate" :disabled="identifying || identifyText.length < 20"
          class="px-4 py-2 bg-violet-600 text-white text-sm rounded-lg hover:bg-violet-700 disabled:opacity-50">
          {{ identifying ? 'Analysiere…' : '🔍 Muster erkennen' }}
        </button>

        <div v-if="identifyResult.name" class="bg-white border border-violet-200 rounded-xl p-5 space-y-3">
          <p class="text-xs font-semibold text-violet-700 uppercase tracking-wide">Erkanntes Muster</p>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Name</label>
              <input v-model="identifyResult.name" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-medium" />
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Beschreibung</label>
              <input v-model="identifyResult.description" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" />
            </div>
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">Struktur</label>
            <textarea v-model="identifyResult.structure" rows="4" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono text-gray-800 resize-y"></textarea>
          </div>
          <div class="flex items-center gap-3">
            <button @click="saveIdentifiedTemplate" :disabled="identifySaving" class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 disabled:opacity-50">
              {{ identifySaving ? 'Speichere…' : '💾 Als Template speichern' }}
            </button>
            <button @click="Object.assign(identifyResult, {})" class="text-sm text-gray-500 underline">Verwerfen</button>
          </div>
        </div>
        <p v-if="identifyResult.error" class="text-sm text-red-600">⚠️ {{ identifyResult.error }}</p>
      </div>

      <!-- Template manuell hinzufügen -->
      <div v-if="showAddForm" class="bg-white border border-gray-200 rounded-xl p-5 mb-5 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold text-gray-900">+ Neues Template</h3>
          <button @click="showAddForm = false" class="text-gray-400 hover:text-gray-700">✕</button>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-gray-500 mb-1">Name *</label>
            <input v-model="addForm.name" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="z.B. Insight Post" />
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">Format *</label>
            <select v-model="addForm.format" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900">
              <option v-for="(label, key) in formats" :key="key" :value="key">{{ label }}</option>
            </select>
          </div>
          <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Beschreibung</label>
            <input v-model="addForm.description" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Wofür eignet sich das Template?" />
          </div>
          <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Struktur * <span class="text-gray-400">(eine Zeile pro Schritt)</span></label>
            <textarea v-model="addForm.structure" rows="5" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono resize-y" placeholder="Hook&#10;Kontext&#10;Mechanismus&#10;Konsequenz&#10;Soft CTA"></textarea>
          </div>
          <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Beispiel (optional)</label>
            <textarea v-model="addForm.example" rows="3" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm resize-y" placeholder="Kurzer Beispieltext (erste Zeile/Satz reicht)"></textarea>
          </div>
        </div>
        <button @click="saveNewTemplate" :disabled="addSaving || !addForm.name || !addForm.structure"
          class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 disabled:opacity-50">
          {{ addSaving ? 'Speichere…' : '💾 Template speichern' }}
        </button>
      </div>

      <!-- Format-Filter -->
      <div class="flex gap-2 mb-5 overflow-x-auto">
        <button @click="activeFormat = ''"
          class="px-3 py-1.5 rounded-lg text-sm whitespace-nowrap font-medium transition-colors"
          :class="activeFormat === '' ? 'bg-white border border-gray-200 text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'">
          Alle ({{ templates?.length }})
        </button>
        <button v-for="(label, key) in formats" :key="key" @click="activeFormat = key"
          class="px-3 py-1.5 rounded-lg text-sm whitespace-nowrap font-medium transition-colors"
          :class="activeFormat === key ? 'bg-white border border-gray-200 text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'">
          {{ label }} ({{ (templates || []).filter(t => t.format === key).length }})
        </button>
      </div>

      <!-- Gruppierte Template-Liste -->
      <div class="space-y-3">
        <div v-for="(tpls, format) in grouped" :key="format" class="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <!-- Gruppen-Header -->
          <button @click="toggleGroup(format)"
            class="w-full flex items-center justify-between px-5 py-3.5 hover:bg-gray-50 transition-colors text-left">
            <div class="flex items-center gap-3">
              <span class="text-sm font-semibold text-gray-900">{{ formatLabel(format) }}</span>
              <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{{ tpls.length }} Templates</span>
              <span class="text-xs text-green-600 font-medium">{{ tpls.filter(t => isSelected(t.id)).length }} aktiv</span>
            </div>
            <span class="text-gray-400 transition-transform" :class="{ 'rotate-180': isGroupExpanded(format) }">▾</span>
          </button>

          <!-- Template-Zeilen -->
          <div v-if="isGroupExpanded(format)" class="divide-y divide-gray-50">
            <div v-for="tpl in tpls" :key="tpl.id"
              class="flex items-start gap-4 px-5 py-3.5 hover:bg-gray-50 transition-colors">

              <!-- Auswahl-Toggle -->
              <button @click="toggleSelected(tpl.id)"
                class="mt-0.5 w-6 h-6 rounded-full border-2 shrink-0 flex items-center justify-center transition-all"
                :class="isSelected(tpl.id) ? 'border-green-500 bg-green-500 text-white' : 'border-gray-300 text-transparent hover:border-green-400'"
                :title="isSelected(tpl.id) ? 'Deaktivieren' : 'Aktivieren'">
                ✓
              </button>

              <!-- Inhalt -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-medium text-gray-900">{{ tpl.name }}</span>
                  <span v-if="tpl.source === 'builtin'" class="text-[10px] bg-blue-50 text-blue-600 border border-blue-100 px-1.5 py-0.5 rounded">builtin</span>
                  <span v-if="tpl.source === 'ai_generated'" class="text-[10px] bg-violet-50 text-violet-600 border border-violet-100 px-1.5 py-0.5 rounded">KI-generiert</span>
                  <span v-for="icp in (tpl.best_for || []).slice(0,3)" :key="icp" class="text-[10px] bg-green-50 text-green-700 px-1.5 py-0.5 rounded">{{ icp }}</span>
                </div>
                <p class="text-xs text-gray-500 mt-0.5">{{ tpl.description }}</p>
              </div>

              <!-- Actions -->
              <button @click="openDetail(tpl)" class="text-xs text-gray-400 hover:text-gray-700 shrink-0 px-2 py-1 rounded hover:bg-gray-100 transition-colors">
                Details →
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Detail-Panel (Sidebar-Style) -->
      <div v-if="detailTemplate"
        class="fixed inset-0 z-50 flex"
        @click.self="closeDetail">
        <div class="fixed inset-0 bg-black/30" @click="closeDetail"></div>
        <div class="ml-auto relative bg-white shadow-2xl w-full max-w-xl h-full overflow-y-auto z-10 flex flex-col">
          <div class="flex items-center justify-between p-5 border-b border-gray-200 sticky top-0 bg-white z-10">
            <div>
              <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold text-gray-900">{{ detailTemplate.name }}</h2>
                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{{ formatLabel(detailTemplate.format) }}</span>
              </div>
              <p class="text-xs text-gray-500 mt-0.5">{{ detailTemplate.description }}</p>
            </div>
            <button @click="closeDetail" class="text-gray-400 hover:text-gray-700 text-xl">✕</button>
          </div>

          <div class="p-5 flex-1 space-y-5">
            <!-- Auswahl-Toggle groß -->
            <button @click="toggleSelected(detailTemplate.id)"
              class="w-full py-2.5 rounded-xl text-sm font-semibold border-2 transition-all"
              :class="isSelected(detailTemplate.id)
                ? 'border-green-500 bg-green-50 text-green-700'
                : 'border-gray-300 bg-white text-gray-600 hover:border-green-400'">
              {{ isSelected(detailTemplate.id) ? '✓ Für ' + currentStrategy?.name + ' aktiv' : '○ Für ' + currentStrategy?.name + ' aktivieren' }}
            </button>

            <!-- Struktur -->
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Struktur (Bausteine)</label>
              <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-1.5">
                <div v-for="(step, i) in (detailTemplate.structure || '').split('\n').filter(s => s.trim())" :key="i"
                  class="flex items-start gap-2.5">
                  <span class="w-5 h-5 rounded-full bg-gray-200 text-gray-600 text-[10px] font-bold flex items-center justify-center shrink-0 mt-0.5">{{ i+1 }}</span>
                  <span class="text-sm text-gray-800">{{ step }}</span>
                </div>
              </div>
            </div>

            <!-- Anwendungsfälle -->
            <div v-if="detailTemplate.best_for?.length">
              <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Passt gut für</label>
              <div class="flex flex-wrap gap-2">
                <span v-for="icp in detailTemplate.best_for" :key="icp"
                  class="text-sm bg-green-50 text-green-700 border border-green-100 px-3 py-1 rounded-full">{{ icp }}</span>
              </div>
            </div>

            <!-- Beispiel -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide">Beispiel</label>
                <button @click="generateExample(detailTemplate)" :disabled="generatingExample"
                  class="text-xs px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
                  {{ generatingExample ? 'Generiert…' : '🤖 KI-Beispiel' }}
                </button>
              </div>
              <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm text-gray-700 whitespace-pre-line leading-relaxed min-h-[80px]">
                {{ exampleResult || detailTemplate.example || '—' }}
              </div>
              <button v-if="exampleResult" @click="exampleResult = ''" class="text-xs text-gray-400 mt-1 underline hover:text-gray-600">Zurücksetzen</button>
            </div>

            <!-- Löschen -->
            <div class="pt-3 border-t border-gray-100">
              <button v-if="detailTemplate.source !== 'builtin'" @click="deleteTemplate(detailTemplate.id)"
                class="text-sm text-red-500 hover:text-red-700 underline">
                Template löschen
              </button>
              <p v-else class="text-xs text-gray-400 italic">Builtin-Templates können nicht gelöscht werden.</p>
            </div>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>
