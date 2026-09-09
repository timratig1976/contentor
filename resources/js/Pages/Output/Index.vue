<script setup>
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array, currentStrategy: Object, items: Array, kpis: Object, learningReport: Object });

const previewFormat = ref('linkedin');
const selectedItem = ref(null);
const seoMode = ref(false);
const detailTab = ref('preview'); // preview | seo | kpi

// A/B-Varianten
const variantGroup = ref(null);       // alle Items einer variant_group_id
const variantLoading = ref(false);

const filteredItems = computed(() => {
    return props.items.filter(i => i.format === previewFormat.value || previewFormat.value === 'all');
});

const formats = ['linkedin', 'newsletter', 'ad', 'blog'];

function openItem(item) {
    selectedItem.value = item;
    seoMode.value = false;
    detailTab.value = 'preview';
    loadKpiForm(item);
}
function closeItem() { selectedItem.value = null; seoMode.value = false; }
function toggleSeo() { seoMode.value = !seoMode.value; }

// ─── KPI-Erfassung (Lernschleife) ───
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const kpiSaving = ref(false);
const kpiSaved = ref(false);
const kpiError = ref('');
const kpiForm = reactive({
    measured_at: new Date().toISOString().slice(0, 10),
    impressions: 0, reach: 0, clicks: 0,
    likes: 0, comments: 0, shares: 0,
    leads: 0, conversions: 0, revenue_eur: null,
    open_rate: null, click_rate: null, notes: '',
});

// Vorbelegung aus bereits gespeicherter Messung
function loadKpiForm(item) {
    const existing = props.kpis?.[item?.id] || null;
    Object.assign(kpiForm, {
        measured_at: existing?.measured_at?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        impressions: existing?.impressions ?? 0,
        reach: existing?.reach ?? 0,
        clicks: existing?.clicks ?? 0,
        likes: existing?.likes ?? 0,
        comments: existing?.comments ?? 0,
        shares: existing?.shares ?? 0,
        leads: existing?.leads ?? 0,
        conversions: existing?.conversions ?? 0,
        revenue_eur: existing?.revenue_eur ?? null,
        open_rate: existing?.open_rate ?? null,
        click_rate: existing?.click_rate ?? null,
        notes: existing?.notes ?? '',
    });
    kpiError.value = ''; kpiSaved.value = false;
}

async function saveKpi() {
    if (!selectedItem.value) return;
    kpiSaving.value = true; kpiError.value = ''; kpiSaved.value = false;
    try {
        const res = await fetch('/api/content-kpis', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ content_item_id: selectedItem.value.id, ...kpiForm }),
        });
        const data = await res.json().catch(() => null);
        if (!res.ok) { kpiError.value = data?.message || `HTTP ${res.status}`; return; }
        kpiSaved.value = true; setTimeout(() => kpiSaved.value = false, 2500);
        // Lokaler Status: Item ist jetzt live
        const it = props.items.find(x => x.id === selectedItem.value.id);
        if (it) it.status = 'live';
        selectedItem.value.status = 'live';
        // Board-Daten neu laden
        router.reload({ only: ['kpis', 'learningReport', 'items'] });
    } finally { kpiSaving.value = false; }
}

const hasKpis = computed(() => (props.learningReport?.measured_items || 0) > 0);

// Alle Varianten einer Gruppe laden (aus props.items)
function showVariants(groupId) {
    variantGroup.value = props.items.filter(i => i.variant_group_id === groupId);
}

async function selectVariant(item) {
    if (variantLoading.value) return;
    variantLoading.value = true;
    try {
        await fetch(`/api/content/${item.id}/select-variant`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        // Status lokal aktualisieren
        variantGroup.value.forEach(v => {
            const it = props.items.find(x => x.id === v.id);
            if (it) it.status = (v.id === item.id) ? 'geplant' : 'verworfen';
        });
    } finally {
        variantLoading.value = false;
    }
}

function switchStrategy(key) {
    router.get('/output', { strategy: key }, { preserveState: true });
}

// SEO scoring
function seoScore(item) {
    const text = item.content || '';
    let score = 0;
    if (text.length > 500) score += 2;
    if (text.length > 2000) score += 1;
    if (/#\w+/.test(text)) score += 1;
    if (text.includes('\n\n')) score += 1;
    return score;
}
</script>

<template>
  <AppLayout>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Output</h1>
        <p class="text-sm text-gray-600 mt-1">Generierte Content-Posts · {{ currentStrategy.name }}</p>
      </div>
      <div class="flex gap-2">
        <button v-for="s in strategies" :key="s.key" @click="switchStrategy(s.key)"
          class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
          :class="s.key === currentStrategy.key ? 'bg-white border border-gray-200 text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'">
          {{ s.name }}
        </button>
      </div>
    </div>

    <!-- Performance-Board (Lernschleife) -->
    <div v-if="learningReport" class="mb-6 bg-white border border-gray-200 rounded-xl p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-900">📈 Performance-Learnings</h3>
        <span class="text-xs text-gray-400">{{ learningReport.measured_items }} gemessene Posts · {{ learningReport.total_leads }} Leads · {{ learningReport.total_conversions }} Conversions</span>
      </div>

      <p v-if="!learningReport.measured_items" class="text-xs text-gray-500">
        Noch keine KPIs erfasst. Öffne einen veröffentlichten Post → Tab „KPI“ → Zahlen eintragen.
        Sobald mindestens 2 Posts pro Gruppe gemessen sind, beginnt das System zu lernen und
        bevorzugt automatisch die Patterns/Formate/ICPs, die nachweislich funktionieren.
      </p>

      <template v-else>
        <ul class="space-y-1.5">
          <li v-for="(ins, idx) in learningReport.insights" :key="idx" class="text-xs text-gray-700 flex gap-2">
            <span class="text-green-600">●</span> {{ ins }}
          </li>
        </ul>

        <div v-if="Object.keys(learningReport.by_pattern || {}).length" class="mt-4">
          <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Pattern-Ranking (Ø Erfolgsscore)</h4>
          <div class="flex flex-wrap gap-2">
            <span v-for="(agg, key) in learningReport.by_pattern" :key="key"
              class="text-xs px-2.5 py-1 rounded-full border"
              :class="key === Object.keys(learningReport.by_pattern)[0] ? 'bg-green-50 border-green-300 text-green-800 font-semibold' : 'bg-gray-50 border-gray-200 text-gray-600'">
              {{ key }} · {{ agg.avg_score }} <span class="opacity-60">({{ agg.samples }} Posts)</span>
            </span>
          </div>
        </div>

        <div v-if="Object.keys(learningReport.by_icp || {}).length" class="mt-3">
          <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">ICP-Ranking</h4>
          <div class="flex flex-wrap gap-2">
            <span v-for="(agg, key) in learningReport.by_icp" :key="key"
              class="text-xs px-2.5 py-1 rounded-full border"
              :class="key === Object.keys(learningReport.by_icp)[0] ? 'bg-green-50 border-green-300 text-green-800 font-semibold' : 'bg-gray-50 border-gray-200 text-gray-600'">
              {{ key }} · {{ agg.avg_score }} <span class="opacity-60">({{ agg.samples }})</span>
            </span>
          </div>
        </div>
      </template>
    </div>

    <!-- Format Tabs -->
    <div class="flex gap-1 mb-4">
      <button @click="previewFormat='all'" class="px-4 py-2 rounded-lg text-sm font-medium"
        :class="previewFormat==='all'?'bg-white border border-gray-200 text-gray-900':'text-gray-500 hover:text-gray-900'">
        Alle ({{ items.length }})
      </button>
      <button v-for="f in formats" :key="f" @click="previewFormat=f" class="px-4 py-2 rounded-lg text-sm font-medium"
        :class="previewFormat===f?'bg-white border border-gray-200 text-gray-900':'text-gray-500 hover:text-gray-900'">
        {{ f === 'linkedin' ? 'LinkedIn' : f === 'newsletter' ? 'Newsletter' : f === 'ad' ? 'Ad' : 'Blog' }}
        ({{ items.filter(i => i.format === `linkedin_post` || i.format === `ad_copy` || i.format === `newsletter_acquisition` || i.format === `landing_page_headlines`).filter(i => i.format?.startsWith(f)).length }})
      </button>
    </div>

    <!-- Post Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
      <div v-for="item in filteredItems" :key="item.id" @click="openItem(item)"
        class="bg-white border border-gray-200 rounded-xl p-4 cursor-pointer hover:shadow-md transition-shadow">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200">{{ item.format }}</span>
          <span class="text-xs text-gray-400">{{ item.status }}</span>
        </div>
        <!-- Varianten-Badge -->
        <div v-if="item.variant_group_id" class="mb-2 flex items-center gap-2">
          <span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full">{{ item.variant_pattern }}</span>
          <button @click.stop="showVariants(item.variant_group_id)"
            class="text-xs text-gray-500 underline hover:text-gray-700">
            Alle Varianten zeigen
          </button>
        </div>
        <p class="text-sm text-gray-900 font-medium mb-2 line-clamp-2">{{ item.title || item.angle?.angle }}</p>
        <div class="text-xs text-gray-500 mb-2">
          <span v-if="item.persona">{{ item.persona?.name }} · </span>
          {{ item.created_at?.split('T')[0] }}
        </div>
        <div class="flex items-center gap-2">
          <span class="text-xs text-gray-400">SEO: {{ seoScore(item) }}/5</span>
          <span class="text-xs text-gray-300">·</span>
          <span class="text-xs text-gray-400">{{ item.refinement_count || 0 }} Optimierungen</span>
          <span v-if="kpis?.[item.id]" class="ml-auto text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"
            :title="`Impressions: ${kpis[item.id].impressions} · Likes: ${kpis[item.id].likes} · Leads: ${kpis[item.id].leads}`">
            📈 {{ kpis[item.id].leads > 0 ? kpis[item.id].leads + ' Leads' : kpis[item.id].impressions + ' Impr.' }}
          </span>
        </div>
      </div>
      <div v-if="filteredItems.length===0" class="col-span-full text-center py-12 text-sm text-gray-500">
        Noch keine Content-Posts für dieses Format. Produziere Content über die Agents-Seite oder Quick Input.
      </div>
    </div>

    <!-- Detail View -->
    <div v-if="selectedItem" class="fixed inset-0 z-50 flex items-start justify-center pt-8 overflow-y-auto">
      <div class="fixed inset-0 bg-black/40" @click="closeItem"></div>
      <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-3xl mx-4 mb-8 z-10">
        <!-- Header -->
        <div class="flex items-center justify-between p-5 border-b border-gray-200">
          <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ selectedItem.title || selectedItem.angle?.angle }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ selectedItem.format }} · {{ selectedItem.status }} · {{ selectedItem.created_at?.split('T')[0] }}</p>
          </div>
          <button @click="closeItem" class="text-gray-400 hover:text-gray-900 text-xl">✕</button>
        </div>

        <div class="flex border-b border-gray-200">
          <button @click="seoMode=false; detailTab='preview'" class="flex-1 px-4 py-2 text-sm font-medium" :class="detailTab==='preview'?'text-green-600 border-b-2 border-green-600':'text-gray-500'">📝 Preview</button>
          <button @click="seoMode=true; detailTab='seo'" class="flex-1 px-4 py-2 text-sm font-medium" :class="detailTab==='seo'?'text-green-600 border-b-2 border-green-600':'text-gray-500'">🔍 SEO</button>
          <button @click="detailTab='kpi'" class="flex-1 px-4 py-2 text-sm font-medium" :class="detailTab==='kpi'?'text-green-600 border-b-2 border-green-600':'text-gray-500'">📈 KPI (nach Publishing)</button>
        </div>

        <!-- Preview Mode -->
        <div v-if="detailTab==='preview'" class="p-5">
          <!-- LinkedIn -->
          <div v-if="selectedItem.preview?.linkedin" class="mb-6">
            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">LinkedIn Preview</h4>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
              <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-full bg-green-600 flex items-center justify-center text-white font-bold text-sm">{{ selectedItem.preview.linkedin.avatar }}</div>
                <div>
                  <p class="text-sm font-semibold text-gray-900">{{ selectedItem.preview.linkedin.author }}</p>
                  <p class="text-xs text-gray-500">{{ selectedItem.preview.linkedin.role }} · {{ selectedItem.preview.linkedin.time }}</p>
                </div>
              </div>
              <p class="text-sm text-gray-900 whitespace-pre-wrap leading-relaxed">{{ selectedItem.preview.linkedin.text }}</p>
              <div class="flex gap-2 mt-3">
                <span v-for="h in (selectedItem.preview.linkedin.hashtags || []).slice(0, 5)" :key="h" class="text-xs text-green-600">{{ h }}</span>
              </div>
            </div>
          </div>

          <!-- Ad -->
          <div v-if="selectedItem.preview?.ad" class="mb-6">
            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">Ad Preview</h4>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
              <div class="bg-gray-100 rounded-lg h-40 mb-3 flex items-center justify-center text-gray-400 text-sm">Ad Image</div>
              <p class="text-sm font-semibold text-gray-900 mb-1">{{ selectedItem.preview.ad.headline }}</p>
              <p class="text-sm text-gray-700">{{ selectedItem.preview.ad.primary_text }}</p>
              <span class="inline-block mt-2 px-3 py-1 bg-gray-100 text-xs font-medium text-gray-600 rounded">{{ selectedItem.preview.ad.cta }}</span>
            </div>
          </div>

          <!-- Newsletter -->
          <div v-if="selectedItem.preview?.newsletter" class="mb-6">
            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">Newsletter Preview</h4>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
              <p class="text-sm font-semibold text-gray-900 mb-1">{{ selectedItem.preview.newsletter.subject }}</p>
              <p class="text-xs text-gray-500 mb-2">{{ selectedItem.preview.newsletter.preview }}</p>
              <div class="text-sm text-gray-900 whitespace-pre-wrap leading-relaxed">{{ selectedItem.preview.newsletter.body }}</div>
            </div>
          </div>

          <!-- Blog -->
          <div v-if="selectedItem.preview?.blog">
            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">Blog Preview</h4>
            <div class="bg-white border border-gray-200 rounded-xl p-4">
              <h4 class="text-lg font-semibold text-gray-900 mb-2">{{ selectedItem.preview.blog.title }}</h4>
              <p class="text-sm text-gray-600 mb-3">{{ selectedItem.preview.blog.excerpt }}...</p>
              <div class="text-sm text-gray-900 whitespace-pre-wrap leading-relaxed">{{ selectedItem.preview.blog.body }}</div>
            </div>
          </div>
        </div>

        <!-- SEO Mode -->
        <div v-else-if="detailTab==='seo'" class="p-5">
          <div class="space-y-4">
            <div class="grid grid-cols-3 gap-3">
              <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-gray-900">{{ seoScore(selectedItem) }}</div>
                <div class="text-xs text-gray-500">/5 SEO Score</div>
              </div>
              <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-gray-900">{{ (selectedItem.content||'').length }}</div>
                <div class="text-xs text-gray-500">Zeichen</div>
              </div>
              <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-gray-900">{{ ((selectedItem.content||'').match(/#\w+/g) || []).length }}</div>
                <div class="text-xs text-gray-500">Hashtags</div>
              </div>
            </div>
            <div>
              <h4 class="text-sm font-semibold text-gray-900 mb-2">SEO-Analyse</h4>
              <div class="space-y-2 text-sm">
                <div class="flex items-center gap-2">
                  <span :class="(selectedItem.content||'').length>500?'text-green-600':'text-red-500'">{{ (selectedItem.content||'').length>500?'✓':'✗' }}</span>
                  <span class="text-gray-700">Länge &gt; 500 Zeichen</span>
                </div>
                <div class="flex items-center gap-2">
                  <span :class="(selectedItem.content||'').length>2000?'text-green-600':'text-yellow-500'">{{ (selectedItem.content||'').length>2000?'✓':'✗' }}</span>
                  <span class="text-gray-700">Länge &gt; 2000 Zeichen (Long-Form)</span>
                </div>
                <div class="flex items-center gap-2">
                  <span :class="(/#\w+/.test(selectedItem.content||''))?'text-green-600':'text-red-500'">{{ (/#\w+/.test(selectedItem.content||''))?'✓':'✗' }}</span>
                  <span class="text-gray-700">Hashtags vorhanden</span>
                </div>
                <div class="flex items-center gap-2">
                  <span :class="(selectedItem.content||'').includes('\n\n')?'text-green-600':'text-yellow-500'">{{ (selectedItem.content||'').includes('\n\n')?'✓':'✗' }}</span>
                  <span class="text-gray-700">Absätze strukturiert</span>
                </div>
              </div>
            </div>
            <div>
              <h4 class="text-sm font-semibold text-gray-900 mb-2">Keyword-Vorschläge</h4>
              <div class="flex flex-wrap gap-2">
                <span v-for="kw in (selectedItem.persona?.content_attributes?.keywords || []).slice(0, 8)" :key="kw" class="text-xs bg-gray-100 text-gray-700 px-2 py-1 rounded">{{ kw }}</span>
                <span v-if="!(selectedItem.persona?.content_attributes?.keywords || []).length" class="text-xs text-gray-500">Keine Keywords in Persona definiert</span>
              </div>
            </div>
          </div>
        </div>

        <!-- KPI Mode: Performance-Daten nach dem Publishing erfassen -->
        <div v-else-if="detailTab==='kpi'" class="p-5">
          <p class="text-xs text-gray-500 mb-4">
            Trage die echten Zahlen nach dem Publishing ein. Das System lernt daraus:
            Format × Pattern × Statement-Typ × ICP werden ausgewertet und die
            Erkenntnisse fließen automatisch in künftige Generierungen ein.
          </p>

          <div class="grid grid-cols-2 gap-3 mb-4">
            <div>
              <label class="block text-xs text-gray-400 mb-1">Messdatum</label>
              <input type="date" v-model="kpiForm.measured_at" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
            <div>
              <label class="block text-xs text-gray-400 mb-1">Impressions</label>
              <input type="number" min="0" v-model.number="kpiForm.impressions" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
          </div>

          <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Interaktion</h4>
          <div class="grid grid-cols-3 gap-3 mb-4">
            <div>
              <label class="block text-xs text-gray-400 mb-1">Likes</label>
              <input type="number" min="0" v-model.number="kpiForm.likes" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
            <div>
              <label class="block text-xs text-gray-400 mb-1">Kommentare</label>
              <input type="number" min="0" v-model.number="kpiForm.comments" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
            <div>
              <label class="block text-xs text-gray-400 mb-1">Shares</label>
              <input type="number" min="0" v-model.number="kpiForm.shares" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
          </div>

          <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Conversion</h4>
          <div class="grid grid-cols-3 gap-3 mb-4">
            <div>
              <label class="block text-xs text-gray-400 mb-1">Klicks</label>
              <input type="number" min="0" v-model.number="kpiForm.clicks" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
            <div>
              <label class="block text-xs text-gray-400 mb-1">Leads</label>
              <input type="number" min="0" v-model.number="kpiForm.leads" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
            <div>
              <label class="block text-xs text-gray-400 mb-1">Conversions</label>
              <input type="number" min="0" v-model.number="kpiForm.conversions" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
          </div>

          <div class="grid grid-cols-3 gap-3 mb-4">
            <div>
              <label class="block text-xs text-gray-400 mb-1">Umsatz (€)</label>
              <input type="number" min="0" step="0.01" v-model.number="kpiForm.revenue_eur" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800">
            </div>
            <div>
              <label class="block text-xs text-gray-400 mb-1">Open Rate (%)</label>
              <input type="number" min="0" max="100" step="0.1" v-model.number="kpiForm.open_rate" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800" placeholder="Newsletter">
            </div>
            <div>
              <label class="block text-xs text-gray-400 mb-1">Click Rate (%)</label>
              <input type="number" min="0" max="100" step="0.1" v-model.number="kpiForm.click_rate" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800" placeholder="Newsletter">
            </div>
          </div>

          <div class="mb-4">
            <label class="block text-xs text-gray-400 mb-1">Notizen</label>
            <textarea rows="2" v-model="kpiForm.notes" class="w-full bg-white border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800" placeholder="z. B. in welchem Kontext gepostet, besondere Umstände…"></textarea>
          </div>

          <p v-if="kpiError" class="text-xs text-red-600 bg-red-50 border border-red-200 rounded-md px-2 py-1.5 mb-2">⚠️ {{ kpiError }}</p>
          <div class="flex items-center gap-3">
            <button @click="saveKpi" :disabled="kpiSaving"
              class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
              {{ kpiSaving ? 'Speichere…' : '💾 KPIs speichern (Item → live)' }}
            </button>
            <span v-if="kpiSaved" class="text-xs text-green-600">✓ Gespeichert — Learnings aktualisiert</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Side-by-Side Varianten-Modal -->
    <div v-if="variantGroup" class="fixed inset-0 z-[60] flex items-start justify-center pt-8 overflow-y-auto">
      <div class="fixed inset-0 bg-black/40" @click="variantGroup = null"></div>
      <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-6xl mx-4 mb-8 z-10">
        <div class="flex items-center justify-between p-5 border-b border-gray-200">
          <div>
            <h3 class="text-lg font-semibold text-gray-900">Varianten vergleichen</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ variantGroup.length }} Varianten · wähle eine als Gewinner</p>
          </div>
          <button @click="variantGroup = null" class="text-gray-400 hover:text-gray-900 text-xl">✕</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-6">
          <div v-for="v in variantGroup" :key="v.id"
            class="bg-white border rounded-xl p-4 flex flex-col"
            :class="v.status === 'geplant' ? 'border-green-400 ring-2 ring-green-300' : 'border-gray-200'">
            <div class="flex items-center justify-between mb-2">
              <p class="text-xs font-semibold text-gray-500 uppercase">{{ v.variant_pattern }}</p>
              <span class="text-xs px-2 py-0.5 rounded-full"
                :class="{
                  'bg-green-50 text-green-700': v.status === 'geplant',
                  'bg-red-50 text-red-600': v.status === 'verworfen',
                  'bg-gray-100 text-gray-500': v.status !== 'geplant' && v.status !== 'verworfen',
                }">{{ v.status }}</span>
            </div>
            <p class="text-sm text-gray-900 whitespace-pre-line flex-1 max-h-72 overflow-y-auto">{{ v.content }}</p>
            <button @click="selectVariant(v)" :disabled="variantLoading || v.status === 'geplant'"
              class="mt-3 w-full px-3 py-1.5 text-sm bg-green-600 text-white rounded-lg disabled:opacity-40 disabled:cursor-not-allowed">
              {{ v.status === 'geplant' ? '✓ Gewählt' : '✓ Diese Variante wählen' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>