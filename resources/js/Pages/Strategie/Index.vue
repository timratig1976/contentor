<script setup>
import { ref, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({ strategies: Array, currentStrategy: Object, contentKeys: Array });

const activeTab = ref('brand_voice');
const saving = ref(false);
const saved = ref(false);
const showNewStrategy = ref(false);
const newStrategyForm = reactive({ key: '', name: '' });

const forms = reactive({
    brand_voice: { personality: '', tone: 'direkt', never: [], must: [] },
    channel_rules: { channels: [] },
    icp_channel_mapping: { mappings: [] },
    media_logic: { rules: [] },
    editorial_rhythm: { cadence: 'weekly', slots: [] },
    content_strategy: { pillars: [], goals: '' },
    post_templates: { templates: [] },
    content_personas: { personas: [] },
});

props.contentKeys.forEach(s => {
    if (s.content) forms[s.key] = { ...forms[s.key], ...s.content };
});

const tabLabels = {
    brand_voice: 'Brand Voice',
    channel_rules: 'Kanal-Regeln',
    icp_channel_mapping: 'ICP → Kanal',
    media_logic: 'Medien-Logik',
    editorial_rhythm: 'Redaktions-Rhythmus',
    content_strategy: 'Content-Strategie',
    post_templates: 'Post-Templates',
    content_personas: 'Personen & Themen',
};

async function save() {
    saving.value = true; saved.value = false;
    try {
        await fetch('/api/strategy', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({ strategy: props.currentStrategy.key, key: activeTab.value, content: forms[activeTab.value] }),
        });
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
function removeItem(key, field, index) { forms[key][field].splice(index, 1); }
function addChannel() { forms.channel_rules.channels.push({ channel: 'linkedin', frequency: 'weekly', best_times: [], rules: [] }); }
function addMapping() { forms.icp_channel_mapping.mappings.push({ icp: '', channels: [], priority: 'medium' }); }
function addTemplate() { forms.post_templates.templates.push({ format: 'linkedin_post', name: '', structure: [], example: '' }); }
function addStrategyPersona() { forms.content_personas.personas.push({ name: '', role: '', core_statements: [], tonality: { style: 'direkt', do: [], dont: [] }, topic_clusters: [], channels: [], positioning: '' }); }
function addMediaRule() { forms.media_logic.rules.push({ format: 'image', style: '', aspect_ratio: '1:1', notes: '' }); }
function addEditorialSlot() { forms.editorial_rhythm.slots.push({ day: 'Monday', channel: 'linkedin', format: 'post', persona: '' }); }
function addPillar() { forms.content_strategy.pillars.push({ name: '', description: '', icp_focus: [] }); }
</script>

<template>
  <AppLayout>
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Content-Strategie</h1>
        <p class="text-sm text-gray-600 mt-1">Multi-Strategie-Verwaltung</p>
      </div>
      <button @click="showNewStrategy = true" class="neu-btn-primary px-4 py-2 text-sm">+ Neue Strategie</button>
    </div>

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

    <div v-if="activeTab === 'icp_channel_mapping'" class="space-y-5">
      <div class="flex justify-between items-center"><h3 class="text-sm font-semibold text-gray-900">ICP → Kanal Mapping</h3><button @click="addMapping" class="neu-btn-primary px-4 py-2 text-sm">+ Mapping</button></div>
      <div v-for="(m, i) in forms.icp_channel_mapping.mappings" :key="i" class="bg-white border border-gray-200 rounded-xl p-5 flex items-start gap-4">
        <div class="flex-1"><input v-model="m.icp" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 mb-2" placeholder="ICP (z.B. B2B-1)" /><div class="flex flex-wrap gap-2"><label v-for="ch in ['linkedin', 'newsletter', 'blog', 'meta_ads', 'linkedin_ads']" :key="ch" class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" :value="ch" v-model="m.channels" class="rounded border-gray-300 text-green-600 focus:ring-2 focus:ring-green-500/20" /> {{ ch }}</label></div></div>
        <select v-model="m.priority" class="bg-white border border-gray-300 rounded-lg px-2 py-2 text-sm text-gray-900"><option value="high">🔴 Hoch</option><option value="medium">🟡 Mittel</option><option value="low">🟢 Niedrig</option></select>
        <button @click="forms.icp_channel_mapping.mappings.splice(i, 1)" class="text-red-500 hover:text-red-700">✕</button>
      </div>
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
      <div class="flex justify-between items-center"><h3 class="text-sm font-semibold text-gray-900">Post-Templates & Regeln</h3><button @click="addTemplate" class="neu-btn-primary px-4 py-2 text-sm">+ Template</button></div>
      <div v-for="(tpl, i) in (forms.post_templates.templates || [])" :key="i" class="bg-white border border-gray-200 rounded-xl p-5 space-y-3">
        <div class="flex items-center justify-between"><div class="flex gap-2 items-center"><select v-model="tpl.format" class="bg-white border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-gray-900"><option value="linkedin_post">LinkedIn Post</option><option value="ad_copy">Ad Copy</option><option value="newsletter_acquisition">Newsletter</option><option value="landing_page_headlines">Landing Page</option></select><input v-model="tpl.name" class="bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Template-Name" /></div><button @click="forms.post_templates.templates.splice(i, 1)" class="text-red-500 hover:text-red-700">✕</button></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Struktur (ein Element pro Zeile)</label><textarea v-model="tpl.structure" rows="5" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 font-mono focus:outline-none focus:border-green-500 focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors focus:ring-2 focus:ring-2 focus:ring-green-500/20/20 transition-colors" placeholder="Hook (Zeile 1)\nMechanismus\nProof\nCTA"></textarea></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Beispiel</label><textarea v-model="tpl.example" rows="4" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Ein Beispiel-Post..."></textarea></div>
      </div>
    </div>

    <div v-if="activeTab === 'content_personas'" class="space-y-5">
      <div class="flex justify-between items-center"><h3 class="text-sm font-semibold text-gray-900">Content-Personas & Themen</h3><button @click="addStrategyPersona" class="neu-btn-primary px-4 py-2 text-sm">+ Persona</button></div>
      <div v-for="(p, i) in (forms.content_personas.personas || [])" :key="i" class="bg-white border border-gray-200 rounded-xl p-5 space-y-4">
        <div class="flex items-center justify-between"><h4 class="text-gray-900 font-medium">Persona {{ i + 1 }}</h4><button @click="forms.content_personas.personas.splice(i, 1)" class="text-red-500 hover:text-red-700 text-sm">Löschen</button></div>
        <div class="grid grid-cols-2 gap-4"><input v-model="p.name" class="bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Name" /><input v-model="p.role" class="bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Rolle (CEO, CMO)" /></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Kernaussagen</label><div class="space-y-1.5 mb-1"><div v-for="(stmt, si) in (p.core_statements || [])" :key="si" class="flex gap-2"><input v-model="p.core_statements[si]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Kernaussage" /><button @click="p.core_statements.splice(si, 1)" class="text-red-500">✕</button></div></div><button @click="p.core_statements = [...(p.core_statements || []), '']" class="text-sm text-green-600 font-medium">+ Kernaussage</button></div>
        <div class="grid grid-cols-2 gap-4"><div><label class="block text-sm text-gray-900 mb-1 font-medium">Tonalität</label><select v-model="p.tonality.style" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900"><option value="direkt">Direkt</option><option value="beratend">Beratend</option><option value="provokativ">Provokativ</option><option value="analytisch">Analytisch</option><option value="visionaer">Visionär</option></select></div><div><label class="block text-sm text-gray-900 mb-1 font-medium">Positionierung</label><input v-model="p.positioning" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="Thought Leader" /></div></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">✅ Do's</label><div class="space-y-1.5 mb-1"><div v-for="(d, di) in (p.tonality.do || [])" :key="di" class="flex gap-2"><input v-model="p.tonality.do[di]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" /><button @click="p.tonality.do.splice(di, 1)" class="text-red-500">✕</button></div></div><button @click="p.tonality.do = [...(p.tonality.do || []), '']" class="text-sm text-green-600 font-medium">+ Do</button></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">🚫 Don'ts</label><div class="space-y-1.5 mb-1"><div v-for="(d, di) in (p.tonality.dont || [])" :key="di" class="flex gap-2"><input v-model="p.tonality.dont[di]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" /><button @click="p.tonality.dont.splice(di, 1)" class="text-red-500">✕</button></div></div><button @click="p.tonality.dont = [...(p.tonality.dont || []), '']" class="text-sm text-green-600 font-medium">+ Don't</button></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Themen-Cluster</label><div class="space-y-1.5 mb-1"><div v-for="(topic, ti) in (p.topic_clusters || [])" :key="ti" class="flex gap-2"><input v-model="p.topic_clusters[ti]" class="flex-1 bg-white border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900" placeholder="CRM-Datenqualität" /><button @click="p.topic_clusters.splice(ti, 1)" class="text-red-500">✕</button></div></div><button @click="p.topic_clusters = [...(p.topic_clusters || []), '']" class="text-sm text-green-600 font-medium">+ Thema</button></div>
        <div><label class="block text-sm text-gray-900 mb-1 font-medium">Kanäle</label><div class="flex flex-wrap gap-2"><label v-for="ch in ['linkedin', 'newsletter', 'blog', 'meta_ads', 'linkedin_ads']" :key="ch" class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" :value="ch" v-model="p.channels" class="rounded border-gray-300 text-green-600 focus:ring-2 focus:ring-green-500/20" /> {{ ch }}</label></div></div>
      </div>
    </div>

    <div class="mt-6 flex items-center gap-3 pt-4 border-t border-gray-200">
      <button @click="save" :disabled="saving" class="neu-btn-primary px-4 py-2 text-sm">{{ saving ? 'Speichert...' : '💾 Strategie speichern' }}</button>
      <span v-if="saved" class="text-sm text-green-600">✓ Gespeichert!</span>
    </div>
  </AppLayout>
</template>