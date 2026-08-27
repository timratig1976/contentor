<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    units: Array,
    currentUnit: Object,
    strategyKeys: Array,
});

const editingKey = ref(null);
const editContent = ref('');

function startEdit(strategyKey) {
    const strategy = props.strategyKeys.find(s => s.key === strategyKey);
    editingKey.value = strategyKey;
    editContent.value = JSON.stringify(strategy?.content || {}, null, 2);
}

function saveStrategy() {
    try {
        const content = JSON.parse(editContent.value);
        router.post('/api/strategy', {
            unit: props.currentUnit.key,
            key: editingKey.value,
            content: content,
        }, {
            preserveState: true,
            onSuccess: () => {
                editingKey.value = null;
            },
        });
    } catch (e) {
        alert('Ungültiges JSON: ' + e.message);
    }
}

function switchUnit(unitKey) {
    router.get('/strategie', { unit: unitKey }, { preserveState: true });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-white">Strategie</h2>
                <p class="text-gray-400 mt-1">8-Key Editor · {{ currentUnit.name }}</p>
            </div>
            <div class="flex gap-2">
                <button
                    v-for="u in units"
                    :key="u.key"
                    @click="switchUnit(u.key)"
                    class="px-4 py-2 rounded-lg text-sm transition-colors"
                    :class="u.key === currentUnit.key
                        ? 'bg-indigo-600 text-white'
                        : 'bg-gray-800 text-gray-400 hover:text-white'"
                >
                    {{ u.name }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
                v-for="sk in strategyKeys"
                :key="sk.key"
                class="bg-gray-900 rounded-xl border border-gray-800 p-6"
            >
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-white">{{ sk.label }}</h3>
                    <div class="flex items-center gap-2">
                        <span v-if="sk.version" class="text-xs text-gray-500">v{{ sk.version }}</span>
                        <button
                            @click="startEdit(sk.key)"
                            class="text-xs px-2 py-1 rounded bg-gray-800 text-indigo-400 hover:bg-gray-700"
                        >
                            {{ sk.content ? 'Bearbeiten' : 'Erstellen' }}
                        </button>
                    </div>
                </div>

                <div v-if="editingKey === sk.key">
                    <textarea
                        v-model="editContent"
                        rows="8"
                        class="w-full bg-gray-800 border border-gray-700 rounded-lg p-3 text-sm text-white font-mono"
                        placeholder='{"key": "value"}'
                    ></textarea>
                    <div class="flex gap-2 mt-2">
                        <button @click="saveStrategy" class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs hover:bg-indigo-500">
                            Speichern
                        </button>
                        <button @click="editingKey = null" class="px-3 py-1.5 bg-gray-700 text-gray-300 rounded-lg text-xs hover:bg-gray-600">
                            Abbrechen
                        </button>
                    </div>
                </div>

                <div v-else>
                    <pre v-if="sk.content" class="text-xs text-gray-400 bg-gray-800 rounded-lg p-3 overflow-auto max-h-40">{{ JSON.stringify(sk.content, null, 2) }}</pre>
                    <p v-else class="text-sm text-gray-600 italic">Noch nicht definiert</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
