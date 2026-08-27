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
            <a href="/angles" class="text-sm text-indigo-400 hover:text-indigo-300">← Zurück zu Angles</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Info -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-white">{{ angle.id }}</h2>
                            <p class="text-sm text-gray-400 mt-1">{{ angle.unit?.name }} · {{ angle.batch_key || 'Kein Batch' }}</p>
                        </div>
                        <span class="text-xs px-3 py-1 rounded-full bg-indigo-600/20 text-indigo-400">{{ angle.status }}</span>
                    </div>

                    <div class="prose prose-invert max-w-none">
                        <p class="text-lg text-white leading-relaxed">{{ angle.angle }}</p>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
                        <div>
                            <div class="text-xs text-gray-500 uppercase">ICP</div>
                            <div class="text-sm text-white mt-1">{{ angle.icp || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 uppercase">Pain Cluster</div>
                            <div class="text-sm text-white mt-1">{{ angle.pain_cluster || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 uppercase">Statement Typ</div>
                            <div class="text-sm text-white mt-1">{{ angle.statement_type || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 uppercase">Funnel</div>
                            <div class="text-sm text-white mt-1">{{ angle.funnel || '—' }}</div>
                        </div>
                    </div>

                    <div v-if="angle.source" class="mt-6 p-4 bg-gray-800 rounded-lg">
                        <div class="text-xs text-gray-500 uppercase mb-1">Quelle</div>
                        <div class="text-sm text-white">{{ angle.source.title }}</div>
                        <div class="text-xs text-gray-500 mt-1">{{ angle.source.type }} · {{ angle.source.visibility }}</div>
                    </div>
                </div>

                <!-- Linked Content Items -->
                <div class="bg-gray-900 rounded-xl border border-gray-800">
                    <div class="p-6 border-b border-gray-800">
                        <h3 class="text-lg font-semibold text-white">Zugeordnete Posts</h3>
                    </div>
                    <div class="divide-y divide-gray-800">
                        <div v-for="item in angle.content_items" :key="item.id" class="p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-white">{{ item.title || item.content?.substring(0, 80) }}</p>
                                    <p class="text-xs text-gray-500 mt-1">{{ item.format }} · {{ item.status }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <span v-for="m in item.media" :key="m.id" class="text-xs px-2 py-1 rounded bg-gray-800 text-gray-400">
                                        {{ m.type }}: {{ m.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div v-if="!angle.content_items?.length" class="p-8 text-center text-gray-500">
                            Noch keine Posts für diesen Angle
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ranking Sidebar -->
            <div class="space-y-6">
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Ranking</h3>

                    <div class="text-center mb-6">
                        <div class="text-5xl font-bold" :class="angle.ranking_score >= 10 ? 'text-green-400' : angle.ranking_score >= 7 ? 'text-yellow-400' : 'text-red-400'">
                            {{ angle.ranking_score ?? '—' }}
                        </div>
                        <div class="text-sm text-gray-400 mt-1">Score</div>
                        <div class="text-xs text-gray-500 mt-1">Rang {{ angle.ranking_rang ?? '—' }}</div>
                    </div>

                    <div v-for="(label, key) in criteriaLabels" :key="key" class="mb-4">
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-sm text-gray-300">{{ label }}</label>
                            <span class="text-sm font-bold text-white">{{ rankings[key] }}</span>
                        </div>
                        <input
                            type="range"
                            v-model.number="rankings[key]"
                            min="0"
                            max="3"
                            step="1"
                            class="w-full h-2 bg-gray-700 rounded-lg appearance-none cursor-pointer accent-indigo-500"
                            @change="updateRanking"
                        />
                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                            <span>0</span><span>1</span><span>2</span><span>3</span>
                        </div>
                    </div>

                    <div v-if="saving" class="text-xs text-indigo-400 mt-2">Speichern...</div>
                </div>

                <!-- Produzieren -->
                <div class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Produzieren</h3>
                    <div class="space-y-2">
                        <button class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm transition-colors">
                            LinkedIn Post
                        </button>
                        <button class="w-full px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg text-sm transition-colors">
                            Ad Copy
                        </button>
                        <button class="w-full px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg text-sm transition-colors">
                            Newsletter BK
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
