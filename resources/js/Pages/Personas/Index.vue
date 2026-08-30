<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array, personas: Array });

const activeTab = ref('list');
const editingId = ref(null);
const saving = ref(false);

const emptyForm = () => ({
    strategy: props.strategies[0]?.key || 'viscale', name: '', role: '', voice: '', positioning: '',
    core_statements: [], tonality: { style: 'direkt', do: [], dont: [] },
    topics: [], angles: [], content_attributes: { maxLength: 2000, formats: ['linkedin_post'], tone: 'direkt', keywords: [] },
    cadence: 'weekly', channel_strategies: [], active: true,
});

const form = reactive(emptyForm());

function openNew() { editingId.value = null; Object.assign(form, emptyForm()); activeTab.value = 'form'; }
function openEdit(p) { editingId.value = p.id; form.strategy = p.strategy?.key || 'viscale'; form.name = p.name; form.role = p.role || ''; form.voice = p.voice || ''; form.positioning = p.positioning || ''; form.core_statements = [...(p.core_statements || [])]; form.tonality = { style: 'direkt', do: [], dont: [], ...(p.tonality || {}) }; form.topics = [...(p.topics || [])]; form.angles = [...(p.angles || [])]; form.content_attributes = { maxLength: 2000, formats: ['linkedin_post'], tone: 'direkt', keywords: [], ...(p.content_attributes || {}) }; form.cadence = p.cadence || 'weekly'; form.channel_strategies = p.channel_strategies ? JSON.parse(JSON.stringify(p.channel_strategies)) : []; form.active = p.active ?? true; activeTab.value = 'form'; }
function closeForm() { activeTab.value = 'list'; }
async function save() { saving.value = true; try { const payload = { ...form }; if (editingId.value) { await router.patch(`/api/personas/${editingId.value}`, payload, { preserveState: true, onSuccess: closeForm }); } else { await router.post('/api/personas', payload, { preserveState: true, onSuccess: closeForm }); } } finally { saving.value = false; } }
async function del(id) { if (!confirm('Persona löschen?')) return; await router.delete(`/api/personas/${id}`, { preserveState: true }); }
function addItem(f) { if (!form[f]) form[f] = []; form[f].push(''); }
function addAngle() { if (!form.angles) form.angles = []; form.angles.push({ text: '', icp: '', funnel: 'ToFu', score: 0 }); }
function addKeyword() { if (!form.content_attributes.keywords) form.content_attributes.keywords = []; form.content_attributes.keywords.push(''); }
function addChannel() { form.channel_strategies.push({ channel: 'linkedin', frequency: 'weekly', content_mix: { thought_leadership: 40, case_study: 20, how_to: 20, personal: 20 } }); }
function addTonal(w) { if (!form.tonality[w]) form.tonality[w] = []; form.tonality[w].push(''); }
</script>

<template>
  <AppLayout>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Personas</h1>
        <p class="text-sm text-gray-600 mt-1">Wer postet was, in welchem Ton, zu welchen Themen?</p>
      </div>
      <button v-if="activeTab==='list'" @click="openNew" class="neu-btn-primary px-4 py-2 text-sm">+ Neue Persona</button>
      <button v-else @click="closeForm" class="text-gray-500 hover:text-gray-900 text-sm">← Zurück zur Liste</button>
    </div>

    <!-- Tabs -->
    <div v-if="activeTab==='form'" class="flex gap-2 mb-6">
      <button @click="activeTab='list'" class="px-3 py-1.5 rounded-lg text-sm text-gray-600 hover:bg-gray-100">Liste</button>
      <button class="px-3 py-1.5 rounded-lg text-sm font-medium bg-white border border-gray-200 text-gray-900">Formular</button>
    </div>

    <!-- LIST VIEW -->
    <div v-if="activeTab==='list'">
      <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <table class="w-full">
          <thead><tr class="bg-gray-50 border-b border-gray-200"><th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Name</th><th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Strategie</th><th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Rolle</th><th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Tonalität</th><th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Themen</th><th class="text-left px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Angles</th><th class="text-center px-4 py-3 text-xs font-semibold text-gray-600 uppercase">Aktiv</th><th class="text-right px-4 py-3 text-xs font-semibold text-gray-600 uppercase"></th></tr></thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="p in personas" :key="p.id" class="hover:bg-gray-50 cursor-pointer" @click="openEdit(p)">
              <td class="px-4 py-3 text-sm text-gray-900 font-medium">{{ p.name }}</td>
              <td class="px-4 py-3 text-sm text-gray-600">{{ p.strategy?.name }}</td>
              <td class="px-4 py-3 text-sm text-gray-600">{{ p.role || '—' }}</td>
              <td class="px-4 py-3"><span class="text-xs px-2 py-1 rounded-full bg-green-50 text-green-700 border border-green-200">{{ p.tonality?.style || 'direkt' }}</span></td>
              <td class="px-4 py-3"><div class="flex flex-wrap gap-1 max-w-[150px]"><span v-for="t in (p.topics || []).slice(0, 2)" :key="t" class="text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">{{ t }}</span></div></td>
              <td class="px-4 py-3 text-sm text-gray-600">{{ (p.angles || []).length }}</td>
              <td class="px-4 py-3 text-center"><span :class="p.active?'text-green-600':'text-red-500'">{{ p.active?'✓':'✗' }}</span></td>
              <td class="px-4 py-3 text-right" @click.stop><button @click="del(p.id)" class="text-xs text-red-500 hover:text-red-700">🗑️</button></td>
            </tr>
            <tr v-if="!personas?.length"><td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">Noch keine Personas. Lege sie in der Strategie an oder erstelle sie hier.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- FORM VIEW (full page, not modal) -->
    <div v-else class="max-w-3xl">
      <div class="bg-white border border-gray-200 rounded-xl p-6 space-y-6">
        <!-- Basis -->
        <div>
          <h3 class="text-sm font-semibold text-gray-900 mb-3">Basis</h3>
          <div class="grid grid-cols-3 gap-4">
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">Name *</label><input v-model="form.name" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /></div>
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">Rolle</label><input v-model="form.role" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /></div>
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">Strategie</label><select v-model="form.strategy" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"><option v-for="s in strategies" :key="s.key" :value="s.key">{{ s.name }}</option></select></div>
          </div>
          <div class="grid grid-cols-2 gap-4 mt-4">
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">Positionierung</label><input v-model="form.positioning" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /></div>
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">Kadenz</label><select v-model="form.cadence" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"><option value="daily">Täglich</option><option value="weekly">1× Woche</option><option value="biweekly">2× Woche</option><option value="monthly">1× Monat</option></select></div>
          </div>
          <div class="mt-4"><label class="block text-sm text-gray-900 mb-1 font-medium">Voice</label><textarea v-model="form.voice" rows="2" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"></textarea></div>
        </div>

        <!-- Kernaussagen -->
        <div class="border-t border-gray-200 pt-4">
          <h3 class="text-sm font-semibold text-gray-900 mb-3">Kernaussagen</h3>
          <div class="space-y-1.5 mb-2">
            <div v-for="(stmt, i) in form.core_statements" :key="i" class="flex gap-2">
              <input v-model="form.core_statements[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" />
              <button @click="form.core_statements.splice(i, 1)" class="text-red-500 hover:text-red-700 text-lg">✕</button>
            </div>
          </div>
          <button @click="addItem('core_statements')" class="text-sm text-green-600 hover:text-green-700 font-medium">+ Kernaussage</button>
        </div>

        <!-- Tonalität -->
        <div class="border-t border-gray-200 pt-4">
          <h3 class="text-sm font-semibold text-gray-900 mb-3">Tonalität</h3>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">Stil</label><select v-model="form.tonality.style" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"><option value="direkt">Direkt</option><option value="beratend">Beratend</option><option value="provokativ">Provokativ</option><option value="analytisch">Analytisch</option></select></div>
            <div></div>
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">✅ Do's</label><div class="space-y-1.5 mb-1"><div v-for="(d, i) in (form.tonality.do || [])" :key="i" class="flex gap-2"><input v-model="form.tonality.do[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /><button @click="form.tonality.do.splice(i, 1)" class="text-red-500">✕</button></div></div><button @click="addTonal('do')" class="text-sm text-green-600 font-medium">+ Do</button></div>
            <div><label class="block text-sm text-gray-900 mb-1 font-medium">🚫 Don'ts</label><div class="space-y-1.5 mb-1"><div v-for="(d, i) in (form.tonality.dont || [])" :key="i" class="flex gap-2"><input v-model="form.tonality.dont[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" /><button @click="form.tonality.dont.splice(i, 1)" class="text-red-500">✕</button></div></div><button @click="addTonal('dont')" class="text-sm text-green-600 font-medium">+ Don't</button></div>
          </div>
        </div>

        <!-- Themen -->
        <div class="border-t border-gray-200 pt-4">
          <h3 class="text-sm font-semibold text-gray-900 mb-3">Themen-Cluster</h3>
          <div class="space-y-1.5 mb-2">
            <div v-for="(t, i) in form.topics" :key="i" class="flex gap-2">
              <input v-model="form.topics[i]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" />
              <button @click="form.topics.splice(i, 1)" class="text-red-500">✕</button>
            </div>
          </div>
          <button @click="addItem('topics')" class="text-sm text-green-600 font-medium">+ Thema</button>
        </div>

        <!-- Angles -->
        <div class="border-t border-gray-200 pt-4">
          <div class="flex justify-between mb-2"><h3 class="text-sm font-semibold text-gray-900">Spezifische Angles</h3><button @click="addAngle" class="text-sm text-green-600 font-medium">+ Angle</button></div>
          <div class="space-y-2">
            <div v-for="(a, i) in (form.angles || [])" :key="i" class="bg-gray-50 border border-gray-200 rounded-lg p-3">
              <div class="flex justify-between mb-2"><span class="text-sm text-gray-900 font-medium font-medium">Angle {{ i + 1 }}</span><button @click="form.angles.splice(i, 1)" class="text-red-500 text-xs">✕</button></div>
              <textarea v-model="a.text" rows="2" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 mb-2 focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors"></textarea>
              <div class="grid grid-cols-3 gap-2">
                <input v-model="a.icp" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900" placeholder="ICP" />
                <select v-model="a.funnel" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900"><option>ToFu</option><option>MoFu</option><option>BoFu</option></select>
                <input v-model.number="a.score" type="number" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900" placeholder="Score" />
              </div>
            </div>
          </div>
        </div>

        <!-- Content-Attribute -->
        <div class="border-t border-gray-200 pt-4">
          <h3 class="text-sm font-semibold text-gray-900 mb-3">Content-Attribute</h3>
          <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <div><label class="text-xs text-gray-900 mb-1 font-medium">Max Länge (Zeichen)</label><input v-model.number="form.content_attributes.maxLength" type="number" class="w-full bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900" /></div>
              <div><label class="text-xs text-gray-900 mb-1 font-medium">Tone</label><select v-model="form.content_attributes.tone" class="w-full bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900"><option value="direkt">Direkt</option><option value="casual">Casual</option><option value="formal">Formal</option></select></div>
            </div>
            <div>
              <label class="text-xs text-gray-900 mb-1 font-medium">Formate</label>
              <div class="flex flex-wrap gap-3"><label v-for="fmt in ['linkedin_post','ad_copy','newsletter_acquisition','landing_page_headlines']" :key="fmt" class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" :value="fmt" v-model="form.content_attributes.formats" class="rounded border-gray-300 text-green-600 focus:ring-2 focus:ring-green-500/20" /> {{ fmt }}</label></div>
            </div>
            <div>
              <label class="text-xs text-gray-900 mb-1 font-medium">Keywords</label>
              <div class="flex flex-wrap gap-1.5 mb-1.5"><span v-for="(kw, i) in (form.content_attributes.keywords || [])" :key="i" class="text-xs bg-white border border-gray-200 text-gray-700 px-2 py-1 rounded flex items-center gap-1">{{ kw }}<button @click="form.content_attributes.keywords.splice(i, 1)" class="text-red-500">✕</button></span></div>
              <button @click="addKeyword" class="text-sm text-green-600 font-medium">+ Keyword</button>
            </div>
          </div>
        </div>

        <!-- Channel-Strategien -->
        <div class="border-t border-gray-200 pt-4">
          <div class="flex justify-between mb-2"><h3 class="text-sm font-semibold text-gray-900">Channel-Strategien</h3><button @click="addChannel" class="text-sm text-green-600 font-medium">+ Kanal</button></div>
          <div v-for="(cs, i) in form.channel_strategies" :key="i" class="bg-gray-50 border border-gray-200 rounded-lg p-3 mb-2">
            <div class="flex justify-between mb-2">
              <div class="flex gap-2">
                <select v-model="cs.channel" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900"><option value="linkedin">LinkedIn</option><option value="newsletter">Newsletter</option><option value="blog">Blog</option></select>
                <select v-model="cs.frequency" class="bg-white border border-gray-300 rounded px-2 py-1.5 text-sm text-gray-900"><option value="daily">Täglich</option><option value="weekly">1× Woche</option><option value="biweekly">2× Woche</option></select>
              </div>
              <button @click="form.channel_strategies.splice(i, 1)" class="text-red-500">✕</button>
            </div>
            <div class="grid grid-cols-4 gap-2 text-xs">
              <div><label class="text-gray-600">Leadership</label><input type="number" v-model.number="cs.content_mix.thought_leadership" class="w-full bg-white border border-gray-300 rounded p-1 text-gray-900" /></div>
              <div><label class="text-gray-600">Case</label><input type="number" v-model.number="cs.content_mix.case_study" class="w-full bg-white border border-gray-300 rounded p-1 text-gray-900" /></div>
              <div><label class="text-gray-600">How-To</label><input type="number" v-model.number="cs.content_mix.how_to" class="w-full bg-white border border-gray-300 rounded p-1 text-gray-900" /></div>
              <div><label class="text-gray-600">Persönlich</label><input type="number" v-model.number="cs.content_mix.personal" class="w-full bg-white border border-gray-300 rounded p-1 text-gray-900" /></div>
            </div>
          </div>
        </div>

        <!-- Aktiv + Actions -->
        <div class="border-t border-gray-200 pt-4 flex items-center justify-between">
          <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" v-model="form.active" class="rounded border-gray-300 text-green-600 focus:ring-2 focus:ring-green-500/20" /> Aktiv</label>
          <div class="flex gap-3">
            <button @click="closeForm" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">Abbrechen</button>
            <button @click="save" :disabled="saving" class="neu-btn-primary px-4 py-2 text-sm">Speichern</button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>