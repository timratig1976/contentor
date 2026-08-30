<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    media: Object,
    strategies: Array,
    filters: Object,
});

const filterUnit = ref(props.filters?.unit || '');
const filterStatus = ref(props.filters?.status || '');
const filterType = ref(props.filters?.type || '');

function applyFilters() {
    router.get('/medien', {
        unit: filterUnit.value || undefined,
        status: filterStatus.value || undefined,
        type: filterType.value || undefined,
    }, { preserveState: true });
}

const statusColors = {
    briefing: 'bg-gray-200',
    generiert: 'bg-blue-600',
    in_drive: 'bg-cyan-600',
    live: 'bg-green-600',
    verworfen: 'bg-red-600',
};

const typeIcons = {
    image: '🖼️',
    video: '🎬',
    graphic: '📊',
    carousel_slide: '📑',
    ad_creative: '📢',
};
</script>

<template>
    <AppLayout>
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Medien</h2>
            <p class="text-gray-400 mt-1">{{ media?.total || 0 }} Medien-Elemente</p>
        </div>

        <!-- Filters -->
        <div class="neu-card p-4  mb-6 flex flex-wrap gap-3">
            <select v-model="filterUnit" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Strategies</option>
                <option v-for="u in strategies" :key="u.key" :value="u.key">{{ u.name }}</option>
            </select>
            <select v-model="filterStatus" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Status</option>
                <option value="briefing">Briefing</option>
                <option value="generiert">Generiert</option>
                <option value="in_drive">In Drive</option>
                <option value="live">Live</option>
                <option value="verworfen">Verworfen</option>
            </select>
            <select v-model="filterType" @change="applyFilters" class="bg-neu  rounded-lg px-3 py-2 text-sm text-gray-800">
                <option value="">Alle Typen</option>
                <option value="image">Image</option>
                <option value="video">Video</option>
                <option value="graphic">Graphic</option>
                <option value="carousel_slide">Carousel Slide</option>
                <option value="ad_creative">Ad Creative</option>
            </select>
        </div>

        <!-- Gallery Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <div v-for="m in media?.data" :key="m.id" class="neu-card overflow-hidden hover:border-neu-border transition-colors">
                <!-- Preview Area -->
                <div class="aspect-video bg-neu flex items-center justify-center">
                    <img v-if="m.url" :src="m.url" :alt="m.type" class="w-full h-full object-cover" />
                    <span v-else class="text-4xl">{{ typeIcons[m.type] || '📄' }}</span>
                </div>

                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-mono text-gray-400">{{ m.id }}</span>
                        <span class="text-xs px-2 py-0.5 rounded-full text-gray-800" :class="statusColors[m.status]">{{ m.status }}</span>
                    </div>
                    <p class="text-sm text-gray-800 truncate">{{ m.content_item?.title || 'Kein Titel' }}</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="text-xs text-gray-400">{{ m.type }}</span>
                        <span v-if="m.format" class="text-xs text-gray-400">· {{ m.format }}</span>
                        <span class="text-xs text-gray-400">· {{ m.unit?.name }}</span>
                    </div>
                    <div v-if="m.briefing?.prompt_hint" class="mt-2 text-xs text-gray-400 line-clamp-2">
                        {{ m.briefing.prompt_hint }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="media?.links?.length > 3" class="mt-6 flex justify-center gap-1">
            <button
                v-for="link in media.links"
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
