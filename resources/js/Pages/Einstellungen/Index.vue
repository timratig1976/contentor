<script setup>
import { ref, reactive, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import UCard from '../../Components/UCard.vue';
import UInput from '../../Components/UInput.vue';
import UButton from '../../Components/UButton.vue';

const props = defineProps({ settings: Object });

const keys = reactive({
    edenai_key: props.settings?.llm_keys?.edenai_key || '',
    serperdev_key: props.settings?.llm_keys?.serperdev_key || '',
    content_api_url: props.settings?.llm_keys?.content_api_url || 'http://localhost:8000/api',
});

const saving = ref(false);
async function save() {
    saving.value = true;
    await router.post('/api/settings', { key: 'llm_keys', value: keys }, { preserveState: true });
    alert('API Keys gespeichert!');
    saving.value = false;
}

// ─── Modell-Kurator: Lade ALLE Modelle von EdenAI, dann wähle aus ───
const allModels = reactive({});
const curatedModels = reactive({});
const expandedProviders = reactive({});
const loadingModels = ref(false);
const savingCurated = ref(false);
const modelsLoaded = ref(false);

async function loadAllModels() {
    loadingModels.value = true;
    try {
        const res = await fetch('/api/edenai/models/all');
        const data = await res.json();
        if (data.models) {
            Object.assign(allModels, data.models);
            for (const [provider, models] of Object.entries(data.models)) {
                curatedModels[provider] = models.map(m => ({
                    value: m.id,
                    label: m.name,
                    context_length: m.context_length,
                    enabled: false
                }));
                expandedProviders[provider] = false;
            }
            // Load saved curated from DB
            const savedRes = await fetch('/api/edenai/models');
            const savedData = await savedRes.json();
            if (savedData.models) {
                for (const [provider, savedIds] of Object.entries(savedData.models)) {
                    if (curatedModels[provider]) {
                        curatedModels[provider].forEach(m => {
                            m.enabled = savedIds.includes(m.value);
                        });
                    }
                }
            }
            modelsLoaded.value = true;
        } else {
            alert('Keine Modelle geladen. Prüfe den EdenAI Key.');
        }
    } catch (e) {
        alert('Fehler: ' + e.message);
    } finally { loadingModels.value = false; }
}

async function saveCurated() {
    savingCurated.value = true;
    const enabled = {};
    for (const [provider, models] of Object.entries(curatedModels)) {
        enabled[provider] = models.filter(m => m.enabled).map(m => m.value);
    }
    await fetch('/api/edenai/models/curated', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify({ models: enabled }),
    });
    alert('Kuratierung gespeichert!');
    savingCurated.value = false;
}

function toggleAll(provider, enabled) {
    if (curatedModels[provider]) {
        curatedModels[provider].forEach(m => m.enabled = enabled);
    }
}
</script>

<template>
  <AppLayout>
    <div class="mb-6">
      <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Einstellungen</h1>
      <p class="text-sm text-gray-600 mt-1">API-Konfiguration</p>
    </div>

    <UCard title="🔑 API Keys" class="mb-6">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <UInput v-model="keys.edenai_key" label="EdenAI API Key" type="password" hint="Alle Modelle über einen Key" />
        <UInput v-model="keys.serperdev_key" label="SerperDev API Key" type="password" hint="Für Web-Recherche" />
        <UInput v-model="keys.content_api_url" label="Content API URL" class="md:col-span-2" hint="Laravel API-Endpunkt" />
      </div>
      <div class="mt-4">
        <UButton @click="save" :loading="saving" variant="primary">💾 Speichern</UButton>
      </div>
    </UCard>

    <UCard title="🤖 Modell-Kurator" class="mb-6">
      <p class="text-sm text-gray-600 mb-4">Lade alle verfügbaren Modelle von EdenAI und wähle aus, welche für die Agents verfügbar sind.</p>

      <UButton @click="loadAllModels" :loading="loadingModels" variant="primary" class="mb-4">
        {{ loadingModels ? 'Lade Modelle...' : '🔄 Alle Modelle laden' }}
      </UButton>

      <div v-if="modelsLoaded">
        <div v-for="(models, provider) in curatedModels" :key="provider" class="mb-3">
          <button @click="expandedProviders[provider] = !expandedProviders[provider]"
            class="w-full flex items-center justify-between p-3 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
            <div class="flex items-center gap-3">
              <span class="text-lg">{{ expandedProviders[provider] ? '▼' : '▶' }}</span>
              <div class="text-left">
                <h4 class="text-sm font-semibold text-gray-900 capitalize">{{ provider }}</h4>
                <p class="text-xs text-gray-500">{{ models.filter(m => m.enabled).length }} von {{ models.length }} aktiviert</p>
              </div>
            </div>
            <div class="flex gap-2" @click.stop>
              <button @click="toggleAll(provider, true)" class="text-xs text-green-600 hover:text-green-700 font-medium px-2 py-1">Alle</button>
              <button @click="toggleAll(provider, false)" class="text-xs text-gray-500 hover:text-gray-700 font-medium px-2 py-1">Keine</button>
            </div>
          </button>
          <div v-if="expandedProviders[provider]" class="mt-2 space-y-1">
            <label v-for="model in models" :key="model.value" class="flex items-center gap-3 text-sm p-2 rounded-lg border"
              :class="model.enabled ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200'">
              <input type="checkbox" :value="model.value" v-model="model.enabled" class="rounded border-gray-300 text-green-600 focus:ring-green-500 shrink-0" />
              <div class="flex-1 min-w-0">
                <span class="text-gray-900 font-medium block truncate">{{ model.label }}</span>
                <span v-if="model.context_length" class="text-xs text-gray-500">{{ model.context_length }} tokens</span>
              </div>
            </label>
          </div>
        </div>
        <UButton @click="saveCurated" :loading="savingCurated" variant="primary" class="mt-4">💾 Kuratierung speichern</UButton>
      </div>

      <p v-else class="text-sm text-gray-500 italic">Lade zuerst alle Modelle von EdenAI.</p>
    </UCard>
  </AppLayout>
</template>