<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    drafts: Object,
    units: Array,
    currentUnit: Object,
});

function switchUnit(unitKey) {
    router.get('/newsletter', { unit: unitKey }, { preserveState: true });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-white">Newsletter</h2>
                <p class="text-gray-400 mt-1">Draft-Liste · {{ currentUnit.name }}</p>
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

        <!-- Drafts List -->
        <div class="space-y-4">
            <div v-for="item in drafts?.data" :key="item.id" class="bg-gray-900 rounded-xl border border-gray-800 p-6">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="text-lg font-semibold text-white">{{ item.title }}</h3>
                        <p class="text-sm text-gray-400 mt-1">{{ item.format }} · {{ item.owner }}</p>
                    </div>
                    <span class="text-xs px-3 py-1 rounded-full"
                        :class="{
                            'bg-gray-700 text-gray-300': item.status === 'idee',
                            'bg-yellow-600/20 text-yellow-400': item.status === 'in_produktion',
                            'bg-purple-600/20 text-purple-400': item.status === 'review',
                            'bg-green-600/20 text-green-400': item.status === 'live',
                        }"
                    >{{ item.status }}</span>
                </div>
                <div class="bg-gray-800 rounded-lg p-4 mt-3">
                    <pre class="text-sm text-gray-300 whitespace-pre-wrap">{{ item.content }}</pre>
                </div>
                <div class="flex items-center justify-between mt-4">
                    <span class="text-xs text-gray-500">{{ new Date(item.created_at).toLocaleDateString('de-DE') }}</span>
                    <div v-if="item.angle" class="text-xs text-indigo-400">
                        Angle: {{ item.angle.angle?.substring(0, 60) }}...
                    </div>
                </div>
            </div>

            <div v-if="!drafts?.data?.length" class="bg-gray-900 rounded-xl border border-gray-800 p-12 text-center text-gray-500">
                Noch keine Newsletter-Drafts für {{ currentUnit.name }}
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="drafts?.links?.length > 3" class="mt-6 flex justify-center gap-1">
            <button
                v-for="link in drafts.links"
                :key="link.label"
                @click="link.url && router.get(link.url)"
                class="px-3 py-1 rounded text-sm"
                :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:bg-gray-800'"
                v-html="link.label"
                :disabled="!link.url"
            />
        </div>
    </AppLayout>
</template>
