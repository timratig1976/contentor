<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    angle: Object,
});

const rankings = ref({
    r_zielgruppe: props.angle.r_zielgruppe || 0,
    r_viscale_fit: props.angle.r_viscale_fit || 0,
    r_schaerfe: props.angle.r_schaerfe || 0,
    r_timing: props.angle.r_timing || 0,
});

const saving = ref(false);

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
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Produzieren</h3>
                    <div class="space-y-2">
                        <button class="w-full px-4 py-2 bg-neu hover:bg-neu text-gray-800 rounded-lg text-sm transition-colors">
                            LinkedIn Post
                        </button>
                        <button class="w-full px-4 py-2 bg-neu hover:bg-gray-300 text-gray-800 rounded-lg text-sm transition-colors">
                            Ad Copy
                        </button>
                        <button class="w-full px-4 py-2 bg-neu hover:bg-gray-300 text-gray-800 rounded-lg text-sm transition-colors">
                            Newsletter BK
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
