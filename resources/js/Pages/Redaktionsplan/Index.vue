<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    kanban: Object,
    strategies: Array,
    currentStrategy: Object,
    columns: Array,
});

const columnLabels = {
    idee: '💡 Idee',
    angle: 'Angle',
    in_produktion: '🔨 In Produktion',
    review: '👀 Review',
    geplant: '📅 Geplant',
    live: '🟢 Live',
};

const columnColors = {
    idee: 'border-neu-border',
    angle: 'border-blue-500',
    in_produktion: 'border-yellow-500',
    review: 'border-purple-500',
    geplant: 'border-cyan-500',
    live: 'border-green-500',
};

function scoreColor(score) {
    if (!score) return '⚪';
    if (score >= 10) return '🟢';
    if (score >= 7) return '🟡';
    return '🔴';
}

function switchStrategy(strategyKey) {
    router.get('/redaktionsplan', { unit: strategyKey }, { preserveState: true });
}

function moveCard(itemId, newStatus) {
    fetch(`/api/content/${itemId}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ status: newStatus }),
    });
}

let draggedItem = null;

function onDragStart(item) {
    draggedItem = item;
}

function onDrop(columnStatus) {
    if (draggedItem && draggedItem.status !== columnStatus) {
        moveCard(draggedItem.id, columnStatus);
    }
    draggedItem = null;
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Redaktionsplan</h2>
                <p class="text-gray-400 mt-1">Kanban-Board · {{ currentStrategy.name }}</p>
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

        <!-- Kanban Board -->
        <div class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="col in columns"
                :key="col"
                class="flex-shrink-0 w-72"
                @dragover.prevent
                @drop="onDrop(col)"
            >
                <div class="neu-card border-t-2 " :class="columnColors[col]">
                    <div class="p-4 border-b border-neu-border flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-800">{{ columnLabels[col] }}</h3>
                        <span class="text-xs text-gray-400 bg-neu rounded-full px-2 py-0.5">{{ kanban[col]?.length || 0 }}</span>
                    </div>
                    <div class="p-2 space-y-2 min-h-[200px]">
                        <div
                            v-for="item in kanban[col]"
                            :key="item.id"
                            class="neu-card-sm p-3 cursor-grab hover:bg-gray-750 transition-colors "
                            draggable="true"
                            @dragstart="onDragStart(item)"
                        >
                            <p class="text-sm text-gray-800 line-clamp-2 mb-2">{{ item.title || item.content?.substring(0, 80) }}</p>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span v-if="item.icp" class="text-xs px-1.5 py-0.5 rounded bg-neu/20 text-gray-400">{{ item.icp }}</span>
                                    <span v-if="item.format" class="text-xs text-gray-400">{{ item.format }}</span>
                                </div>
                                <span v-if="item.angle?.ranking_score" class="text-sm">{{ scoreColor(item.angle.ranking_score) }}</span>
                            </div>
                            <div v-if="item.media?.length" class="flex gap-1 mt-2">
                                <span v-for="m in item.media" :key="m.id" class="text-xs text-gray-400" :title="`${m.type}: ${m.status}`">
                                    {{ m.type === 'image' ? '🖼️' : m.type === 'video' ? '🎬' : '📊' }}
                                </span>
                            </div>
                            <div v-if="item.live_date" class="text-xs text-gray-400 mt-1">📅 {{ item.live_date }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
