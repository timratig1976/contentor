<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    drafts: Object,
    strategies: Array,
    currentStrategy: Object,
});

function switchStrategy(strategyKey) {
    router.get('/newsletter', { unit: strategyKey }, { preserveState: true });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Newsletter</h2>
                <p class="text-gray-400 mt-1">Draft-Liste · {{ currentStrategy.name }}</p>
            </div>
            <div class="flex gap-2">
                <button
                    v-for="u in strategies"
                    :key="u.key"
                    @click="switchStrategy(u.key)"
                    class="px-4 py-2 rounded-lg text-sm transition-colors"
                    :class="u.key === currentStrategy.key
                        ? 'bg-neu text-gray-800'
                        : 'bg-neu text-gray-400 hover:text-gray-800'"
                >
                    {{ u.name }}
                </button>
            </div>
        </div>

        <!-- Drafts List -->
        <div class="space-y-4">
            <div v-for="item in drafts?.data" :key="item.id" class="neu-card p-6">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">{{ item.title }}</h3>
                        <p class="text-sm text-gray-400 mt-1">{{ item.format }} · {{ item.owner }}</p>
                    </div>
                    <span class="text-xs px-3 py-1 rounded-full"
                        :class="{
                            'bg-neu text-gray-400': item.status === 'idee',
                            'bg-yellow-50 text-yellow-700': item.status === 'in_produktion',
                            'bg-purple-50 text-purple-600': item.status === 'review',
                            'bg-green-50 text-green-700': item.status === 'live',
                        }"
                    >{{ item.status }}</span>
                </div>
                <div class="neu-card-sm p-4 mt-3">
                    <pre class="text-sm text-gray-400 whitespace-pre-wrap">{{ item.content }}</pre>
                </div>
                <div class="flex items-center justify-between mt-4">
                    <span class="text-xs text-gray-400">{{ new Date(item.created_at).toLocaleDateString('de-DE') }}</span>
                    <div v-if="item.angle" class="text-xs text-gray-400">
                        Angle: {{ item.angle.angle?.substring(0, 60) }}...
                    </div>
                </div>
            </div>

            <div v-if="!drafts?.data?.length" class="neu-card p-12 text-center text-gray-400">
                Noch keine Newsletter-Drafts für {{ currentStrategy.name }}
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="drafts?.links?.length > 3" class="mt-6 flex justify-center gap-1">
            <button
                v-for="link in drafts.links"
                :key="link.label"
                @click="link.url && router.get(link.url)"
                class="px-3 py-1 rounded text-sm"
                :class="link.active ? 'bg-neu text-gray-800' : 'text-gray-400 hover:bg-gray-300'"
                v-html="link.label"
                :disabled="!link.url"
            />
        </div>
    </AppLayout>
</template>
