<script setup>
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array, currentStrategy: Object, contentKeys: Array });

const templateFilter = ref('');
const selectedTemplate = ref(null);
const generatedExample = ref('');
const generatingExample = ref(false);

// Templates aus der aktuellen Strategie laden
const forms = reactive({
    templates: [],
});

const contentKey = props.contentKeys?.find(k => k.key === 'post_templates');
if (contentKey?.content?.templates) {
    forms.templates = JSON.parse(JSON.stringify(contentKey.content.templates));
}

const availableTemplates = [
    { name: 'Contrarian Take', format: 'linkedin_post', description: 'Gegen den Mainstream — alle sagen X, Realität ist Y', best_for: ['B2B-2', 'B2B-3'], structure: "These gegen den Mainstream (max 8 Wörter)\nWarum der Mainstream irrt\nBeleg / Mechanismus\nWas das für den Leser heißt\nSoft CTA", example: 'CRM-Automatisierung macht euren Vertrieb schlechter. Weil sie Prozesse optimiert, die gar nicht funktionieren. Erst Struktur, dann Automatisierung.' },
    { name: 'Data Drop', format: 'linkedin_post', description: 'Überraschende Zahl + Einordnung', best_for: ['B2B-1', 'B2B-2'], structure: "Überraschende Zahl / Fakt\nWarum das überrascht\nMechanismus dahinter\nKonsequenz\nSoft CTA", example: '73% der Forecasts liegen daneben. Nicht wegen schlechter Sales-Leute, sondern wegen fehlender Datenhygiene.' },
    { name: 'Mistake Post', format: 'linkedin_post', description: 'Die N häufigsten Fehler bei X', best_for: ['B2B-3', 'B2B-1'], structure: "Fehler 1 + Folge\nFehler 2 + Folge\nFehler 3 + Folge\nWie man es richtig macht\nSoft CTA", example: '3 Fehler bei der HubSpot-Property-Hygiene: 1. Pflichtfelder nicht gesetzt.' },
    { name: 'Framework / Modell', format: 'linkedin_post', description: 'Eigenes Denkmodell teilen', best_for: ['B2B-1', 'B2B-2'], structure: "Problem\nModell-Übersicht (3 Stufen)\nStufe 1\nStufe 2\nStufe 3\nErgebnis\nSoft CTA", example: 'Unser 3-Stufen-Modell für Datenhygiene: 1. Audit 2. Struktur 3. Rhythmus.' },
    { name: 'Storytelling Post', format: 'linkedin_post', description: 'Persönliche Geschichte mit Lerneffekt', best_for: ['B2B-1', 'B2B-3'], structure: "Hook (emotional)\nAusgangssituation\nWendepunkt\nLösung / Learnings\nCTA", example: 'Letzte Woche saß ich mit einem CEO zusammen, der nicht wusste, wo seine Deals stehen.' },
    { name: 'Listicle', format: 'linkedin_post', description: 'Aufzählung mit Mehrwert', best_for: ['B2B-1', 'B2B-2', 'B2B-3'], structure: "Hook (Zahl + Thema)\nPunkt 1\nPunkt 2\nPunkt 3\nFazit\nCTA", example: '5 Dinge, die wir von unseren besten Kunden über CRM gelernt haben.' },
    { name: 'Question Post', format: 'linkedin_post', description: 'Frage ans Netzwerk, hohe Engagement-Rate', best_for: ['B2B-1', 'B2B-2', 'B2B-3'], structure: "Frage (provokant)\nKontext\nEigene Einschätzung\nDiskussion", example: 'Warum haben die meisten Unternehmen kein Pipeline-Review?' },
    { name: 'Ad Copy — Schmerz', format: 'ad_copy', description: 'Schmerz-getriebene Anzeige', best_for: ['B2B-2', 'B2B-3'], structure: "Primary Text (max 125 Zeichen)\nHeadline (max 40)\nDescription\nCTA", example: 'Primary: Forecast daneben? Headline: Kostenloses Pipeline-Audit' },
    { name: 'Ad Copy — Gain', format: 'ad_copy', description: 'Gain-getriebene Anzeige', best_for: ['B2B-1', 'B2B-2'], structure: "Primary Text (max 125 Zeichen)\nHeadline (max 40)\nDescription\nCTA", example: 'Primary: Planbares Wachstum beginnt mit sauberem CRM.' },
    { name: 'Newsletter BK', format: 'newsletter_bk', description: 'Bestandskunden-Mail mit konkreten Schritten (BK = Lifecycle, Zielgruppe = B2B-Segment)', best_for: ['B2B-1', 'B2B-2', 'B2B-3'], structure: "Betreff (max 50)\nPreview-Text\nEinleitung\nHauptteil\nNext Step\nSign-off", example: 'Betreff: Datenhygiene in 15 Minuten' },
    { name: 'Landing Page Headline', format: 'landing_page_headlines', description: 'Konversions-starke Landing Page', best_for: ['B2B-1', 'B2B-2'], structure: "Hero Headline (max 20 Wörter)\nSub-Headline\n3 Bullet Points\nCTA-Button", example: 'Headline: Schluss mit Blindflug im Forecast' },
];

const filteredTemplates = computed(() => {
    if (!templateFilter.value) return availableTemplates;
    return availableTemplates.filter(t => t.format === templateFilter.value);
});

function addTemplate() {
    forms.templates.push({ format: 'linkedin_post', name: '', structure: '', example: '' });
}

function useTemplate(tpl) {
    if (forms.templates.some(t => t.name === tpl.name)) return;
    forms.templates.push({
        format: tpl.format,
        name: tpl.name,
        structure: tpl.structure,
        example: generatedExample.value || tpl.example,
    });
    generatedExample.value = '';
}

async function generateExampleWithAI(tpl) {
    const strategy = props.currentStrategy;
    if (!strategy || generatingExample.value) return;
    generatingExample.value = true;
    try {
        const res = await fetch('/api/assistant/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({
                message: `Generiere ein konkretes Beispiel für das Template "${tpl.name}" (Format: ${tpl.format}) für die Strategie "${strategy.name}". Template-Struktur: ${tpl.structure}. Gib NUR den fertigen Content-Text aus, keine Erklärungen.`,
                history: [],
            }),
        });
        const data = await res.json();
        generatedExample.value = data.reply || 'Keine Antwort.';
    } catch { generatedExample.value = 'Fehler.'; }
    generatingExample.value = false;
}

function saveGeneratedExample(tpl) {
    if (!generatedExample.value) return;
    let existing = forms.templates.find(t => t.name === tpl.name);
    if (existing) existing.example = generatedExample.value;
    else forms.templates.push({ format: tpl.format, name: tpl.name, structure: tpl.structure, example: generatedExample.value });
    generatedExample.value = '';
}

async function saveTemplates() {
    const strategy = props.currentStrategy;
    if (!strategy) return;
    await fetch('/api/strategy', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({ strategy: strategy.key, key: 'post_templates', content: { templates: forms.templates } }),
    });
    alert('Templates gespeichert!');
}
</script>

<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">📝 Template-Katalog</h1>
          <p class="text-sm text-gray-600 mt-1">Post-Formate entwickeln, testen und für Strategien bereitstellen.</p>
        </div>
        <div class="flex items-center gap-3">
          <select v-model="templateFilter" class="bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-gray-900">
            <option value="">Alle Formate</option>
            <option value="linkedin_post">LinkedIn Post</option>
            <option value="ad_copy">Ad Copy</option>
            <option value="newsletter_bk">Newsletter BK</option>
            <option value="newsletter_acquisition">Newsletter Acquisition</option>
            <option value="landing_page_headlines">Landing Page</option>
          </select>
          <button @click="saveTemplates" class="neu-btn-primary px-4 py-2 text-sm">💾 Templates speichern</button>
        </div>
      </div>

      <!-- Katalog -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
        <div v-for="tpl in filteredTemplates" :key="tpl.name"
          class="bg-white border border-gray-200 rounded-xl p-4 hover:border-green-300 hover:shadow-md transition-all cursor-pointer"
          :class="selectedTemplate?.name === tpl.name ? 'ring-2 ring-green-400 border-green-400' : ''"
          @click="selectedTemplate = tpl">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-gray-900">{{ tpl.name }}</span>
            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{{ tpl.format }}</span>
          </div>
          <p class="text-xs text-gray-500 mb-2">{{ tpl.description }}</p>
          <div class="flex flex-wrap gap-1">
            <span v-for="icp in (tpl.best_for || [])" :key="icp" class="text-xs bg-green-50 text-green-700 px-1.5 py-0.5 rounded">{{ icp }}</span>
          </div>
        </div>
      </div>

      <!-- Detail -->
      <div v-if="selectedTemplate" class="bg-white border border-gray-200 rounded-xl p-6 mb-8">
        <div class="flex items-center justify-between mb-5">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ selectedTemplate.name }}</h2>
            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{{ selectedTemplate.format }}</span>
          </div>
          <div class="flex items-center gap-2">
            <button @click="useTemplate(selectedTemplate)" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700">+ In Strategie übernehmen</button>
            <button @click="selectedTemplate = null" class="text-gray-400 hover:text-gray-900">✕</button>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-6">
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase">Struktur</label>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700 whitespace-pre-line font-mono">{{ selectedTemplate.structure }}</div>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase">Beispiel</label>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700 whitespace-pre-line mb-3 min-h-[100px]">
              <template v-if="generatedExample">{{ generatedExample }}</template>
              <template v-else>{{ selectedTemplate.example }}</template>
            </div>
            <div class="flex items-center gap-3">
              <button @click="generateExampleWithAI(selectedTemplate)" :disabled="generatingExample"
                class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50">
                {{ generatingExample ? 'Generiert…' : '🤖 Beispiel generieren' }}
              </button>
              <button v-if="generatedExample" @click="saveGeneratedExample(selectedTemplate)"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">💾 Speichern</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Eigene Templates -->
      <div class="bg-white border border-gray-200 rounded-xl p-6">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="text-sm font-semibold text-gray-900">Eigene Templates ({{ forms.templates.length }})</h3>
            <p class="text-xs text-gray-500 mt-0.5">Diese Templates stehen in der Strategie zur Auswahl.</p>
          </div>
          <button @click="addTemplate" class="neu-btn-primary px-3 py-1.5 text-sm">+ Eigenes Template</button>
        </div>
        <div v-if="!forms.templates.length" class="text-sm text-gray-500 italic py-6 text-center border-2 border-dashed border-gray-200 rounded-lg">
          Aus dem Katalog übernehmen oder ein eigenes Template erstellen.
        </div>
        <div v-for="(tpl, i) in forms.templates" :key="i" class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-3 space-y-3">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-gray-900">{{ tpl.name || 'Unbenannt' }}</span>
              <span class="text-xs bg-white border border-gray-200 text-gray-600 px-2 py-0.5 rounded-full">{{ tpl.format }}</span>
            </div>
            <button @click="forms.templates.splice(i, 1)" class="text-red-500 hover:text-red-700 text-sm">✕ Entfernen</button>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Format</label>
              <select v-model="tpl.format" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900">
                <option value="linkedin_post">LinkedIn Post</option>
                <option value="ad_copy">Ad Copy</option>
                <option value="newsletter_bk">Newsletter BK</option>
                <option value="newsletter_acquisition">Newsletter Acquisition</option>
                <option value="landing_page_headlines">Landing Page</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Name</label>
              <input v-model="tpl.name" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Template-Name" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Struktur (ein Element pro Zeile)</label>
              <textarea v-model="tpl.structure" rows="5" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-mono" placeholder="Hook\nMechanismus\nProof\nCTA"></textarea>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Beispiel</label>
              <textarea v-model="tpl.example" rows="5" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Beispiel-Post..."></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>