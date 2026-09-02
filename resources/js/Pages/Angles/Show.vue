<script setup>
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    angle: Object,
    templates: Array,
});

const rankings = ref({
    r_zielgruppe: props.angle.r_zielgruppe || 0,
    r_viscale_fit: props.angle.r_viscale_fit || 0,
    r_schaerfe: props.angle.r_schaerfe || 0,
    r_timing: props.angle.r_timing || 0,
});

const saving = ref(false);
const generating = ref(false);
const generateResult = ref(null);
const selectedTemplates = ref([]);

function updateRanking() {
    saving.value = true;
    router.patch(`/api/angles/${props.angle.id}`, rankings.value, {
        preserveState: true,
        onFinish: () => saving.value = false,
    });
}

const criteriaLabels = {
    r_zielgruppe: 'Zielgruppe',
    r_viscale_fit: 'viscale Fit',
    r_schaerfe: 'Schärfe',
    r_timing: 'Timing',
};

function toggleTemplate(tpl) {
    const idx = selectedTemplates.value.findIndex(t => t.name === tpl.name);
    if (idx >= 0) selectedTemplates.value.splice(idx, 1);
    else selectedTemplates.value.push(tpl);
}

function isSelected(name) {
    return selectedTemplates.value.some(t => t.name === name);
}

async function generateVariants() {
    if (!selectedTemplates.value.length) return;
    generating.value = true;
    generateResult.value = null;

    const patterns = selectedTemplates.value.map(t =>
        t.name.toLowerCase().includes('contrarian') ? 'contrarian' :
        t.name.toLowerCase().includes('data') ? 'data_drop' :
        t.name.toLowerCase().includes('mistake') ? 'mistake_post' :
        t.name.toLowerCase().includes('story') ? 'story' :
        t.name.toLowerCase().includes('listicle') ? 'listicle' :
        t.name.toLowerCase().includes('question') ? 'question' :
        'contrarian'
    );

    // Pro Template ein Format — wir nutzen das Format des ersten ausgewählten
    const format = selectedTemplates.value[0]?.format || 'linkedin_post';

    try {
        const res = await fetch('/api/content/produzieren', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify({
                angle_id: props.angle.id,
                strategy: props.angle.strategy?.key,
                format: format,
                variants_count: selectedTemplates.value.length,
                variant_patterns: patterns,
            }),
        });
        generateResult.value = await res.json();
    } catch (e) {
        generateResult.value = { error: e.message };
    }
    generating.value = false;
}
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <a href="/angles" class="text-sm text-gray-400 hover:text-gray-400">← Zurück zu Angles</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Info -->
            <div class="lg:col-span-2 space-y-6">
                <div class="neu-card p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-800">{{ angle.id }}</h2>
                            <p class="text-sm text-gray-400 mt-1">{{ angle.unit?.name }} · {{ angle.batch_key || 'Kein Batch' }}</p>
                        </div>
                        <span class="text-xs px-3 py-1 rounded-full bg-neu/20 text-gray-400">{{ angle.status }}</span>
                    </div>

                    <div class="prose prose-invert max-w-none">
                        <p class="text-lg text-gray-800 leading-relaxed">{{ angle.angle }}</p>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
                        <div>
                            <div class="text-xs text-gray-400 uppercase">ICP</div>
                            <div class="text-sm text-gray-800 mt-1">{{ angle.icp || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Pain Cluster</div>
                            <div class="text-sm text-gray-800 mt-1">{{ angle.pain_cluster || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Statement Typ</div>
                            <div class="text-sm text-gray-800 mt-1">{{ angle.statement_type || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400 uppercase">Funnel</div>
                            <div class="text-sm text-gray-800 mt-1">{{ angle.funnel || '—' }}</div>
                        </div>
                    </div>

                    <div v-if="angle.source" class="mt-6 p-4 neu-card-sm">
                        <div class="text-xs text-gray-400 uppercase mb-1">Quelle</div>
                        <div class="text-sm text-gray-800">{{ angle.source.title }}</div>
                        <div class="text-xs text-gray-400 mt-1">{{ angle.source.type }} · {{ angle.source.visibility }}</div>
                    </div>
                </div>

                <!-- Linked Content Items -->
                <div class="neu-card">
                    <div class="p-6 border-b border-neu-border">
                        <h3 class="text-lg font-semibold text-gray-800">Zugeordnete Posts</h3>
                    </div>
                    <div class="divide-y divide-gray-800">
                        <div v-for="item in angle.content_items" :key="item.id" class="p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-800">{{ item.title || item.content?.substring(0, 80) }}</p>
                                    <p class="text-xs text-gray-400 mt-1">{{ item.format }} · {{ item.status }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <span v-for="m in item.media" :key="m.id" class="text-xs px-2 py-1 rounded bg-neu text-gray-400">
                                        {{ m.type }}: {{ m.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div v-if="!angle.content_items?.length" class="p-8 text-center text-gray-400">
                            Noch keine Posts für diesen Angle
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ranking Sidebar -->
            <div class="space-y-6">
                <div class="neu-card p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Ranking</h3>

                    <div class="text-center mb-6">
                        <div class="text-5xl font-bold" :class="angle.ranking_score >= 10 ? 'text-green-600' : angle.ranking_score >= 7 ? 'text-yellow-600' : 'text-red-600'">
                            {{ angle.ranking_score ?? '—' }}
                        </div>
                        <div class="text-sm text-gray-400 mt-1">Score</div>
                        <div class="text-xs text-gray-400 mt-1">Rang {{ angle.ranking_rang ?? '—' }}</div>
                    </div>

                    <div v-for="(label, key) in criteriaLabels" :key="key" class="mb-4">
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-sm text-gray-400">{{ label }}</label>
                            <span class="text-sm font-bold text-gray-800">{{ rankings[key] }}</span>
                        </div>
                        <input
                            type="range"
                            v-model.number="rankings[key]"
                            min="0"
                            max="3"
                            step="1"
                            class="w-full h-2 neu-card-sm appearance-none cursor-pointer accent-indigo-500"
                            @change="updateRanking"
                        />
                        <div class="flex justify-between text-xs text-gray-400 mt-1">
                            <span>0</span><span>1</span><span>2</span><span>3</span>
                        </div>
                    </div>

                    <div v-if="saving" class="text-xs text-gray-400 mt-2">Speichern...</div>
                </div>

                <!-- Score-Begründung -->
                <div v-if="angle.score_reasoning" class="neu-card p-6">
                    <h3 class="text-xs font-semibold text-gray-400 uppercase mb-2">Scoring-Begründung</h3>
                    <p class="text-sm text-gray-800 leading-relaxed">{{ angle.score_reasoning }}</p>
                </div>

                <!-- Duplikat-Hinweis -->
                <div v-if="angle.duplicate_of_id" class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                    <p class="text-xs font-semibold text-yellow-700 mb-1">⚠ Mögliches Duplikat</p>
                    <p class="text-sm text-yellow-700">
                        Ähnlichkeit {{ Math.round((angle.similarity_score || 0) * 100) }}% zu
                        <a :href="`/angles/${angle.duplicate_of_id}`" class="underline">{{ angle.duplicate_of_id }}</a>
                    </p>
                </div>

                <!-- Produzieren -->
                <div class="neu-card p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Produzieren</h3>

                    <!-- Template-Auswahl -->
                    <div v-if="templates?.length" class="space-y-2 mb-4">
                        <p class="text-xs text-gray-500 font-medium">Template wählen:</p>
                        <div v-for="tpl in templates" :key="tpl.name"
                            class="flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer transition-colors text-sm"
                            :class="isSelected(tpl.name) ? 'bg-green-50 border-green-300 text-green-800' : 'bg-white border-gray-200 text-gray-700 hover:border-gray-300'"
                            @click="toggleTemplate(tpl)">
                            <span class="text-xs" :class="isSelected(tpl.name) ? 'text-green-600' : 'text-gray-400'">{{ isSelected(tpl.name) ? '✓' : '○' }}</span>
                            <span class="font-medium">{{ tpl.name }}</span>
                            <span class="text-xs text-gray-400 ml-auto">{{ tpl.format }}</span>
                        </div>
                    </div>
                    <div v-else class="text-xs text-gray-400 italic mb-4">
                        Keine Templates in der Strategie ausgewählt.
                        <a href="/strategie" class="text-green-600 underline">Strategie bearbeiten</a>
                    </div>

                    <button @click="generateVariants" :disabled="generating || !selectedTemplates.length"
                        class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition-colors disabled:opacity-50">
                        {{ generating ? 'Generiere…' : '🚀 ' + selectedTemplates.length + ' Varianten generieren' }}
                    </button>

                    <!-- Ergebnis -->
                    <div v-if="generateResult" class="mt-4 space-y-3">
                        <div v-if="generateResult.error" class="text-sm text-red-600">{{ generateResult.error }}</div>
                        <div v-for="item in (generateResult.items || [])" :key="item.id"
                            class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-medium text-gray-600">{{ item.variant_pattern || 'Standard' }}</span>
                                <span class="text-xs text-gray-400">{{ item.id }}</span>
                            </div>
                            <p class="text-sm text-gray-900 whitespace-pre-line line-clamp-4">{{ item.content }}</p>
                            <div class="mt-2 flex gap-2">
                                <a :href="`/output`" class="text-xs text-green-600 underline">Im Output ansehen</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
